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
 * Language strings.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['actions'] = 'Actions';
$string['addgroup'] = 'Add group';
$string['defaultsection'] = 'Default section';
$string['deleteconfirm'] = 'Delete the FlexiSet group for {$a}? Grades already recorded will be recalculated.';
$string['deleted'] = 'Group deleted.';
$string['deletegroup'] = 'Delete group';
$string['editgroup'] = 'Edit group';
$string['editquizsettings'] = 'Edit in quiz settings';
$string['errorkrange'] = 'The number that count must be empty, or from the minimum up to {$a} (the questions in the section).';
$string['errormode'] = 'Choose which answers count.';
$string['errornrange'] = 'The minimum must be from 1 to {$a}, the number of questions in the section.';
$string['errorsection'] = 'Choose a section.';
$string['errorsectionused'] = 'That section already has a group.';
$string['groupname'] = 'Name';
$string['groupname_help'] = 'Shown to students instead of the section heading. Leave this blank to use the heading.';
$string['groups'] = 'Question groups';
$string['invalid'] = 'Needs attention';
$string['invalid_bounds'] = 'The minimum or the counted answers no longer fit this section.';
$string['invalid_missingsection'] = 'The section was deleted. Grades use the normal quiz total until this group is repaired or deleted.';
$string['invalid_mode'] = 'The selection mode is not recognised.';
$string['invalid_overlap'] = 'This section shares questions with another group.';
$string['k'] = 'Counted (K)';
$string['maxcount'] = 'Answers that count (K)';
$string['maxcount_help'] = 'How many answers are included in the grade. Leave this blank to use the minimum. For "All answers", blank means every answered question counts.';
$string['minanswer'] = 'Minimum answers (N)';
$string['minanswer_help'] = 'How many questions in this section a student must answer.';
$string['mode'] = 'Which answers count';
$string['mode_all'] = 'All answers';
$string['mode_best'] = 'Best answers';
$string['mode_first'] = 'First answers';
$string['n'] = 'Minimum (N)';
$string['nequalsm'] = 'If the minimum equals the number of questions in the section, every question in the section is required.';
$string['nogroups'] = 'No question groups yet.';
$string['nosections'] = 'Every section already has a group.';
$string['pluginname'] = 'FlexiSet';
$string['privacy:metadata:result'] = 'The latest FlexiSet grade figures for a quiz attempt.';
$string['privacy:metadata:result:appmax'] = 'The maximum of the questions FlexiSet counted.';
$string['privacy:metadata:result:attemptid'] = 'The quiz attempt these figures belong to.';
$string['privacy:metadata:result:coretotal'] = 'The question total before FlexiSet rewrote the attempt grade.';
$string['privacy:metadata:result:raw'] = 'The marks FlexiSet counted.';
$string['privacy:metadata:result:sumgrades'] = 'The attempt total written for the gradebook.';
$string['privacy:metadata:result:timemodified'] = 'When these figures were last calculated.';
$string['questions'] = 'Questions';
$string['reviewlabel'] = 'FlexiSet grade';
$string['reviewvalue'] = '{$a->raw} / {$a->max} ({$a->percent}%)';
$string['saved'] = 'Group saved.';
$string['section'] = 'Section';
$string['sectionchoice'] = '{$a->name} ({$a->count} questions)';
$string['settingsreadonly'] = 'Use FlexiSet: {$a->enabled}. When a group is short: {$a->shortfall}.';
$string['shortfall_core'] = 'Keep the normal quiz total';
$string['shortfall_zero'] = 'Score missing answers as zero';
$string['status'] = 'Status';
$string['taskreapply'] = 'Reapply FlexiSet grades for a quiz';
$string['taskrepair'] = 'Repair FlexiSet grades';
$string['valid'] = 'OK';
