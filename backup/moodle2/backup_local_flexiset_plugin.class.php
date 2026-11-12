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
 * Backup of FlexiSet quiz settings and section groups.
 *
 * Result rows are attempt grades and are not backed up.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Attach FlexiSet rows to a quiz activity backup.
 */
class backup_local_flexiset_plugin extends backup_local_plugin {
    /**
     * Settings and groups for this quiz.
     *
     * The structure is added under every activity. The modulename condition
     * keeps a forum whose id matches a quiz id from picking up that quiz's rows.
     * VAR_ACTIVITYID is the quiz id, not the course-module id.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '../../modulename', 'quiz');

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $settings = new backup_nested_element('flexiset_quiz', ['id'], [
            'enabled', 'shortfall', 'invalid', 'timemodified',
        ]);
        $group = new backup_nested_element('flexiset_group', ['id'], [
            'sectionid', 'name', 'mode', 'minanswer', 'maxcount', 'invalid', 'sortorder', 'timemodified',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($settings);
        $wrapper->add_child($group);

        $settings->set_source_table('local_flexiset_quiz', ['quizid' => backup::VAR_ACTIVITYID]);
        $group->set_source_table('local_flexiset_group', ['quizid' => backup::VAR_ACTIVITYID]);

        return $plugin;
    }
}
