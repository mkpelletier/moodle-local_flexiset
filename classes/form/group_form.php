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
 * Add or edit a question group.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_flexiset\form;

use local_flexiset\groups;

/**
 * Moodle form for one section group.
 */
class group_form extends \moodleform {
    /**
     * Form fields.
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement(
            'select',
            'sectionid',
            get_string('section', 'local_flexiset'),
            $this->_customdata['sections']
        );
        if (!empty($this->_customdata['freeze'])) {
            $mform->freeze('sectionid');
        }

        $mform->addElement('text', 'name', get_string('groupname', 'local_flexiset'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addHelpButton('name', 'groupname', 'local_flexiset');

        $mform->addElement('select', 'mode', get_string('mode', 'local_flexiset'), [
            groups::MODE_BEST => get_string('mode_best', 'local_flexiset'),
            groups::MODE_FIRST => get_string('mode_first', 'local_flexiset'),
            groups::MODE_ALL => get_string('mode_all', 'local_flexiset'),
        ]);
        $mform->setDefault('mode', groups::MODE_BEST);

        $mform->addElement('text', 'minanswer', get_string('minanswer', 'local_flexiset'));
        $mform->setType('minanswer', PARAM_INT);
        $mform->setDefault('minanswer', 1);
        $mform->addHelpButton('minanswer', 'minanswer', 'local_flexiset');

        $mform->addElement('text', 'maxcount', get_string('maxcount', 'local_flexiset'));
        $mform->setType('maxcount', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('maxcount', 'maxcount', 'local_flexiset');

        $mform->addElement('static', 'nequalsm', '', get_string('nequalsm', 'local_flexiset'));

        $this->add_action_buttons();
    }

    /**
     * Server-side checks against the live section.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $rawcount = trim((string) ($data['maxcount'] ?? ''));
        $maxcount = null;
        if ($rawcount !== '') {
            if (!preg_match('/^\d+$/', $rawcount)) {
                $errors['maxcount'] = get_string('errorkrange', 'local_flexiset', 0);
                return $errors;
            }
            $maxcount = (int) $rawcount;
        }
        $errors += groups::validate(
            (int) $this->_customdata['quizid'],
            (int) $data['sectionid'],
            (int) $data['minanswer'],
            $maxcount,
            (string) $data['mode'],
            !empty($data['id']) ? (int) $data['id'] : null
        );
        return $errors;
    }
}
