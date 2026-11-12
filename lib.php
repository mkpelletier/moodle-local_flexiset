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
 * Library callbacks.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the question-groups page to a quiz activity menu.
 *
 * @param settings_navigation $settingsnav
 * @param context $context
 */
function local_flexiset_extend_settings_navigation(settings_navigation $settingsnav, context $context): void {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return;
    }

    $cm = get_coursemodule_from_id('', $context->instanceid);
    if (!$cm || $cm->modname !== 'quiz') {
        return;
    }
    if (!has_capability('local/flexiset:manage', $context)) {
        return;
    }

    $modulesettings = $settingsnav->find('modulesettings', navigation_node::TYPE_SETTING);
    if (!$modulesettings) {
        return;
    }

    $url = new moodle_url('/local/flexiset/groups.php', ['cmid' => $cm->id]);
    $node = navigation_node::create(
        get_string('groups', 'local_flexiset'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'flexisetgroups',
        new pix_icon('i/settings', '')
    );

    $keys = $modulesettings->get_children_key_list();
    $beforekey = null;
    $editindex = array_search('modedit', $keys, true);
    if ($editindex !== false && array_key_exists($editindex + 1, $keys)) {
        $beforekey = $keys[$editindex + 1];
    }
    $modulesettings->add_node($node, $beforekey);
}
