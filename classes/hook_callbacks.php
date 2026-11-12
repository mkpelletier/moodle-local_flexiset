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
 * Hook callbacks.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset;

use mod_quiz\quiz_attempt;

/**
 * Quiz hook callbacks.
 */
class hook_callbacks {
    /**
     * Rewrite the total once an attempt is finished.
     *
     * The gradebook push inside process_grade_submission has already run, so
     * this pass replaces that total and pushes again.
     *
     * @param \mod_quiz\hook\attempt_state_changed $hook
     */
    public static function attempt_state_changed(\mod_quiz\hook\attempt_state_changed $hook): void {
        if (rescaler::is_running()) {
            return;
        }
        $updated = $hook->get_updated_attempt();
        if (!$updated || $updated->state !== quiz_attempt::FINISHED) {
            return;
        }
        rescaler::apply_attempt((int) $updated->id);
    }

    /**
     * Section and slot edits that recompute quiz.sumgrades land here.
     *
     * Adding or removing a section does not. Those have their own events.
     *
     * @param \mod_quiz\hook\structure_modified $hook
     */
    public static function structure_modified(\mod_quiz\hook\structure_modified $hook): void {
        $quizid = $hook->get_structure()->get_quizid();
        groups::revalidate($quizid);
        rescaler::queue_quiz($quizid);
    }
}
