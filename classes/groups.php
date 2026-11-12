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
 * Question groups bound to quiz sections.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset;

use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Load, check, and save section groups.
 */
class groups {
    /** Count the highest-scoring answers. */
    public const MODE_BEST = 'best';

    /** Count the first answers in quiz slot order. */
    public const MODE_FIRST = 'first';

    /** Count every answered question, or the best K when K is set. */
    public const MODE_ALL = 'all';

    /** @var array<int, array|null> */
    private static array $cache = [];

    /**
     * A saved answer is anything other than not-started, invalid, or gave up.
     *
     * mangaveup is a subclass of gaveup, so identity with $gaveup is not enough.
     *
     * @param \question_state $state
     * @return bool
     */
    public static function is_answered(\question_state $state): bool {
        if ($state->is_gave_up()) {
            return false;
        }
        return $state != \question_state::$todo
            && $state != \question_state::$invalid
            && $state != \question_state::$notstarted
            && $state != \question_state::$unprocessed;
    }

    /**
     * Live view of the quiz switch and its groups.
     *
     * The stored invalid flag is not trusted. A deleted section is invalid here
     * even before revalidate has run. Extra grade items are reported separately
     * and do not set that flag.
     *
     * @param int $quizid
     * @param quiz_settings|null $quizobj Reuse the caller's object when already loaded.
     * @return array|null Null when this quiz has no FlexiSet row.
     */
    public static function resolve(int $quizid, ?quiz_settings $quizobj = null): ?array {
        if (isset(self::$cache[$quizid]) && $quizobj === null) {
            return self::$cache[$quizid];
        }
        $settings = api::get($quizid);
        if (!$settings) {
            self::$cache[$quizid] = null;
            return null;
        }

        $quizobj = $quizobj ?? quiz_settings::create($quizid);
        $structure = $quizobj->get_structure();
        $sections = $structure->get_sections();
        $records = self::records($quizid);

        $groups = [];
        $slotowner = [];
        foreach ($records as $record) {
            $group = self::describe_group($record, $structure, $sections);
            foreach ($group['slots'] as $slot) {
                if (isset($slotowner[$slot])) {
                    $group['invalid'] = true;
                    $group['reason'] = 'overlap';
                    $groups[$slotowner[$slot]]['invalid'] = true;
                    $groups[$slotowner[$slot]]['reason'] = 'overlap';
                }
                $slotowner[$slot] = $group['id'];
            }
            $groups[$group['id']] = $group;
        }

        $anyinvalid = false;
        foreach ($groups as $group) {
            $anyinvalid = $anyinvalid || $group['invalid'];
        }

        $resolved = [
            'enabled' => (bool) $settings->enabled,
            'shortfall' => $settings->shortfall === api::SHORTFALL_CORE ? api::SHORTFALL_CORE : api::SHORTFALL_ZERO,
            'invalid' => $anyinvalid,
            'gradeitems' => api::has_grade_items($quizid),
            'groups' => $groups,
        ];
        self::$cache[$quizid] = $resolved;
        return $resolved;
    }

    /**
     * Write the invalid flags from the live structure.
     *
     * @param int $quizid
     */
    public static function revalidate(int $quizid): void {
        global $DB;
        if (!api::get($quizid)) {
            return;
        }
        // A new quiz saves this switch before course modinfo lists the module.
        // With no groups there is nothing to check, and loading the quiz would fail.
        if (!self::records($quizid)) {
            $DB->set_field('local_flexiset_quiz', 'invalid', 0, ['quizid' => $quizid]);
            api::clear_cache();
            return;
        }
        self::clear_cache();
        $resolved = self::resolve($quizid);
        if (!$resolved) {
            return;
        }
        foreach ($resolved['groups'] as $group) {
            $DB->set_field('local_flexiset_group', 'invalid', $group['invalid'] ? 1 : 0, ['id' => $group['id']]);
        }
        $DB->set_field('local_flexiset_quiz', 'invalid', $resolved['invalid'] ? 1 : 0, ['quizid' => $quizid]);
        // The flag write must not serve a stale settings row.
        api::clear_cache();
        self::$cache[$quizid] = $resolved;
    }

    /**
     * Forget the live view after a write.
     */
    public static function clear_cache(): void {
        self::$cache = [];
    }

    /**
     * Section menu for the group form.
     *
     * @param int $quizid
     * @param int|null $includeid Section to keep even when another group uses it.
     * @return array<int, string>
     */
    public static function section_choices(int $quizid, ?int $includeid = null): array {
        global $DB;
        $quizobj = quiz_settings::create($quizid);
        $structure = $quizobj->get_structure();
        $used = $DB->get_fieldset_select('local_flexiset_group', 'sectionid', 'quizid = ?', [$quizid]);
        $used = array_map('intval', $used);
        $choices = [];
        foreach ($structure->get_sections() as $section) {
            $sectionid = (int) $section->id;
            if (in_array($sectionid, $used, true) && $sectionid !== (int) $includeid) {
                continue;
            }
            $count = count(self::real_slots($structure, $sectionid));
            $choices[$sectionid] = get_string('sectionchoice', 'local_flexiset', (object) [
                'name' => self::section_name($section, $quizobj->get_context()),
                'count' => $count,
            ]);
        }
        return $choices;
    }

    /**
     * Check a group before it is stored.
     *
     * @param int $quizid
     * @param int $sectionid
     * @param int $minanswer
     * @param int|null $maxcount
     * @param string $mode
     * @param int|null $ignoreid Group id to ignore when checking the unique section.
     * @return array<string, string> Field name => error. Empty when the group is valid.
     */
    public static function validate(
        int $quizid,
        int $sectionid,
        int $minanswer,
        ?int $maxcount,
        string $mode,
        ?int $ignoreid = null
    ): array {
        global $DB;
        $errors = [];
        if (!in_array($mode, [self::MODE_BEST, self::MODE_FIRST, self::MODE_ALL], true)) {
            $errors['mode'] = get_string('errormode', 'local_flexiset');
        }

        $quizobj = quiz_settings::create($quizid);
        $structure = $quizobj->get_structure();
        $sections = $structure->get_sections();
        if (!$sectionid || !isset($sections[$sectionid])) {
            $errors['sectionid'] = get_string('errorsection', 'local_flexiset');
            return $errors;
        }

        $params = ['quizid' => $quizid, 'sectionid' => $sectionid];
        $select = 'quizid = :quizid AND sectionid = :sectionid';
        if ($ignoreid) {
            $select .= ' AND id <> :ignoreid';
            $params['ignoreid'] = $ignoreid;
        }
        if ($DB->record_exists_select('local_flexiset_group', $select, $params)) {
            $errors['sectionid'] = get_string('errorsectionused', 'local_flexiset');
        }

        $count = count(self::real_slots($structure, $sectionid));
        if ($minanswer < 1 || $minanswer > $count) {
            $errors['minanswer'] = get_string('errornrange', 'local_flexiset', $count);
        }
        if ($maxcount !== null && ($maxcount < $minanswer || $maxcount > $count)) {
            $errors['maxcount'] = get_string('errorkrange', 'local_flexiset', $count);
        }
        return $errors;
    }

    /**
     * Insert or update a group, then recheck the quiz and queue a regrade.
     *
     * @param int $quizid
     * @param int|null $id
     * @param int $sectionid
     * @param string|null $name
     * @param string $mode
     * @param int $minanswer
     * @param int|null $maxcount
     * @return int Group id.
     */
    public static function save(
        int $quizid,
        ?int $id,
        int $sectionid,
        ?string $name,
        string $mode,
        int $minanswer,
        ?int $maxcount
    ): int {
        global $DB;
        $errors = self::validate($quizid, $sectionid, $minanswer, $maxcount, $mode, $id);
        if ($errors) {
            throw new \invalid_parameter_exception(reset($errors));
        }
        api::ensure($quizid);
        $name = trim((string) $name);
        $record = (object) [
            'quizid' => $quizid,
            'sectionid' => $sectionid,
            'name' => $name === '' ? null : $name,
            'mode' => $mode,
            'minanswer' => $minanswer,
            'maxcount' => $maxcount,
            'timemodified' => time(),
        ];
        if ($id) {
            $current = $DB->get_record('local_flexiset_group', ['id' => $id, 'quizid' => $quizid], '*', MUST_EXIST);
            $record->id = $current->id;
            $record->invalid = 0;
            $record->sortorder = $current->sortorder;
            $DB->update_record('local_flexiset_group', $record);
            $saved = (int) $current->id;
        } else {
            $maxorder = $DB->get_field_sql(
                'SELECT MAX(sortorder) FROM {local_flexiset_group} WHERE quizid = ?',
                [$quizid]
            );
            $record->invalid = 0;
            $record->sortorder = ((int) $maxorder) + 1;
            $saved = (int) $DB->insert_record('local_flexiset_group', $record);
        }
        self::clear_cache();
        self::revalidate($quizid);
        rescaler::queue_quiz($quizid);
        return $saved;
    }

    /**
     * Delete a group and queue a regrade.
     *
     * @param int $id
     * @param int $quizid
     */
    public static function delete(int $id, int $quizid): void {
        global $DB;
        $DB->delete_records('local_flexiset_group', ['id' => $id, 'quizid' => $quizid]);
        self::clear_cache();
        self::revalidate($quizid);
        rescaler::queue_quiz($quizid);
    }

    /**
     * Counts for the summary-page banner. Empty when FlexiSet will not rescale.
     *
     * @param quiz_attempt $attempt
     * @return array<int, array>
     */
    public static function banner_items(quiz_attempt $attempt): array {
        $resolved = self::resolve($attempt->get_quizid(), $attempt->get_quizobj());
        if (!$resolved || !$resolved['enabled'] || $resolved['invalid'] || $resolved['gradeitems']) {
            return [];
        }
        $attemptslots = array_flip($attempt->get_slots());
        $items = [];
        foreach ($resolved['groups'] as $group) {
            if ($group['invalid']) {
                continue;
            }
            $answered = 0;
            foreach ($group['slots'] as $slot) {
                if (!isset($attemptslots[$slot])) {
                    continue;
                }
                if (self::is_answered($attempt->get_question_state($slot))) {
                    $answered++;
                }
            }
            $items[] = [
                'name' => $group['displayname'],
                'n' => $group['minanswer'],
                'm' => count($group['slots']),
                'k' => $group['maxcount'],
                'mode' => $group['mode'],
                'answered' => $answered,
            ];
        }
        return $items;
    }

    /**
     * Stored groups in display order.
     *
     * @param int $quizid
     * @return \stdClass[]
     */
    public static function records(int $quizid): array {
        global $DB;
        return $DB->get_records('local_flexiset_group', ['quizid' => $quizid], 'sortorder, id');
    }

    /**
     * Real question slot numbers in one section, in slot order.
     *
     * @param \mod_quiz\structure $structure
     * @param int $sectionid
     * @return int[]
     */
    public static function real_slots(\mod_quiz\structure $structure, int $sectionid): array {
        $slots = [];
        foreach ($structure->get_slots_in_section($sectionid) as $slot) {
            if ($structure->is_real_question($slot)) {
                $slots[] = (int) $slot;
            }
        }
        return $slots;
    }

    /**
     * Heading shown when a group has no name of its own.
     *
     * @param \stdClass $section
     * @param \context $context
     * @return string
     */
    public static function section_name(\stdClass $section, \context $context): string {
        $heading = trim((string) ($section->heading ?? ''));
        if ($heading === '') {
            return get_string('defaultsection', 'local_flexiset');
        }
        return format_string($heading, true, ['context' => $context]);
    }

    /**
     * Build the live description of one stored group.
     *
     * @param \stdClass $record
     * @param \mod_quiz\structure $structure
     * @param array $sections
     * @return array
     */
    private static function describe_group(\stdClass $record, \mod_quiz\structure $structure, array $sections): array {
        $sectionid = (int) $record->sectionid;
        $invalid = false;
        $reason = null;
        $slots = [];
        $heading = '';
        if (!isset($sections[$sectionid])) {
            $invalid = true;
            $reason = 'missingsection';
        } else {
            $slots = self::real_slots($structure, $sectionid);
            $heading = self::section_name($sections[$sectionid], $structure->get_context());
            $count = count($slots);
            $min = (int) $record->minanswer;
            $max = $record->maxcount === null ? null : (int) $record->maxcount;
            $mode = (string) $record->mode;
            if (!in_array($mode, [self::MODE_BEST, self::MODE_FIRST, self::MODE_ALL], true)) {
                $invalid = true;
                $reason = 'mode';
            } else if ($min < 1 || $min > $count || ($max !== null && ($max < $min || $max > $count))) {
                $invalid = true;
                $reason = 'bounds';
            }
        }

        $name = trim((string) ($record->name ?? ''));
        return [
            'id' => (int) $record->id,
            'sectionid' => $sectionid,
            'name' => $name === '' ? null : $name,
            'heading' => $heading,
            'displayname' => $name !== '' ? $name : ($heading !== '' ? $heading : get_string('defaultsection', 'local_flexiset')),
            'mode' => (string) $record->mode,
            'minanswer' => (int) $record->minanswer,
            'maxcount' => $record->maxcount === null ? null : (int) $record->maxcount,
            'slots' => $slots,
            'invalid' => $invalid,
            'reason' => $reason,
            'sortorder' => (int) $record->sortorder,
        ];
    }
}
