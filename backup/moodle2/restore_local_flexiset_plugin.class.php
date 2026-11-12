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
 * Restore FlexiSet settings after the quiz sections exist.
 *
 * The local plugin is processed with the course-module, before quiz.xml, so
 * the new quiz id and the quiz_section mapping are not available in process_*.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Stash FlexiSet rows and insert them once the quiz has been restored.
 */
class restore_local_flexiset_plugin extends restore_local_plugin {
    /** @var stdClass|null */
    private $quizdata = null;

    /** @var stdClass[] */
    private $groups = [];

    /**
     * Paths inside the activity backup.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('flexiset_quiz', $this->get_pathfor('/flexiset_quiz')),
            new restore_path_element('flexiset_group', $this->get_pathfor('/flexiset_group')),
        ];
    }

    /**
     * Hold the quiz switch until the new quiz id exists.
     *
     * @param array|stdClass $data
     */
    public function process_flexiset_quiz($data): void {
        $this->quizdata = (object) $data;
    }

    /**
     * Hold one group until quiz_section ids have been mapped.
     *
     * @param array|stdClass $data
     */
    public function process_flexiset_group($data): void {
        $this->groups[] = (object) $data;
    }

    /**
     * Insert the stashed rows against the restored quiz.
     */
    public function after_restore_module(): void {
        global $DB;
        if ($this->task->get_modulename() !== 'quiz' || $this->quizdata === null) {
            return;
        }
        $quizid = (int) $this->task->get_activityid();
        if (!$quizid || $DB->record_exists('local_flexiset_quiz', ['quizid' => $quizid])) {
            return;
        }

        $shortfall = (string) ($this->quizdata->shortfall ?? 'zero');
        if ($shortfall !== 'zero' && $shortfall !== 'core') {
            $shortfall = 'zero';
        }
        $DB->insert_record('local_flexiset_quiz', (object) [
            'quizid' => $quizid,
            'enabled' => !empty($this->quizdata->enabled) ? 1 : 0,
            'shortfall' => $shortfall,
            'invalid' => 0,
            'timemodified' => time(),
        ]);

        foreach ($this->groups as $group) {
            $sectionid = (int) $this->get_mappingid('quiz_section', $group->sectionid, 0);
            if (!$sectionid) {
                // Two groups can both lose their section. A shared 0 would break the unique index.
                // A negative id cannot match a restored section, so revalidate flags the group.
                $sectionid = -1 * (int) $group->sectionid;
            }
            $maxcount = $group->maxcount;
            if ($maxcount === '' || $maxcount === null) {
                $maxcount = null;
            } else {
                $maxcount = (int) $maxcount;
            }
            $name = trim((string) ($group->name ?? ''));
            $DB->insert_record('local_flexiset_group', (object) [
                'quizid' => $quizid,
                'sectionid' => (int) $sectionid,
                'name' => $name === '' ? null : $name,
                'mode' => (string) ($group->mode ?? 'best'),
                'minanswer' => (int) $group->minanswer,
                'maxcount' => $maxcount,
                'invalid' => $sectionid ? 0 : 1,
                'sortorder' => (int) ($group->sortorder ?? 0),
                'timemodified' => time(),
            ]);
        }

        \local_flexiset\groups::revalidate($quizid);
        \local_flexiset\rescaler::queue_quiz($quizid);
    }
}
