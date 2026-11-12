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

namespace local_flexiset;

use local_flexiset\api;
use local_flexiset\groups;

/**
 * Backup and restore of FlexiSet settings and section groups.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_flexiset_plugin
 * @covers     \restore_local_flexiset_plugin
 */
final class backup_test extends \advanced_testcase {
    use \mod_quiz\tests\question_helper_test_trait;

    /**
     * A restored quiz gets the switch and a remapped section. Result rows stay behind.
     */
    public function test_restore_remaps_section_and_skips_results(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100.0,
            'questionsperpage' => 1,
        ]);
        $quiz->cmid = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST)->id;

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($course->id)->id,
        ]);
        foreach ([1, 2] as $page) {
            $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz, $page, 1);
        }
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();
        $sectionid = $quizobj->get_structure()->add_section_heading(2, 'Second');
        // The first question is the only one on page 1, so the new section starts at slot 2.
        groups::save($quiz->id, null, $sectionid, 'Pick one', groups::MODE_BEST, 1, 1);
        api::save_quiz_settings($quiz->id, true, api::SHORTFALL_CORE);

        $DB->insert_record('local_flexiset_result', (object) [
            'attemptid' => 999999,
            'raw' => 1,
            'appmax' => 2,
            'coretotal' => 1,
            'sumgrades' => 1,
            'timemodified' => time(),
        ]);

        $admin = get_admin();
        $backupid = $this->backup_quiz($quiz, $admin);
        $this->restore_quiz($backupid, $course, $admin);

        $newquizid = (int) $DB->get_field_sql(
            'SELECT id FROM {quiz} WHERE course = ? AND id <> ?',
            [$course->id, $quiz->id]
        );
        $this->assertNotEmpty($newquizid);

        $settings = $DB->get_record('local_flexiset_quiz', ['quizid' => $newquizid], '*', MUST_EXIST);
        $this->assertEquals(1, (int) $settings->enabled);
        $this->assertEquals(api::SHORTFALL_CORE, $settings->shortfall);
        $this->assertEquals(0, (int) $settings->invalid);

        $group = $DB->get_record('local_flexiset_group', ['quizid' => $newquizid], '*', MUST_EXIST);
        $newsections = array_map('intval', $DB->get_fieldset_select('quiz_sections', 'id', 'quizid = ?', [$newquizid]));
        $this->assertContains((int) $group->sectionid, $newsections);
        $this->assertNotEquals($sectionid, (int) $group->sectionid);
        $this->assertEquals('Pick one', $group->name);
        $this->assertEquals(groups::MODE_BEST, $group->mode);
        $this->assertEquals(1, (int) $group->minanswer);
        $this->assertEquals(1, (int) $group->maxcount);
        $this->assertEquals(0, (int) $group->invalid);

        $results = $DB->get_records('local_flexiset_result');
        $this->assertCount(1, $results);
        $only = reset($results);
        $this->assertEquals(999999, (int) $only->attemptid);

        $resolved = groups::resolve($newquizid);
        $this->assertFalse($resolved['invalid']);
        $this->assertCount(1, $resolved['groups']);
    }
}
