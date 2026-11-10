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

$cm = get_coursemodule_from_id('sat', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:manageoverrides', $context);

$PAGE->set_url('/mod/sat/overrides.php', ['cmid' => $cm->id]);
$PAGE->set_title(get_string('overrides', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overrides', 'mod_sat') . ' — ' . format_string($sat->name));

echo $OUTPUT->box(get_string('overrides_help', 'mod_sat'), 'alert alert-info');

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
    ];
    $table->attributes['class'] = 'table table-bordered table-striped';

    foreach ($overrides as $ov) {
        $user = $ov->userid ? $DB->get_record('user', ['id' => $ov->userid]) : null;
        $username = $user ? fullname($user) : get_string('group');
        $multstr = $ov->timemultiplier ? ($ov->timemultiplier . 'x') : '1.0x';
        $attstr = $ov->attempts !== null ? (string)$ov->attempts : get_string('default', 'mod_sat');

        $table->data[] = [$username, $multstr, $attstr];
    }
    echo html_writer::table($table);
}

echo html_writer::start_div('mt-3');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo $OUTPUT->footer();
