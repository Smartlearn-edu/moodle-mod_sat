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
 * List all instances of mod_sat in a course.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT); // Course ID.

$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$PAGE->set_url('/mod/sat/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->fullname) . ': ' . get_string('modulenameplural', 'mod_sat'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_sat'));

if (!$sats = get_all_instances_in_course('sat', $course)) {
    notice(get_string('nosatexams', 'mod_sat'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [
    get_string('name'),
    get_string('exam_scope', 'mod_sat'),
    get_string('pacing_mode', 'mod_sat'),
    get_string('timeclose', 'mod_sat'),
];

foreach ($sats as $sat) {
    $link = html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $sat->coursemodule]), format_string($sat->name));
    $scopestr = get_string('scope_' . $sat->exam_scope, 'mod_sat');
    $pacingstr = get_string('pacing_' . $sat->pacing_mode, 'mod_sat');
    $closestr = $sat->timeclose ? userdate($sat->timeclose) : '-';

    $table->data[] = [$link, $scopestr, $pacingstr, $closestr];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
