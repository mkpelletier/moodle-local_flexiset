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
 * Edit FlexiSet question groups for one quiz.
 *
 * The on/off switch is edited under Extra restrictions on the quiz settings
 * form. This page shows that switch and links to it.
 *
 * @package    local_flexiset
 * @copyright  2026 South African Theological Seminary (mathieu@sats.ac.za)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(local_flexiset_config());

use local_flexiset\api;
use local_flexiset\form\group_form;
use local_flexiset\groups;

$cmid = required_param('cmid', PARAM_INT);
$add = optional_param('add', 0, PARAM_BOOL);
$edit = optional_param('edit', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, false, $cm);
require_capability('local/flexiset:manage', $context);

$base = new moodle_url('/local/flexiset/groups.php', ['cmid' => $cm->id]);
$pageparams = ['cmid' => $cm->id];
if ($add) {
    $pageparams['add'] = 1;
} else if ($edit) {
    $pageparams['edit'] = $edit;
}
$PAGE->set_url(new moodle_url('/local/flexiset/groups.php', $pageparams));
$PAGE->set_cm($cm, $course, $quiz);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('groups', 'local_flexiset'));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->set_attrs(['hidecompletion' => true]);

if ($delete) {
    require_sesskey();
    $group = $DB->get_record('local_flexiset_group', ['id' => $delete, 'quizid' => $quiz->id], '*', MUST_EXIST);
    if ($confirm) {
        groups::delete((int) $group->id, (int) $quiz->id);
        redirect($base, get_string('deleted', 'local_flexiset'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $resolved = groups::resolve((int) $quiz->id);
    $name = $resolved['groups'][$group->id]['displayname'] ?? format_string((string) $group->name);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deleteconfirm', 'local_flexiset', $name),
        new moodle_url($base, ['delete' => $group->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $base
    );
    echo $OUTPUT->footer();
    exit;
}

$editing = null;
if ($edit) {
    $editing = $DB->get_record('local_flexiset_group', ['id' => $edit, 'quizid' => $quiz->id], '*', MUST_EXIST);
}

if ($add || $editing) {
    $choices = groups::section_choices((int) $quiz->id, $editing ? (int) $editing->sectionid : null);
    if (!$choices) {
        redirect($base, get_string('nosections', 'local_flexiset'), null, \core\output\notification::NOTIFY_WARNING);
    }
    $formurl = new moodle_url('/local/flexiset/groups.php', $editing ? ['cmid' => $cm->id, 'edit' => $editing->id] : ['cmid' => $cm->id, 'add' => 1]);
    $form = new group_form($formurl, [
        'quizid' => (int) $quiz->id,
        'sections' => $choices,
        'freeze' => (bool) $editing,
    ]);
    if ($editing) {
        $form->set_data([
            'id' => $editing->id,
            'sectionid' => $editing->sectionid,
            'name' => $editing->name,
            'mode' => $editing->mode,
            'minanswer' => $editing->minanswer,
            'maxcount' => $editing->maxcount,
        ]);
    }
    if ($form->is_cancelled()) {
        redirect($base);
    }
    if ($data = $form->get_data()) {
        $rawcount = trim((string) $data->maxcount);
        groups::save(
            (int) $quiz->id,
            !empty($data->id) ? (int) $data->id : null,
            (int) $data->sectionid,
            $data->name,
            (string) $data->mode,
            (int) $data->minanswer,
            $rawcount === '' ? null : (int) $rawcount
        );
        redirect($base, get_string('saved', 'local_flexiset'), null, \core\output\notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo local_flexiset_settings_note($quiz, $cm);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo local_flexiset_settings_note($quiz, $cm);
echo $OUTPUT->single_button(new moodle_url($base, ['add' => 1]), get_string('addgroup', 'local_flexiset'), 'get');

$resolved = groups::resolve((int) $quiz->id);
$records = groups::records((int) $quiz->id);
if (!$records) {
    echo $OUTPUT->notification(get_string('nogroups', 'local_flexiset'), 'info', false);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('section', 'local_flexiset'),
    get_string('mode', 'local_flexiset'),
    get_string('n', 'local_flexiset'),
    get_string('k', 'local_flexiset'),
    get_string('questions', 'local_flexiset'),
    get_string('status', 'local_flexiset'),
    get_string('actions', 'local_flexiset'),
];
$table->attributes['class'] = 'generaltable';
foreach ($records as $record) {
    $live = $resolved['groups'][$record->id] ?? null;
    $modename = get_string('mode_' . $record->mode, 'local_flexiset');
    $status = get_string('valid', 'local_flexiset');
    if ($live && $live['invalid']) {
        $status = get_string('invalid_' . $live['reason'], 'local_flexiset');
    }
    $editurl = new moodle_url($base, ['edit' => $record->id]);
    $deleteurl = new moodle_url($base, ['delete' => $record->id, 'sesskey' => sesskey()]);
    $actions = $OUTPUT->action_icon($editurl, new pix_icon('t/edit', get_string('edit'))) . ' ' .
        $OUTPUT->action_icon($deleteurl, new pix_icon('t/delete', get_string('delete')));
    $table->data[] = [
        $live['displayname'] ?? format_string((string) $record->name),
        $modename,
        $record->minanswer,
        $record->maxcount === null ? get_string('none') : $record->maxcount,
        $live ? count($live['slots']) : '',
        $status,
        $actions,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();

/**
 * Find config.php from this page.
 *
 * The plugin directory may be a symlink, so __DIR__ can sit outside the
 * Moodle tree. The web server's document root still points at it.
 *
 * @return string
 */
function local_flexiset_config(): string {
    $candidates = [];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = dirname($_SERVER['DOCUMENT_ROOT']) . '/config.php';
        $candidates[] = $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    }
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $dir = dirname($_SERVER['SCRIPT_FILENAME']);
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = $dir . '/config.php';
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }
    $candidates[] = dirname(__DIR__, 3) . '/config.php';
    $candidates[] = dirname(__DIR__, 2) . '/config.php';
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new \Exception('Cannot find Moodle config.php for the FlexiSet groups page.');
}

/**
 * Read-only switch, with the link to the only editor.
 *
 * @param stdClass $quiz
 * @param stdClass $cm
 * @return string
 */
function local_flexiset_settings_note(stdClass $quiz, stdClass $cm): string {
    $settings = api::get((int) $quiz->id);
    $enabled = $settings && $settings->enabled;
    $shortfall = $settings->shortfall ?? api::SHORTFALL_ZERO;
    $text = get_string('settingsreadonly', 'local_flexiset', (object) [
        'enabled' => get_string($enabled ? 'yes' : 'no'),
        'shortfall' => get_string('shortfall_' . $shortfall, 'local_flexiset'),
    ]);
    $link = html_writer::link(
        new moodle_url('/course/modedit.php', ['update' => $cm->id]),
        get_string('editquizsettings', 'local_flexiset')
    );
    return html_writer::div($text . ' ' . $link, 'alert alert-info');
}
