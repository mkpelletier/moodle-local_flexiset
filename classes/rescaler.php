<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Rewrites quiz_attempts.sumgrades from the FlexiSet selection.
 *
 * Counted marks over the counted maximum, scaled onto quiz.sumgrades, so
 * Moodle's usual rescale yields that fraction of quiz.grade. Per-question
 * marks are left as the question engine stored them.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset;

use mod_quiz\quiz_attempt;

/**
 * Calculate and write FlexiSet attempt totals.
 */
class rescaler {
    /** Grades closer than this are treated as unchanged. */
    private const EPSILON = 0.00001;

    /** @var bool True while a gradebook push from this class is in progress. */
    private static bool $running = false;

    /** @var array<int, bool> Quiz ids waiting for the end-of-request rewrite. */
    private static array $pending = [];

    /** @var bool */
    private static bool $shutdownregistered = false;

    /**
     * Whether a gradebook push from FlexiSet is already running.
     *
     * @return bool
     */
    public static function is_running(): bool {
        return self::$running;
    }

    /**
     * Plan for one attempt. Nothing is written.
     *
     * action is skip, needsgrading, restore, or rescale.
     * sumgrades is the value that should stand in quiz_attempts.sumgrades.
     *
     * @param quiz_attempt $attempt
     * @return array
     */
    public static function plan(quiz_attempt $attempt): array {
        $resolved = groups::resolve($attempt->get_quizid(), $attempt->get_quizobj());
        $core = self::core_total($attempt);
        if ($resolved === null) {
            return self::outcome('skip', null, null, $core, null);
        }
        if ($core === null) {
            // A question still needs a mark. Leave the attempt ungraded.
            return self::outcome('needsgrading', null, null, null, null);
        }
        if (!$resolved['enabled'] || $resolved['invalid'] || $resolved['gradeitems'] || !$resolved['groups']) {
            return self::outcome('restore', null, null, $core, $core);
        }

        $attemptslots = [];
        foreach ($attempt->get_slots() as $slot) {
            $attemptslots[(int) $slot] = true;
        }
        $grouped = [];
        $parts = [];
        $short = false;
        foreach ($resolved['groups'] as $group) {
            if ($group['invalid']) {
                return self::outcome('restore', null, null, $core, $core);
            }
            foreach ($group['slots'] as $slot) {
                if (!isset($attemptslots[$slot])) {
                    return self::outcome('restore', null, null, $core, $core);
                }
                $grouped[$slot] = true;
            }
            $part = self::select_group($attempt, $group);
            if ($part['count'] < $group['minanswer']) {
                $short = true;
            }
            $parts[] = $part;
        }
        if ($short && $resolved['shortfall'] === api::SHORTFALL_CORE) {
            return self::outcome('restore', null, null, $core, $core);
        }

        $raw = 0.0;
        $appmax = 0.0;
        foreach ($attempt->get_slots() as $slot) {
            $slot = (int) $slot;
            if (!$attempt->is_real_question($slot) || isset($grouped[$slot])) {
                continue;
            }
            $qa = $attempt->get_question_attempt($slot);
            $mark = $qa->get_mark();
            $raw += $mark === null ? 0.0 : (float) $mark;
            $appmax += (float) $qa->get_max_mark();
        }
        foreach ($parts as $part) {
            $raw += $part['raw'];
            $appmax += $part['max'];
            if ($part['missing'] > 0) {
                $filler = $part['unansweredmax'] > 0 ? $part['unansweredmax'] : $part['largestmax'];
                $appmax += $part['missing'] * $filler;
            }
        }

        $quiz = $attempt->get_quiz();
        if ($appmax < self::EPSILON) {
            $sumgrades = 0.0;
        } else {
            $sumgrades = $raw / $appmax * (float) $quiz->sumgrades;
        }
        return self::outcome('rescale', $raw, $appmax, $core, $sumgrades);
    }

    /**
     * One summary cell for the review page, or null when FlexiSet is not the grade.
     *
     * @param quiz_attempt $attempt
     * @return array|null label and value, plain text.
     */
    public static function review_line(quiz_attempt $attempt): ?array {
        global $CFG;
        if ($attempt->get_state() !== quiz_attempt::FINISHED) {
            return null;
        }
        $plan = self::plan($attempt);
        if ($plan['action'] !== 'rescale' && $plan['action'] !== 'needsgrading') {
            return null;
        }
        $label = get_string('reviewlabel', 'local_flexiset');
        if ($plan['action'] === 'needsgrading') {
            return [
                'label' => $label,
                'value' => get_string('notyetgraded', 'quiz'),
            ];
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $quiz = $attempt->get_quiz();
        $percent = $plan['appmax'] > self::EPSILON ? $plan['raw'] / $plan['appmax'] * 100 : 0;
        return [
            'label' => $label,
            'value' => get_string('reviewvalue', 'local_flexiset', (object) [
                'raw' => quiz_format_grade($quiz, $plan['raw']),
                'max' => quiz_format_grade($quiz, $plan['appmax']),
                'percent' => quiz_format_grade($quiz, $percent),
            ]),
        ];
    }

    /**
     * Rewrite one finished attempt when FlexiSet applies.
     *
     * A second call that finds the same sumgrades does not clear a gradebook
     * override. That keeps a late penalty applied to this value.
     *
     * @param int $attemptid
     */
    public static function apply_attempt(int $attemptid): void {
        global $DB;
        if (self::$running || !$attemptid) {
            return;
        }
        $record = $DB->get_record('quiz_attempts', ['id' => $attemptid]);
        if (!$record || $record->preview || $record->state !== quiz_attempt::FINISHED) {
            return;
        }
        if (!api::get((int) $record->quiz)) {
            return;
        }

        $attempt = quiz_attempt::create($attemptid);
        $plan = self::plan($attempt);
        if ($plan['action'] === 'skip') {
            return;
        }
        self::store_result($attemptid, $plan);
        if (self::same_grade($plan['sumgrades'], $record->sumgrades)) {
            return;
        }

        $DB->set_field('quiz_attempts', 'sumgrades', $plan['sumgrades'], ['id' => $attemptid]);
        $DB->set_field('quiz_attempts', 'timemodified', time(), ['id' => $attemptid]);
        self::clear_override((int) $record->quiz, (int) $record->userid, $attempt->get_courseid());

        self::$running = true;
        try {
            $attempt->get_quizobj()->get_grade_calculator()->recompute_final_grade((int) $record->userid);
        } finally {
            self::$running = false;
        }
    }

    /**
     * Rewrite every finished non-preview attempt in a quiz.
     *
     * @param int $quizid
     */
    public static function apply_quiz(int $quizid): void {
        global $DB;
        if (!$quizid || !api::get($quizid) || !$DB->record_exists('quiz', ['id' => $quizid])) {
            return;
        }
        $ids = $DB->get_fieldset_select(
            'quiz_attempts',
            'id',
            'quiz = :quiz AND state = :state AND preview = 0',
            ['quiz' => $quizid, 'state' => quiz_attempt::FINISHED]
        );
        foreach ($ids as $id) {
            self::apply_attempt((int) $id);
        }
    }

    /**
     * Run again after this request, and once from cron.
     *
     * Bulk regrade and max-mark edits overwrite sumgrades with SQL after the
     * per-attempt events. The shutdown pass runs after that SQL. The adhoc
     * task repeats the write if the request dies first.
     *
     * @param int $quizid
     */
    public static function queue_quiz(int $quizid): void {
        if (!$quizid || !api::get($quizid)) {
            return;
        }
        self::$pending[$quizid] = true;
        if (!self::$shutdownregistered) {
            self::$shutdownregistered = true;
            \core_shutdown_manager::register_function([self::class, 'flush_pending']);
        }
        $task = new \local_flexiset\task\reapply_quiz();
        $task->set_custom_data(['quizid' => $quizid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Rewrite quizzes queued during this request.
     */
    public static function flush_pending(): void {
        $ids = array_keys(self::$pending);
        self::$pending = [];
        foreach ($ids as $quizid) {
            self::apply_quiz((int) $quizid);
        }
    }

    /**
     * Question-usage total, or null while a marked question still needs grading.
     *
     * @param quiz_attempt $attempt
     * @return float|null
     */
    private static function core_total(quiz_attempt $attempt): ?float {
        $total = 0.0;
        foreach ($attempt->get_slots() as $slot) {
            $qa = $attempt->get_question_attempt($slot);
            if ($qa->get_max_mark() > 0 && $qa->get_state() == \question_state::$needsgrading) {
                return null;
            }
            $mark = $qa->get_mark();
            $total += $mark === null ? 0.0 : (float) $mark;
        }
        return $total;
    }

    /**
     * Choose the slots that count in one group.
     *
     * Blank K is K = N for best and first. For all, blank K counts every
     * answered slot. Ties in best and capped-all break toward the lower slot.
     *
     * @param quiz_attempt $attempt
     * @param array $group
     * @return array
     */
    private static function select_group(quiz_attempt $attempt, array $group): array {
        $answered = [];
        $largestmax = 0.0;
        $unansweredmax = 0.0;
        foreach ($group['slots'] as $slot) {
            $qa = $attempt->get_question_attempt($slot);
            $max = (float) $qa->get_max_mark();
            $largestmax = max($largestmax, $max);
            if (!groups::is_answered($qa->get_state())) {
                $unansweredmax = max($unansweredmax, $max);
                continue;
            }
            $fraction = $qa->get_fraction();
            $mark = $qa->get_mark();
            $answered[] = [
                'slot' => (int) $slot,
                'fraction' => $fraction === null ? 0.0 : (float) $fraction,
                'mark' => $mark === null ? 0.0 : (float) $mark,
                'max' => $max,
            ];
        }

        $mode = $group['mode'];
        $min = (int) $group['minanswer'];
        $maxcount = $group['maxcount'];
        if ($mode === groups::MODE_ALL && $maxcount === null) {
            $cap = count($answered);
        } else {
            $cap = $maxcount ?? $min;
        }

        if ($mode !== groups::MODE_FIRST) {
            usort($answered, function (array $a, array $b): int {
                $byfraction = $b['fraction'] <=> $a['fraction'];
                if ($byfraction !== 0) {
                    return $byfraction;
                }
                return $a['slot'] <=> $b['slot'];
            });
        }
        $selected = array_slice($answered, 0, $cap);
        $selectedslots = [];
        $raw = 0.0;
        $max = 0.0;
        foreach ($selected as $slot) {
            $selectedslots[$slot['slot']] = true;
            $raw += $slot['mark'];
            $max += $slot['max'];
        }
        if ($unansweredmax <= 0) {
            foreach ($group['slots'] as $slot) {
                if (isset($selectedslots[(int) $slot])) {
                    continue;
                }
                $qa = $attempt->get_question_attempt($slot);
                $unansweredmax = max($unansweredmax, (float) $qa->get_max_mark());
            }
        }

        return [
            'raw' => $raw,
            'max' => $max,
            'count' => count($selected),
            'missing' => max(0, $min - count($selected)),
            'unansweredmax' => $unansweredmax,
            'largestmax' => $largestmax,
        ];
    }

    /**
     * @param string $action
     * @param float|null $raw
     * @param float|null $appmax
     * @param float|null $core
     * @param float|null $sumgrades
     * @return array
     */
    private static function outcome(string $action, ?float $raw, ?float $appmax, ?float $core, ?float $sumgrades): array {
        return [
            'action' => $action,
            'raw' => $raw,
            'appmax' => $appmax,
            'coretotal' => $core,
            'sumgrades' => $sumgrades,
        ];
    }

    /**
     * @param int $attemptid
     * @param array $plan
     */
    private static function store_result(int $attemptid, array $plan): void {
        global $DB;
        $record = (object) [
            'attemptid' => $attemptid,
            'raw' => $plan['raw'],
            'appmax' => $plan['appmax'],
            'coretotal' => $plan['coretotal'],
            'sumgrades' => $plan['sumgrades'],
            'timemodified' => time(),
        ];
        $existing = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('local_flexiset_result', $record);
        } else {
            $DB->insert_record('local_flexiset_result', $record);
        }
    }

    /**
     * @param float|null $desired
     * @param mixed $current
     * @return bool
     */
    private static function same_grade(?float $desired, $current): bool {
        if ($desired === null && $current === null) {
            return true;
        }
        if ($desired === null || $current === null || $current === '') {
            return false;
        }
        return abs($desired - (float) $current) < self::EPSILON;
    }

    /**
     * Clear a locked final grade so the next push is not stuck on the old total.
     *
     * Only an overridden grade is cleared, and only when sumgrades changed.
     * The following push can then lock a late penalty against the new raw grade.
     * A second apply sees the same sumgrades and leaves that lock alone.
     *
     * @param int $quizid
     * @param int $userid
     * @param int $courseid
     */
    private static function clear_override(int $quizid, int $userid, int $courseid): void {
        global $DB;
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
            'courseid' => $courseid,
        ]);
        if (!$item) {
            return;
        }
        $grade = $DB->get_record('grade_grades', [
            'itemid' => $item->id,
            'userid' => $userid,
        ]);
        if (!$grade || empty($grade->overridden)) {
            return;
        }
        $grade->overridden = 0;
        $grade->finalgrade = null;
        $DB->update_record('grade_grades', $grade);
    }
}
