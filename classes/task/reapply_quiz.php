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
 * Adhoc reapply of one quiz.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset\task;

use local_flexiset\rescaler;

/**
 * Rewrite finished attempts after the request that queued this task.
 */
class reapply_quiz extends \core\task\adhoc_task {
    /**
     * Task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskreapply', 'local_flexiset');
    }

    /**
     * Reapply the quiz named in the custom data.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $quizid = (int) ($data->quizid ?? 0);
        if ($quizid) {
            rescaler::apply_quiz($quizid);
        }
    }
}
