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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/flexiset_quiz_trait.php');

use local_flexiset\api;
use local_flexiset\groups;
use local_flexiset\rescaler;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * FlexiSet grade formula, fallbacks, and the gradebook write.
 *
 * Run on Moodle 5.3. Moodle 5.1 and 5.2 are in the supported range and are not executed here.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_flexiset\rescaler
 * @covers     \local_flexiset\groups
 */
final class rescaler_test extends \advanced_testcase {
    use flexiset_quiz_trait;

    /**
     * Gave up, including the manual subclass, is not an answer.
     */
    public function test_gaveup_is_not_an_answer(): void {
        $this->assertFalse(groups::is_answered(\question_state::$gaveup));
        $this->assertFalse(groups::is_answered(\question_state::$mangaveup));
        $this->assertFalse(groups::is_answered(\question_state::$todo));
        $this->assertFalse(groups::is_answered(\question_state::$invalid));
        $this->assertTrue(groups::is_answered(\question_state::$gradedright));
        $this->assertTrue(groups::is_answered(\question_state::$needsgrading));
        $this->assertTrue(groups::is_answered(\question_state::$mangrright));
    }

    /**
     * Fifteen required questions and the best 2 of 5 essays.
     *
     * Twelve true/false correct is 24. Essays of 7 and 8 make raw 39 of an
     * applicable maximum 50. That is 78% of the quiz, stored as sumgrades 62.4
     * because the quiz total of question marks is 80.
     */
    public function test_canonical_best_two_essays_score_78(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_spec_quiz();
        $this->assertEqualsWithDelta(80.0, (float) $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]), 0.001);

        $this->enable_group($quiz->id, $quiz->sectionid, 2, 2, groups::MODE_BEST);
        $responses = $this->truefalse_responses(12, 15);
        $responses[16] = 'First essay';
        $responses[17] = 'Second essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [16 => 7, 17 => 8]);

        $this->assertEqualsWithDelta(62.4, $this->attempt_sumgrades($attemptid), 0.001);
        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(39.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $result->appmax, 0.001);
        $this->assertEqualsWithDelta(39.0, (float) $result->coretotal, 0.001);
        $this->assertEqualsWithDelta(62.4, (float) $result->sumgrades, 0.001);
        $this->assertEqualsWithDelta(78.0, $this->raw_grade($quiz->id, $this->student->id), 0.01);

        $line = rescaler::review_line(quiz_attempt::create($attemptid));
        $quizrow = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals(get_string('reviewlabel', 'local_flexiset'), $line['label']);
        $this->assertEquals(get_string('reviewvalue', 'local_flexiset', (object) [
            'raw' => quiz_format_grade($quizrow, 39),
            'max' => quiz_format_grade($quizrow, 50),
            'percent' => quiz_format_grade($quizrow, 78),
        ]), $line['value']);

        // A second apply sees the same sumgrades and leaves a gradebook lock alone.
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'itemnumber' => 0,
            'courseid' => $this->course->id,
        ]);
        $this->assertNotEmpty($item);
        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $this->student->id], '*', MUST_EXIST);
        $grade->overridden = 1;
        $grade->finalgrade = 70;
        $DB->update_record('grade_grades', $grade);

        rescaler::apply_attempt($attemptid);

        $grade = $DB->get_record('grade_grades', ['itemid' => $item->id, 'userid' => $this->student->id], '*', MUST_EXIST);
        $this->assertEquals(1, (int) $grade->overridden);
        $this->assertEqualsWithDelta(70.0, (float) $grade->finalgrade, 0.001);
        $this->assertEqualsWithDelta(62.4, $this->attempt_sumgrades($attemptid), 0.001);
    }

    /**
     * One essay of 8, with the missing second answer scored as zero.
     *
     * Raw 32 of the same applicable maximum 50 is 64%, stored as sumgrades 51.2.
     * The question total stays 32, which would have been 40%.
     */
    public function test_one_essay_shortfall_zero_scores_64(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_spec_quiz();
        $this->enable_group($quiz->id, $quiz->sectionid, 2, 2, groups::MODE_BEST, api::SHORTFALL_ZERO);
        $responses = $this->truefalse_responses(12, 15);
        $responses[16] = 'Only essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [16 => 8]);

        $this->assertEqualsWithDelta(51.2, $this->attempt_sumgrades($attemptid), 0.001);
        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(32.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $result->appmax, 0.001);
        $this->assertEqualsWithDelta(32.0, (float) $result->coretotal, 0.001);
        $this->assertEqualsWithDelta(64.0, $this->raw_grade($quiz->id, $this->student->id), 0.01);
    }

    /**
     * The same short attempt keeps Moodle's question total when that is the quiz policy.
     */
    public function test_one_essay_shortfall_core_keeps_question_total(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_spec_quiz();
        $this->enable_group($quiz->id, $quiz->sectionid, 2, 2, groups::MODE_BEST, api::SHORTFALL_CORE);
        $responses = $this->truefalse_responses(12, 15);
        $responses[16] = 'Only essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [16 => 8]);

        $this->assertEqualsWithDelta(32.0, $this->attempt_sumgrades($attemptid), 0.001);
        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertNull($result->raw);
        $this->assertEqualsWithDelta(32.0, (float) $result->sumgrades, 0.001);
        $this->assertEqualsWithDelta(40.0, $this->raw_grade($quiz->id, $this->student->id), 0.01);
    }

    /**
     * A missing answer adds the largest unanswered max mark, not the largest in the group.
     *
     * Required 2, plus an answered 5/10, plus one missing slot. The unanswered
     * maxima are 4 and 6, so the filler is 6. Applicable maximum is 2 + 10 + 6.
     */
    public function test_unequal_unanswered_maxmark_fills_the_shortfall(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 1, 2, 1);
        $this->add_questions($quiz, 'essay', 1, 10, 2);
        $this->add_questions($quiz, 'essay', 1, 4, 3);
        $this->add_questions($quiz, 'essay', 1, 6, 4);
        $this->recompute_sumgrades($quiz->id);
        $sectionid = $this->add_section($quiz->id, 2, 'Essays');
        $this->enable_group($quiz->id, $sectionid, 2, 2, groups::MODE_BEST);

        $responses = $this->truefalse_responses(1, 1);
        $responses[2] = 'The ten-mark essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [2 => 5]);

        // 7 / 18 * 22.
        $this->assertEqualsWithDelta(7 / 18 * 22, $this->attempt_sumgrades($attemptid), 0.001);
        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(7.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(18.0, (float) $result->appmax, 0.001);
    }

    /**
     * First-K follows slot order, skipping a blank earlier slot.
     */
    public function test_first_mode_keeps_slot_order(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'essay', 3, 10, 1);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 1, 1, groups::MODE_FIRST);

        $attemptid = $this->submit_as_student($quiz, [2 => 'Second', 3 => 'Third']);
        $this->mark_essays($attemptid, [2 => 2, 3 => 9]);

        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(2.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $result->appmax, 0.001);
        $this->assertEqualsWithDelta(6.0, $this->attempt_sumgrades($attemptid), 0.001);
    }

    /**
     * All, with K empty, counts every answered slot and ignores a gave-up slot.
     */
    public function test_all_mode_blank_k_counts_every_answered(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'essay', 3, 10, 1);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 1, null, groups::MODE_ALL);

        $attemptid = $this->submit_as_student($quiz, [1 => 'One', 2 => 'Two']);
        $this->mark_essays($attemptid, [1 => 4, 2 => 6]);

        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(10.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $result->appmax, 0.001);
        $this->assertEqualsWithDelta(15.0, $this->attempt_sumgrades($attemptid), 0.001);
    }

    /**
     * All, with K set, keeps the best K.
     */
    public function test_all_mode_cap_counts_the_best(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'essay', 3, 10, 1);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 1, 1, groups::MODE_ALL);

        $attemptid = $this->submit_as_student($quiz, [1 => 'One', 2 => 'Two']);
        $this->mark_essays($attemptid, [1 => 4, 2 => 6]);

        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(6.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $result->appmax, 0.001);
    }

    /**
     * Equal fractions keep the earlier slot.
     */
    public function test_best_tie_keeps_the_lower_slot(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'essay', 1, 10, 1);
        $this->add_questions($quiz, 'essay', 1, 6, 2);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 1, 1, groups::MODE_BEST);

        $attemptid = $this->submit_as_student($quiz, [1 => 'Half of ten', 2 => 'Half of six']);
        $this->mark_essays($attemptid, [1 => 5, 2 => 3]);

        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(5.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $result->appmax, 0.001);
    }

    /**
     * Blank K is K = N for best, so a third weaker answer stays out.
     */
    public function test_blank_k_means_n_for_best(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'essay', 3, 10, 1);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 2, null, groups::MODE_BEST);

        $attemptid = $this->submit_as_student($quiz, [1 => 'A', 2 => 'B', 3 => 'C']);
        $this->mark_essays($attemptid, [1 => 9, 2 => 8, 3 => 1]);

        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(17.0, (float) $result->raw, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $result->appmax, 0.001);
        $this->assertEqualsWithDelta(25.5, $this->attempt_sumgrades($attemptid), 0.001);
    }

    /**
     * A group whose section is gone flags itself and the attempt uses the question total.
     */
    public function test_invalid_section_restores_the_question_total(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 1, 2, 1);
        $this->add_questions($quiz, 'essay', 2, 10, 2);
        $this->recompute_sumgrades($quiz->id);
        $sectionid = $this->add_section($quiz->id, 2, 'Essays');
        $this->enable_group($quiz->id, $sectionid, 1, 1, groups::MODE_BEST);

        $responses = $this->truefalse_responses(1, 1);
        $responses[2] = 'Essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [2 => 10]);
        $this->assertEqualsWithDelta(22.0, $this->attempt_sumgrades($attemptid), 0.001);

        $DB->set_field('local_flexiset_group', 'sectionid', -50, ['quizid' => $quiz->id]);
        groups::revalidate($quiz->id);
        rescaler::apply_quiz($quiz->id);

        $this->assertEquals(1, (int) $DB->get_field('local_flexiset_quiz', 'invalid', ['quizid' => $quiz->id]));
        $this->assertEquals(1, (int) $DB->get_field('local_flexiset_group', 'invalid', ['quizid' => $quiz->id]));
        // Question total is the true/false (2) plus the essay (10). The blank essay is 0.
        $this->assertEqualsWithDelta(12.0, $this->attempt_sumgrades($attemptid), 0.001);
    }

    /**
     * Extra grade items are left on the question total and do not mark the group invalid.
     */
    public function test_grade_items_restore_without_flagging_invalid(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 1, 2, 1);
        $this->add_questions($quiz, 'essay', 2, 10, 2);
        $this->recompute_sumgrades($quiz->id);
        $sectionid = $this->add_section($quiz->id, 2, 'Essays');
        $this->enable_group($quiz->id, $sectionid, 1, 1, groups::MODE_BEST);

        $responses = $this->truefalse_responses(1, 1);
        $responses[2] = 'Essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [2 => 10]);
        $this->assertEqualsWithDelta(22.0, $this->attempt_sumgrades($attemptid), 0.001);

        $DB->insert_record('quiz_grade_items', (object) [
            'quizid' => $quiz->id,
            'sortorder' => 1,
            'name' => 'Extra',
        ]);
        rescaler::apply_quiz($quiz->id);

        $this->assertEquals(0, (int) $DB->get_field('local_flexiset_quiz', 'invalid', ['quizid' => $quiz->id]));
        $this->assertEqualsWithDelta(12.0, $this->attempt_sumgrades($attemptid), 0.001);
        $resolved = groups::resolve($quiz->id);
        $this->assertTrue($resolved['gradeitems']);
        $this->assertFalse($resolved['invalid']);
    }

    /**
     * Bulk regrade writes the question total with SQL. apply_quiz puts FlexiSet back.
     */
    public function test_sql_regrade_is_repaired_by_apply_quiz(): void {
        $this->setup_course();
        $quiz = $this->make_spec_quiz();
        $this->enable_group($quiz->id, $quiz->sectionid, 2, 2, groups::MODE_BEST);
        $responses = $this->truefalse_responses(12, 15);
        $responses[16] = 'First essay';
        $responses[17] = 'Second essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [16 => 7, 17 => 8]);
        $this->assertEqualsWithDelta(62.4, $this->attempt_sumgrades($attemptid), 0.001);

        quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_all_attempt_sumgrades();
        $this->assertEqualsWithDelta(39.0, $this->attempt_sumgrades($attemptid), 0.001);

        rescaler::apply_quiz($quiz->id);
        $this->assertEqualsWithDelta(62.4, $this->attempt_sumgrades($attemptid), 0.001);
        $this->assertEqualsWithDelta(78.0, $this->raw_grade($quiz->id, $this->student->id), 0.01);
    }

    /**
     * A teacher preview is finished by core and then left alone.
     */
    public function test_preview_attempt_is_not_rewritten(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 2, 2, 1);
        $this->recompute_sumgrades($quiz->id);
        $this->enable_group($quiz->id, $this->default_section_id($quiz->id), 1, 1, groups::MODE_BEST);

        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->setAdminUser();
        $attempt = $quizgenerator->create_attempt($quiz->id, get_admin()->id);
        // One correct answer of two. FlexiSet would scale that single mark up; the preview must stay at 2.
        $quizgenerator->submit_responses($attempt->id, $this->truefalse_responses(1, 1), false, true);

        $this->assertEquals(1, (int) $DB->get_field('quiz_attempts', 'preview', ['id' => $attempt->id]));
        $this->assertEqualsWithDelta(2.0, $this->attempt_sumgrades($attempt->id), 0.001);
        $this->assertFalse($DB->record_exists('local_flexiset_result', ['attemptid' => $attempt->id]));

        rescaler::apply_attempt((int) $attempt->id);
        $this->assertEqualsWithDelta(2.0, $this->attempt_sumgrades($attempt->id), 0.001);
        $this->assertFalse($DB->record_exists('local_flexiset_result', ['attemptid' => $attempt->id]));
    }

    /**
     * An essay that still needs a mark leaves the attempt ungraded.
     */
    public function test_needsgrading_leaves_sumgrades_null(): void {
        global $DB;
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 1, 2, 1);
        $this->add_questions($quiz, 'essay', 1, 10, 2);
        $this->recompute_sumgrades($quiz->id);
        $sectionid = $this->add_section($quiz->id, 2, 'Essay');
        $this->enable_group($quiz->id, $sectionid, 1, 1, groups::MODE_BEST);

        $responses = $this->truefalse_responses(1, 1);
        $responses[2] = 'Waiting for a mark';
        $attemptid = $this->submit_as_student($quiz, $responses);

        $this->assertNull($this->attempt_sumgrades($attemptid));
        $result = $DB->get_record('local_flexiset_result', ['attemptid' => $attemptid], '*', MUST_EXIST);
        $this->assertNull($result->sumgrades);
        $line = rescaler::review_line(quiz_attempt::create($attemptid));
        $this->assertEquals(get_string('notyetgraded', 'quiz'), $line['value']);
    }

    /**
     * Switching FlexiSet off, or leaving it on with no groups, writes the question total back.
     */
    public function test_disabled_or_no_groups_keeps_the_question_total(): void {
        $this->setup_course();
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 1, 2, 1);
        $this->add_questions($quiz, 'essay', 2, 10, 2);
        $this->recompute_sumgrades($quiz->id);
        $sectionid = $this->add_section($quiz->id, 2, 'Essays');
        $this->enable_group($quiz->id, $sectionid, 1, 1, groups::MODE_BEST);

        $responses = $this->truefalse_responses(1, 1);
        $responses[2] = 'Essay';
        $attemptid = $this->submit_as_student($quiz, $responses);
        $this->mark_essays($attemptid, [2 => 10]);
        $this->assertEqualsWithDelta(22.0, $this->attempt_sumgrades($attemptid), 0.001);

        api::save_quiz_settings($quiz->id, false, api::SHORTFALL_ZERO);
        rescaler::apply_quiz($quiz->id);
        $this->assertEqualsWithDelta(12.0, $this->attempt_sumgrades($attemptid), 0.001);

        api::save_quiz_settings($quiz->id, true, api::SHORTFALL_ZERO);
        $records = groups::records($quiz->id);
        $group = reset($records);
        groups::delete((int) $group->id, $quiz->id);
        rescaler::apply_quiz($quiz->id);
        $this->assertEqualsWithDelta(12.0, $this->attempt_sumgrades($attemptid), 0.001);
    }
}
