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
 * Student diagnostic score report for mod_sat.
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
$canviewreports = has_capability('mod/sat:viewreports', $context);
$canreviewown = has_capability('mod/sat:reviewmyattempts', $context);

if (!$canviewreports && (!$isowner || !$canreviewown)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('diagnostic_report', 'mod_sat'));
}

$PAGE->set_url('/mod/sat/diagnostic.php', ['attemptid' => $attempt->id]);
$PAGE->set_title(get_string('diagnostic_report', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('diagnostic_report', 'mod_sat') . ' — ' . format_string($sat->name));

// User Info.
$user = $DB->get_record('user', ['id' => $attempt->userid]);
$fullname = $user ? fullname($user) : get_string('user');

$totalscorestr = $attempt->scaled_total !== null ? (string)$attempt->scaled_total : '-';
$rwscorestr = $attempt->scaled_rw !== null ? (string)$attempt->scaled_rw : '-';
$mathscorestr = $attempt->scaled_math !== null ? (string)$attempt->scaled_math : '-';

// Score Hero Banner.
echo html_writer::start_div('sat-score-hero card p-4 mb-4 shadow text-white bg-dark border-0 rounded-4');
echo html_writer::start_div('d-flex justify-content-between align-items-center flex-wrap gap-3');

echo html_writer::start_div();
echo html_writer::tag('span', 'Official-Style SAT Diagnostic Report', ['class' => 'badge bg-primary text-uppercase px-3 py-2 mb-2']);
echo html_writer::tag('h2', $fullname, ['class' => 'fw-bold mb-1 text-white']);
echo html_writer::tag('div', get_string('attempt', 'mod_sat') . ' #' . $attempt->attempt . ' • ' . userdate($attempt->timefinish ?: time()), ['class' => 'text-secondary']);
echo html_writer::end_div();

echo html_writer::start_div('d-flex gap-4 text-center');
echo html_writer::start_div('border-start border-secondary ps-3');
echo html_writer::tag('small', 'Reading & Writing', ['class' => 'text-muted text-uppercase d-block']);
echo html_writer::tag('span', $rwscorestr, ['class' => 'fs-2 fw-bold text-info']);
echo html_writer::tag('small', ' / 800', ['class' => 'text-secondary d-block']);
echo html_writer::end_div();

echo html_writer::start_div('border-start border-secondary ps-3');
echo html_writer::tag('small', 'Math', ['class' => 'text-muted text-uppercase d-block']);
echo html_writer::tag('span', $mathscorestr, ['class' => 'fs-2 fw-bold text-success']);
echo html_writer::tag('small', ' / 800', ['class' => 'text-secondary d-block']);
echo html_writer::end_div();

echo html_writer::start_div('border-start border-secondary ps-3');
echo html_writer::tag('small', 'Total Score', ['class' => 'text-muted text-uppercase d-block']);
echo html_writer::tag('span', $totalscorestr, ['class' => 'fs-1 fw-bold text-warning']);
echo html_writer::tag('small', ' / ' . $sat->grade, ['class' => 'text-secondary d-block']);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

// Analyze domain breakdown.
$domains = \mod_sat\local\diagnostic_manager::analyze_attempt_domains($attempt);

if (!empty($domains)) {
    echo html_writer::tag('h4', 'Domain Mastery Breakdown', ['class' => 'fw-bold mb-3']);
    echo html_writer::start_div('row g-3 mb-4');

    foreach ($domains as $d) {
        $badgeclass = match ($d['status']) {
            'mastered' => 'bg-success',
            'proficient' => 'bg-primary',
            default => 'bg-warning text-dark',
        };
        $statuslabel = match ($d['status']) {
            'mastered' => 'Mastered (80%+)',
            'proficient' => 'Proficient (60-79%)',
            default => 'Needs Practice (<60%)',
        };

        echo html_writer::start_div('col-md-6');
        echo html_writer::start_div('card p-3 shadow-sm h-100 border');
        echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
        echo html_writer::tag('span', $d['label'], ['class' => 'fw-bold text-dark']);
        echo html_writer::tag('span', $statuslabel, ['class' => 'badge ' . $badgeclass]);
        echo html_writer::end_div();

        $progressbar = html_writer::div('', 'progress-bar ' . $badgeclass, [
            'style' => 'width: ' . $d['percent'] . '%;',
            'role' => 'progressbar',
            'aria-valuenow' => $d['percent'],
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
        ]);
        echo html_writer::div($progressbar, 'progress mb-2', ['style' => 'height: 10px;']);

        echo html_writer::start_div('d-flex justify-content-between small text-muted');
        echo html_writer::tag('span', $d['correct'] . ' of ' . $d['total'] . ' correct');
        echo html_writer::tag('span', $d['percent'] . '% Accuracy', ['class' => 'fw-bold']);
        echo html_writer::end_div();

        echo html_writer::end_div();
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
}

// Action Bar.
echo html_writer::start_div('d-flex justify-content-between align-items-center mt-4 pt-3 border-top');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary px-4']);

echo html_writer::start_div('d-flex gap-2');
echo html_writer::link(new moodle_url('/mod/sat/review.php', ['attemptid' => $attempt->id]), 'Question Review', ['class' => 'btn btn-outline-primary']);
echo html_writer::tag('button', '<i class="fa fa-print me-1"></i> Print Score Report', [
    'class' => 'btn btn-primary',
    'onclick' => 'window.print();',
]);
echo html_writer::end_div();

echo html_writer::end_div();

echo $OUTPUT->footer();
