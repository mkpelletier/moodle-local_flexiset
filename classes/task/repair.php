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
 * Nightly repair of FlexiSet grades.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset\task;

use local_flexiset\rescaler;

/**
 * Reapply every quiz that has FlexiSet settings.
 */
class repair extends \core\task\scheduled_task {
    /**
     * Task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrepair', 'local_flexiset');
    }

    /**
     * Rewrite finished attempts whose stored total has drifted.
     */
    public function execute(): void {
        global $DB;
        $quizids = $DB->get_fieldset_sql('SELECT quizid FROM {local_flexiset_quiz}');
        foreach ($quizids as $quizid) {
            rescaler::apply_quiz((int) $quizid);
        }
    }
}
