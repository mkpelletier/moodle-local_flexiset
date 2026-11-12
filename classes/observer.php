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
 * Event observers.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset;

/**
 * Keep attempt totals and group flags in step with quiz events.
 */
class observer {
    /**
     * The attempt has just been graded. Sumgrades is still the question total.
     *
     * @param \mod_quiz\event\attempt_graded $event
     */
    public static function attempt_graded(\mod_quiz\event\attempt_graded $event): void {
        if (rescaler::is_running()) {
            return;
        }
        rescaler::apply_attempt((int) $event->objectid);
    }

    /**
     * A regrade writes the question total, and a bulk regrade then overwrites
     * every attempt with SQL. Apply now and again after the request.
     *
     * @param \mod_quiz\event\attempt_regraded $event
     */
    public static function attempt_regraded(\mod_quiz\event\attempt_regraded $event): void {
        if (!rescaler::is_running()) {
            rescaler::apply_attempt((int) $event->objectid);
        }
        $quizid = (int) ($event->other['quizid'] ?? 0);
        if ($quizid) {
            rescaler::queue_quiz($quizid);
        }
    }

    /**
     * Manual marking saves the question total before this event.
     *
     * @param \mod_quiz\event\question_manually_graded $event
     */
    public static function question_manually_graded(\mod_quiz\event\question_manually_graded $event): void {
        $attemptid = (int) ($event->other['attemptid'] ?? 0);
        if ($attemptid && !rescaler::is_running()) {
            rescaler::apply_attempt($attemptid);
        }
        $quizid = (int) ($event->other['quizid'] ?? 0);
        if ($quizid) {
            rescaler::queue_quiz($quizid);
        }
    }

    /**
     * The manual-grading notification cron. The total should already be right.
     *
     * @param \mod_quiz\event\attempt_manual_grading_completed $event
     */
    public static function attempt_manual_grading_completed(
        \mod_quiz\event\attempt_manual_grading_completed $event
    ): void {
        if (rescaler::is_running()) {
            return;
        }
        rescaler::apply_attempt((int) $event->objectid);
    }

    /**
     * A section or slot change that does not recompute quiz.sumgrades.
     *
     * @param \core\event\base $event
     */
    public static function structure_event(\core\event\base $event): void {
        $quizid = (int) ($event->other['quizid'] ?? 0);
        if (!$quizid) {
            return;
        }
        groups::revalidate($quizid);
        rescaler::queue_quiz($quizid);
    }

    /**
     * Drop FlexiSet rows when the quiz activity is deleted.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        if (($event->other['modulename'] ?? '') !== 'quiz') {
            return;
        }
        api::delete_quiz((int) ($event->other['instanceid'] ?? 0));
    }

    /**
     * Drop the stored figures for a deleted attempt.
     *
     * @param \mod_quiz\event\attempt_deleted $event
     */
    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event): void {
        global $DB;
        $DB->delete_records('local_flexiset_result', ['attemptid' => $event->objectid]);
    }
}
