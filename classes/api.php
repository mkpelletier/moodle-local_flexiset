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
 * Stored quiz settings.
 *
 * The access rule edits these fields. This plugin owns the rows.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset;

/**
 * Read and write the per-quiz FlexiSet switch.
 */
class api {
    /** Missing answers score 0, and the denominator still includes N slots. */
    public const SHORTFALL_ZERO = 'zero';

    /** A short group leaves Moodle's question total in place for the attempt. */
    public const SHORTFALL_CORE = 'core';

    /** @var array<int, \stdClass|null> */
    private static array $cache = [];

    /**
     * Quiz settings, or null when FlexiSet has never been saved for this quiz.
     *
     * @param int $quizid
     * @return \stdClass|null
     */
    public static function get(int $quizid): ?\stdClass {
        global $DB;
        if (!$quizid) {
            return null;
        }
        if (!array_key_exists($quizid, self::$cache)) {
            $row = $DB->get_record('local_flexiset_quiz', ['quizid' => $quizid]);
            self::$cache[$quizid] = $row ?: null;
        }
        return self::$cache[$quizid];
    }

    /**
     * Insert a disabled row so a group can be saved before the switch is turned on.
     *
     * @param int $quizid
     */
    public static function ensure(int $quizid): void {
        global $DB;
        if (self::get($quizid)) {
            return;
        }
        $DB->insert_record('local_flexiset_quiz', (object) [
            'quizid' => $quizid,
            'enabled' => 0,
            'shortfall' => self::SHORTFALL_ZERO,
            'invalid' => 0,
            'timemodified' => time(),
        ]);
        self::clear_cache();
    }

    /**
     * Store the switch and the shortfall policy, then recheck groups and grades.
     *
     * @param int $quizid
     * @param bool $enabled
     * @param string $shortfall zero|core
     */
    public static function save_quiz_settings(int $quizid, bool $enabled, string $shortfall): void {
        global $DB;
        if ($shortfall !== self::SHORTFALL_ZERO && $shortfall !== self::SHORTFALL_CORE) {
            $shortfall = self::SHORTFALL_ZERO;
        }
        $existing = self::get($quizid);
        $record = (object) [
            'quizid' => $quizid,
            'enabled' => $enabled ? 1 : 0,
            'shortfall' => $shortfall,
            'timemodified' => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $record->invalid = $existing->invalid;
            $DB->update_record('local_flexiset_quiz', $record);
        } else {
            $record->invalid = 0;
            $DB->insert_record('local_flexiset_quiz', $record);
        }
        self::clear_cache();
        groups::revalidate($quizid);
        rescaler::queue_quiz($quizid);
    }

    /**
     * Remove every FlexiSet row for a quiz, including attempt results.
     *
     * @param int $quizid
     */
    public static function delete_quiz(int $quizid): void {
        global $DB;
        if (!$quizid) {
            return;
        }
        $attemptids = $DB->get_fieldset_select('quiz_attempts', 'id', 'quiz = ?', [$quizid]);
        if ($attemptids) {
            $DB->delete_records_list('local_flexiset_result', 'attemptid', $attemptids);
        }
        $DB->delete_records('local_flexiset_group', ['quizid' => $quizid]);
        $DB->delete_records('local_flexiset_quiz', ['quizid' => $quizid]);
        self::clear_cache();
    }

    /**
     * Whether the quiz defines extra grade items. Those totals use raw question marks.
     *
     * @param int $quizid
     * @return bool
     */
    public static function has_grade_items(int $quizid): bool {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('quiz_grade_items')) {
            return false;
        }
        return $DB->record_exists('quiz_grade_items', ['quizid' => $quizid]);
    }

    /**
     * Drop cached rows after a write.
     */
    public static function clear_cache(): void {
        self::$cache = [];
        groups::clear_cache();
    }
}
