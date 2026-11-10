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
 * Post-attempt student and teacher review interface for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$attemptid = required_param('attemptid', PARAM_INT);

$attempt = $DB->get_record('sat_attempts', ['id' => $attemptid], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $attempt->satid], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $sat->course], '*', MUST_EXIST);
$cm = get_coursemodule_from_instance('sat', $sat->id, $course->id, false, MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

$isowner = ($USER->id == $attempt->userid);
$canreviewown = has_capability('mod/sat:reviewmyattempts', $context);
$canviewreports = has_capability('mod/sat:viewreports', $context);

if (!$canviewreports && (!$isowner || !$canreviewown)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('review', 'mod_sat'));
}

$PAGE->set_url('/mod/sat/review.php', ['attemptid' => $attempt->id]);
$PAGE->set_title(get_string('review', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('review', 'mod_sat') . ' — ' . format_string($sat->name));

// Summary Card.
$user = $DB->get_record('user', ['id' => $attempt->userid]);
$fullname = $user ? fullname($user) : get_string('user');

$totalscorestr = $attempt->scaled_total !== null ? (string)$attempt->scaled_total : '-';
$rwscorestr = $attempt->scaled_rw !== null ? (string)$attempt->scaled_rw : '-';
$mathscorestr = $attempt->scaled_math !== null ? (string)$attempt->scaled_math : '-';

$duration = ($attempt->timefinish && $attempt->timestart) ? format_time($attempt->timefinish - $attempt->timestart) : '-';

echo html_writer::start_div('card p-4 mb-4 shadow-sm border-primary');
echo html_writer::start_div('row align-items-center');

echo html_writer::start_div('col-md-6');
echo html_writer::tag('h5', $fullname, ['class' => 'fw-bold mb-1']);
echo html_writer::tag('div', get_string('attempt', 'mod_sat') . ' #' . $attempt->attempt, ['class' => 'text-muted mb-2']);
echo html_writer::tag('div', '<strong>Started:</strong> ' . userdate($attempt->timestart));
echo html_writer::tag('div', '<strong>Completed:</strong> ' . ($attempt->timefinish ? userdate($attempt->timefinish) : 'In progress'));
echo html_writer::tag('div', '<strong>Time Taken:</strong> ' . $duration);
echo html_writer::end_div();

echo html_writer::start_div('col-md-6 text-md-end mt-3 mt-md-0');
echo html_writer::tag('div', 'Total Scaled Score', ['class' => 'small text-muted text-uppercase fw-bold']);
echo html_writer::tag('div', $totalscorestr . ' <small class="text-muted fs-6">/ ' . $sat->grade . '</small>', ['class' => 'fs-1 fw-bold text-primary']);
echo html_writer::tag('div', '<span class="badge bg-info text-dark me-2">RW: ' . $rwscorestr . '</span><span class="badge bg-success">Math: ' . $mathscorestr . '</span>', ['class' => 'mt-2']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

// Question-by-question review using Question Engine.
if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
    $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
    $slots = $quba->get_slots();

    if (!empty($slots)) {
        echo html_writer::tag('h5', 'Question Review', ['class' => 'mb-3 fw-bold']);

        $options = new question_display_options();
        $options->readonly = true;
        $options->flags = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;

        foreach ($slots as $slot) {
            echo html_writer::start_div('sat-review-question-card card mb-3 p-3 shadow-sm');
            echo $quba->render_question($slot, $options, (string)$slot);
            echo html_writer::end_div();
        }
    }
}

echo html_writer::start_div('mt-4');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary px-4']);
echo html_writer::end_div();

echo $OUTPUT->footer();
