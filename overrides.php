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
 * Accommodations and user overrides page for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$overrideid = optional_param('overrideid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('sat', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:manageoverrides', $context);

// Handle save override action.
if ($action === 'save' && confirm_sesskey()) {
    $userid = required_param('userid', PARAM_INT);
    $timemultiplier = required_param('timemultiplier', PARAM_FLOAT);
    $attempts = optional_param('attempts', 0, PARAM_INT);

    if ($userid > 0) {
        $existing = $DB->get_record('sat_overrides', [
            'satid' => $sat->id,
            'userid' => $userid,
        ]);

        if ($existing) {
            $existing->timemultiplier = $timemultiplier;
            $existing->attempts = $attempts;
            $DB->update_record('sat_overrides', $existing);
        } else {
            $record = new stdClass();
            $record->satid = $sat->id;
            $record->userid = $userid;
            $record->timemultiplier = $timemultiplier;
            $record->attempts = $attempts;
            $DB->insert_record('sat_overrides', $record);
        }
    }

    redirect(new moodle_url('/mod/sat/overrides.php', ['cmid' => $cm->id]));
}

// Handle delete override action.
if ($action === 'delete' && $overrideid > 0 && confirm_sesskey()) {
    $DB->delete_records('sat_overrides', [
        'id' => $overrideid,
        'satid' => $sat->id,
    ]);
    redirect(new moodle_url('/mod/sat/overrides.php', ['cmid' => $cm->id]));
}

$PAGE->set_url('/mod/sat/overrides.php', ['cmid' => $cm->id]);
$PAGE->set_title(get_string('overrides', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides', 'mod_sat') . ' — ' . format_string($sat->name));

echo $OUTPUT->box(get_string('overrides_help', 'mod_sat'), 'alert alert-info');

// Add or update accommodation override form.
$enrolledusers = get_enrolled_users($context, 'mod/sat:attempt');
if (empty($enrolledusers)) {
    $enrolledusers = get_enrolled_users($context);
}

echo html_writer::start_div('card p-3 mb-4 shadow-sm border bg-light');
echo html_writer::tag('h5', 'Add / Update Accommodation Override', ['class' => 'card-title mb-3']);

if (empty($enrolledusers)) {
    echo $OUTPUT->notification('No enrolled users found in this course.', 'warning');
} else {
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/sat/overrides.php'),
        'class' => 'row g-3 align-items-end',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cm->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    // Student selector.
    echo html_writer::start_div('col-md-4');
    echo html_writer::tag('label', get_string('user'), ['for' => 'ov-userid', 'class' => 'form-label fw-semibold']);
    $useroptions = [];
    foreach ($enrolledusers as $u) {
        $useroptions[$u->id] = fullname($u) . ' (' . $u->email . ')';
    }
    echo html_writer::select($useroptions, 'userid', 0, false, ['id' => 'ov-userid', 'class' => 'form-select']);
    echo html_writer::end_div();

    // Time multiplier selector.
    echo html_writer::start_div('col-md-3');
    echo html_writer::tag('label', get_string('timemultiplier', 'mod_sat'), [
        'for' => 'ov-timemultiplier',
        'class' => 'form-label fw-semibold',
    ]);
    $multiplieroptions = [
        '1.00' => '1.0x (Standard Time)',
        '1.25' => '1.25x (+25% Extended)',
        '1.50' => '1.5x (Time and a Half)',
        '2.00' => '2.0x (Double Time)',
    ];
    echo html_writer::select($multiplieroptions, 'timemultiplier', '1.50', false, [
        'id' => 'ov-timemultiplier',
        'class' => 'form-select',
    ]);
    echo html_writer::end_div();

    // Attempts allowed selector.
    echo html_writer::start_div('col-md-3');
    echo html_writer::tag('label', get_string('attemptsallowed', 'mod_sat'), [
        'for' => 'ov-attempts',
        'class' => 'form-label fw-semibold',
    ]);
    $attemptoptions = [
        0 => get_string('unlimited'),
        1 => '1',
        2 => '2',
        3 => '3',
        5 => '5',
    ];
    echo html_writer::select($attemptoptions, 'attempts', (int)$sat->attempts, false, [
        'id' => 'ov-attempts',
        'class' => 'form-select',
    ]);
    echo html_writer::end_div();

    // Submit button.
    echo html_writer::start_div('col-md-2');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('savechanges'),
        'class' => 'btn btn-primary w-100',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
}
echo html_writer::end_div();

// List current overrides.
$overrides = $DB->get_records('sat_overrides', ['satid' => $sat->id]);

if (empty($overrides)) {
    echo $OUTPUT->notification(get_string('nooverrides', 'mod_sat'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('timemultiplier', 'mod_sat'),
        get_string('attemptsallowed', 'mod_sat'),
        get_string('actions'),
    ];
    $table->attributes['class'] = 'table table-bordered table-striped align-middle';

    foreach ($overrides as $ov) {
        $user = $ov->userid ? $DB->get_record('user', ['id' => $ov->userid]) : null;
        $username = $user ? fullname($user) : get_string('group');
        $multstr = $ov->timemultiplier ? ($ov->timemultiplier . 'x') : '1.0x';
        if ($ov->attempts === null) {
            $attstr = get_string('default', 'mod_sat');
        } else if ((int)$ov->attempts === 0) {
            $attstr = get_string('unlimited');
        } else {
            $attstr = (string)$ov->attempts;
        }

        $deleteurl = new moodle_url('/mod/sat/overrides.php', [
            'cmid' => $cm->id,
            'action' => 'delete',
            'overrideid' => $ov->id,
            'sesskey' => sesskey(),
        ]);
        $deletelink = html_writer::link($deleteurl, get_string('delete'), [
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm("Remove accommodation override for ' . s($username) . '?");',
        ]);

        $table->data[] = [$username, $multstr, $attstr, $deletelink];
    }
    echo html_writer::table($table);
}

echo html_writer::start_div('mt-3');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo $OUTPUT->footer();
