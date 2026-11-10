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
 * Teacher attempts reporting page for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.

$cm = get_coursemodule_from_id('sat', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:viewreports', $context);

$PAGE->set_url('/mod/sat/report.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('viewreports', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('viewreports', 'mod_sat') . ' — ' . format_string($sat->name));

// Attempts list.
$sql = "SELECT a.*, u.firstname, u.lastname, u.email
          FROM {sat_attempts} a
          JOIN {user} u ON u.id = a.userid
         WHERE a.satid = :satid
      ORDER BY a.timecreated DESC";

$attempts = $DB->get_records_sql($sql, ['satid' => $sat->id]);

if (empty($attempts)) {
    echo $OUTPUT->notification(get_string('noattempts', 'mod_sat'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('user'),
        get_string('attempt', 'mod_sat'),
        get_string('state', 'mod_sat'),
        get_string('scaled_rw', 'mod_sat'),
        get_string('scaled_math', 'mod_sat'),
        get_string('scaled_total', 'mod_sat'),
        get_string('timecompleted', 'mod_sat'),
    ];
    $table->attributes['class'] = 'table table-bordered table-striped';

    foreach ($attempts as $att) {
        $fullname = fullname($att);
        $userlink = html_writer::link(new moodle_url('/user/view.php', ['id' => $att->userid, 'course' => $course->id]), $fullname);
        $statestr = get_string('state_' . $att->state, 'mod_sat');
        $completedstr = $att->timefinish ? userdate($att->timefinish) : '-';

        $rwstr = $att->scaled_rw !== null ? (string)$att->scaled_rw : '-';
        $mathstr = $att->scaled_math !== null ? (string)$att->scaled_math : '-';
        $totalstr = $att->scaled_total !== null ? (string)$att->scaled_total : '-';

        $table->data[] = [
            $userlink,
            $att->attempt,
            $statestr,
            $rwstr,
            $mathstr,
            $totalstr,
            $completedstr,
        ];
    }

    echo html_writer::table($table);
}

echo html_writer::start_div('mt-3');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary']);
echo html_writer::end_div();

echo $OUTPUT->footer();
