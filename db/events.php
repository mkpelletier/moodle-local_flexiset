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
 * Priority is above quizaccess_duedate (9999) so sumgrades is the FlexiSet
 * value before a late penalty reads it.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\local_flexiset\observer::attempt_graded',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_regraded',
        'callback' => '\local_flexiset\observer::attempt_regraded',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\question_manually_graded',
        'callback' => '\local_flexiset\observer::question_manually_graded',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_manual_grading_completed',
        'callback' => '\local_flexiset\observer::attempt_manual_grading_completed',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\section_break_created',
        'callback' => '\local_flexiset\observer::structure_event',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\section_break_deleted',
        'callback' => '\local_flexiset\observer::structure_event',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\slot_moved',
        'callback' => '\local_flexiset\observer::structure_event',
        'priority' => 10000,
    ],
    [
        'eventname' => '\mod_quiz\event\slot_deleted',
        'callback' => '\local_flexiset\observer::structure_event',
        'priority' => 10000,
    ],
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\local_flexiset\observer::course_module_deleted',
    ],
    [
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\local_flexiset\observer::attempt_deleted',
    ],
];
