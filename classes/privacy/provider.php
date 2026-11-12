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
 * Privacy provider.
 *
 * Question groups and the quiz switch are not personal data. The result
 * row is the grade calculation for one attempt, and it leaves with the user.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Export and delete FlexiSet result rows with the quiz attempt's user.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe stored personal data.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_flexiset_result', [
            'attemptid' => 'privacy:metadata:result:attemptid',
            'raw' => 'privacy:metadata:result:raw',
            'appmax' => 'privacy:metadata:result:appmax',
            'coretotal' => 'privacy:metadata:result:coretotal',
            'sumgrades' => 'privacy:metadata:result:sumgrades',
            'timemodified' => 'privacy:metadata:result:timemodified',
        ], 'privacy:metadata:result');
        return $collection;
    }

    /**
     * Quiz module contexts that hold a result for this user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz_attempts} qa ON qa.quiz = cm.instance
                  JOIN {local_flexiset_result} r ON r.attemptid = qa.id
                 WHERE qa.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users who have a result row in this quiz.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT qa.userid
                  FROM {quiz_attempts} qa
                  JOIN {course_modules} cm ON cm.instance = qa.quiz AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {local_flexiset_result} r ON r.attemptid = qa.id";
        $userlist->add_from_sql('userid', $sql, [
            'cmid' => $context->instanceid,
            'modname' => 'quiz',
        ]);
    }

    /**
     * Export result rows for the user's attempts in the approved quizzes.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT r.attemptid, r.raw, r.appmax, r.coretotal, r.sumgrades, r.timemodified
                   FROM {local_flexiset_result} r
                   JOIN {quiz_attempts} qa ON qa.id = r.attemptid
                   JOIN {course_modules} cm ON cm.instance = qa.quiz AND cm.id = :cmid
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  WHERE qa.userid = :userid",
                [
                    'cmid' => $context->instanceid,
                    'modname' => 'quiz',
                    'userid' => $userid,
                ]
            );
            foreach ($rows as $row) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'local_flexiset'), $row->attemptid],
                    $row
                );
            }
        }
    }

    /**
     * Delete every result in this quiz.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        if (!$cm) {
            return;
        }
        $attemptids = $DB->get_fieldset_select('quiz_attempts', 'id', 'quiz = ?', [$cm->instance]);
        if ($attemptids) {
            $DB->delete_records_list('local_flexiset_result', 'attemptid', $attemptids);
        }
    }

    /**
     * Delete this user's results in the approved quizzes.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_MODULE) {
                continue;
            }
            $ids = $DB->get_fieldset_sql(
                "SELECT r.attemptid
                   FROM {local_flexiset_result} r
                   JOIN {quiz_attempts} qa ON qa.id = r.attemptid
                   JOIN {course_modules} cm ON cm.instance = qa.quiz AND cm.id = :cmid
                   JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  WHERE qa.userid = :userid",
                [
                    'cmid' => $context->instanceid,
                    'modname' => 'quiz',
                    'userid' => $userid,
                ]
            );
            if ($ids) {
                $DB->delete_records_list('local_flexiset_result', 'attemptid', $ids);
            }
        }
    }

    /**
     * Delete results for the listed users in this quiz.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['cmid'] = $context->instanceid;
        $params['modname'] = 'quiz';
        $ids = $DB->get_fieldset_sql(
            "SELECT r.attemptid
               FROM {local_flexiset_result} r
               JOIN {quiz_attempts} qa ON qa.id = r.attemptid
               JOIN {course_modules} cm ON cm.instance = qa.quiz AND cm.id = :cmid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE qa.userid $insql",
            $params
        );
        if ($ids) {
            $DB->delete_records_list('local_flexiset_result', 'attemptid', $ids);
        }
    }
}
