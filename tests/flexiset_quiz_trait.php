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
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/**
 * Build quizzes and finish attempts for FlexiSet tests.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait flexiset_quiz_trait {
    /** @var \stdClass */
    protected $course;

    /** @var \stdClass */
    protected $student;

    /** @var \stdClass */
    protected $category;

    /**
     * Course, student, and a question category in that course.
     */
    protected function setup_course(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->student = $generator->create_user();
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $this->setAdminUser();

        $questiongenerator = $generator->get_plugin_generator('core_question');
        $this->category = $questiongenerator->create_question_category([
            'contextid' => \context_course::instance($this->course->id)->id,
        ]);
    }

    /**
     * A quiz whose grade is 100. Sumgrades is whatever the slots add up to after recompute.
     *
     * @return \stdClass
     */
    protected function make_quiz(): \stdClass {
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $quizgenerator->create_instance([
            'course' => $this->course->id,
            'grade' => 100.0,
            'sumgrades' => 1,
            'questionsperpage' => 1,
        ]);
        $quiz->cmid = get_coursemodule_from_instance('quiz', $quiz->id, $this->course->id, false, MUST_EXIST)->id;
        return $quiz;
    }

    /**
     * Add questions, one per page, starting at $startpage.
     *
     * @param \stdClass $quiz
     * @param string $qtype
     * @param int $count
     * @param float $maxmark
     * @param int $startpage
     */
    protected function add_questions(\stdClass $quiz, string $qtype, int $count, float $maxmark, int $startpage): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        for ($i = 0; $i < $count; $i++) {
            $question = $questiongenerator->create_question($qtype, null, ['category' => $this->category->id]);
            quiz_add_quiz_question($question->id, $quiz, $startpage + $i, $maxmark);
        }
    }

    /**
     * Set quiz.sumgrades from the slot max marks.
     *
     * @param int $quizid
     */
    protected function recompute_sumgrades(int $quizid): void {
        quiz_settings::create($quizid)->get_grade_calculator()->recompute_quiz_sumgrades();
    }

    /**
     * Open a section on a page that already has a slot.
     *
     * @param int $quizid
     * @param int $page
     * @param string $heading
     * @return int
     */
    protected function add_section(int $quizid, int $page, string $heading): int {
        return quiz_settings::create($quizid)->get_structure()->add_section_heading($page, $heading);
    }

    /**
     * Id of the quiz's first section.
     *
     * @param int $quizid
     * @return int
     */
    protected function default_section_id(int $quizid): int {
        $sections = quiz_settings::create($quizid)->get_structure()->get_sections();
        $section = reset($sections);
        return (int) $section->id;
    }

    /**
     * Save one group and turn FlexiSet on.
     *
     * @param int $quizid
     * @param int $sectionid
     * @param int $minanswer
     * @param int|null $maxcount
     * @param string $mode
     * @param string $shortfall
     * @param string|null $name
     */
    protected function enable_group(
        int $quizid,
        int $sectionid,
        int $minanswer,
        ?int $maxcount,
        string $mode,
        string $shortfall = api::SHORTFALL_ZERO,
        ?string $name = null
    ): void {
        groups::save($quizid, null, $sectionid, $name, $mode, $minanswer, $maxcount);
        api::save_quiz_settings($quizid, true, $shortfall);
    }

    /**
     * The spec quiz: 15 true/false worth 2, then 5 essays worth 10 in Section C.
     *
     * Twelve true and three false is 24 of the required 30. quiz.sumgrades is 80.
     *
     * @return \stdClass quiz row, with cmid and sectionid.
     */
    protected function make_spec_quiz(): \stdClass {
        $quiz = $this->make_quiz();
        $this->add_questions($quiz, 'truefalse', 15, 2, 1);
        $this->add_questions($quiz, 'essay', 5, 10, 16);
        $this->recompute_sumgrades($quiz->id);
        $quiz->sectionid = $this->add_section($quiz->id, 16, 'Section C');
        return $quiz;
    }

    /**
     * Finish an attempt as the student.
     *
     * True/false responses are the strings True or False. Essay responses are the
     * answer text. Slots left out are unanswered.
     *
     * @param \stdClass $quiz
     * @param array $responses slot => response summary
     * @return int attempt id
     */
    protected function submit_as_student(\stdClass $quiz, array $responses): int {
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->setUser($this->student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $this->student->id);
        $quizgenerator->submit_responses($attempt->id, $responses, false, true);
        $this->setAdminUser();
        return (int) $attempt->id;
    }

    /**
     * True for the first $correct slots and False for the rest, up to $count.
     *
     * @param int $correct
     * @param int $count
     * @param int $startslot
     * @return array
     */
    protected function truefalse_responses(int $correct, int $count, int $startslot = 1): array {
        $true = get_string('true', 'qtype_truefalse');
        $false = get_string('false', 'qtype_truefalse');
        $responses = [];
        for ($i = 0; $i < $count; $i++) {
            $responses[$startslot + $i] = $i < $correct ? $true : $false;
        }
        return $responses;
    }

    /**
     * Mark finished essay slots, store the question total, then raise the grading event.
     *
     * The quiz comment page writes the question-usage total before
     * question_manually_graded. FlexiSet listens to that event and replaces the total.
     *
     * @param int $attemptid
     * @param array $slotmarks slot => absolute mark
     */
    protected function mark_essays(int $attemptid, array $slotmarks): void {
        global $DB;
        $this->setAdminUser();
        $attemptobj = quiz_attempt::create($attemptid);
        $quba = $attemptobj->get_question_usage();
        foreach ($slotmarks as $slot => $mark) {
            $quba->manual_grade((int) $slot, 'Marked', (float) $mark, FORMAT_HTML);
        }
        \question_engine::save_questions_usage_by_activity($quba);

        $core = $quba->get_total_mark();
        $DB->set_field('quiz_attempts', 'sumgrades', $core, ['id' => $attemptid]);
        $DB->set_field('quiz_attempts', 'timemodified', time(), ['id' => $attemptid]);
        if ($core !== null) {
            $attemptobj->get_quizobj()->get_grade_calculator()->recompute_final_grade($attemptobj->get_userid());
        }

        foreach ($slotmarks as $slot => $mark) {
            $slot = (int) $slot;
            $qa = $quba->get_question_attempt($slot);
            \mod_quiz\event\question_manually_graded::create([
                'objectid' => $qa->get_question_id(),
                'courseid' => $attemptobj->get_courseid(),
                'context' => $attemptobj->get_quizobj()->get_context(),
                'other' => [
                    'quizid' => $attemptobj->get_quizid(),
                    'attemptid' => $attemptid,
                    'slot' => $slot,
                ],
            ])->trigger();
        }
    }

    /**
     * Read quiz_attempts.sumgrades for one attempt.
     *
     * @param int $attemptid
     * @return float|null
     */
    protected function attempt_sumgrades(int $attemptid): ?float {
        global $DB;
        $value = $DB->get_field('quiz_attempts', 'sumgrades', ['id' => $attemptid]);
        if ($value === null || $value === false) {
            return null;
        }
        return (float) $value;
    }

    /**
     * Gradebook raw grade for the quiz's main item.
     *
     * @param int $quizid
     * @param int $userid
     * @return float|null
     */
    protected function raw_grade(int $quizid, int $userid): ?float {
        global $DB;
        $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
        $item = \grade_item::fetch([
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quizid,
            'itemnumber' => 0,
            'courseid' => $quiz->course,
        ]);
        if (!$item) {
            return null;
        }
        $grade = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $userid]);
        if (!$grade || $grade->rawgrade === null) {
            return null;
        }
        return (float) $grade->rawgrade;
    }
}
