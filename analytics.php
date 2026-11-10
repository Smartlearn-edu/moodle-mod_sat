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
 * Teacher cohort analytics dashboard for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.

$cm = get_coursemodule_from_id('sat', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:viewreports', $context);

$PAGE->set_url('/mod/sat/analytics.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('cohort_analytics', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cohort_analytics', 'mod_sat') . ' — ' . format_string($sat->name));

$metrics = \mod_sat\local\diagnostic_manager::get_cohort_analytics($sat->id);
$distribution = \mod_sat\local\diagnostic_manager::get_cohort_score_distribution($sat->id);
$domains = \mod_sat\local\diagnostic_manager::get_cohort_domain_breakdown($sat->id);

if ($metrics['totalattempts'] === 0) {
    echo $OUTPUT->notification(get_string('noattempts', 'mod_sat'), 'info');
} else {
    // Top KPI Summary Cards.
    echo html_writer::start_div('row g-3 mb-4');

    // Total Attempts card.
    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card shadow-sm p-3 border-0 bg-light text-center h-100');
    echo html_writer::tag('small', get_string('totalattempts', 'mod_sat'), ['class' => 'text-muted text-uppercase fw-semibold']);
    echo html_writer::tag('h2', (string)$metrics['totalattempts'], ['class' => 'fw-bold my-2 text-dark']);
    echo html_writer::tag('small', 'Completed exam submissions', ['class' => 'text-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Average Total Score card.
    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card shadow-sm p-3 border-0 bg-light text-center h-100');
    echo html_writer::tag('small', get_string('averagetotal', 'mod_sat'), ['class' => 'text-muted text-uppercase fw-semibold']);
    echo html_writer::tag('h2', (string)$metrics['averagetotal'], ['class' => 'fw-bold my-2 text-primary']);
    echo html_writer::tag('small', 'Range: ' . $metrics['lowesttotal'] . ' - ' . $metrics['highesttotal'], ['class' => 'text-secondary']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    // RW & Math Averages card.
    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card shadow-sm p-3 border-0 bg-light text-center h-100');
    echo html_writer::tag('small', 'Section Averages', ['class' => 'text-muted text-uppercase fw-semibold']);
    echo html_writer::start_div('d-flex justify-content-around my-2');
    echo html_writer::start_div();
    echo html_writer::tag('div', (string)$metrics['averagerw'], ['class' => 'fs-3 fw-bold text-info']);
    echo html_writer::tag('small', 'RW (800)', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::start_div();
    echo html_writer::tag('div', (string)$metrics['averagemath'], ['class' => 'fs-3 fw-bold text-success']);
    echo html_writer::tag('small', 'Math (800)', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    // Hard Routing Rate card.
    echo html_writer::start_div('col-md-3 col-sm-6');
    echo html_writer::start_div('card shadow-sm p-3 border-0 bg-light text-center h-100');
    echo html_writer::tag('small', 'Hard Module 2 Route Rate', ['class' => 'text-muted text-uppercase fw-semibold']);
    echo html_writer::start_div('d-flex justify-content-around my-2');
    echo html_writer::start_div();
    echo html_writer::tag('div', $metrics['rwhardrate'] . '%', ['class' => 'fs-3 fw-bold text-info']);
    echo html_writer::tag('small', 'RW Hard', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::start_div();
    echo html_writer::tag('div', $metrics['mathhardrate'] . '%', ['class' => 'fs-3 fw-bold text-success']);
    echo html_writer::tag('small', 'Math Hard', ['class' => 'text-muted']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // End KPI row.

    // Second Row: Score Distribution & Routing Breakdown.
    echo html_writer::start_div('row g-4 mb-4');

    // Score Distribution.
    echo html_writer::start_div('col-md-7');
    echo html_writer::start_div('card shadow-sm p-4 border h-100');
    echo html_writer::tag('h5', get_string('score_distribution', 'mod_sat'), ['class' => 'fw-bold mb-3']);

    foreach ($distribution as $item) {
        echo html_writer::start_div('mb-3');
        echo html_writer::start_div('d-flex justify-content-between small mb-1');
        echo html_writer::tag('span', 'Score ' . $item['range'], ['class' => 'fw-semibold text-dark']);
        echo html_writer::tag('span', $item['count'] . ' students (' . $item['percent'] . '%)', ['class' => 'text-muted']);
        echo html_writer::end_div();

        $barclass = match ($item['range']) {
            '1400-1600' => 'bg-success',
            '1200-1390' => 'bg-primary',
            '1000-1190' => 'bg-info',
            '800-990' => 'bg-warning',
            default => 'bg-danger',
        };

        $progressbar = html_writer::div('', 'progress-bar ' . $barclass, [
            'style' => 'width: ' . $item['percent'] . '%;',
            'role' => 'progressbar',
            'aria-valuenow' => $item['percent'],
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
        ]);
        echo html_writer::div($progressbar, 'progress', ['style' => 'height: 12px;']);
        echo html_writer::end_div();
    }

    echo html_writer::end_div();
    echo html_writer::end_div();

    // Adaptive Routing Breakdown.
    echo html_writer::start_div('col-md-5');
    echo html_writer::start_div('card shadow-sm p-4 border h-100');
    echo html_writer::tag('h5', get_string('routing_breakdown', 'mod_sat'), ['class' => 'fw-bold mb-3']);

    // RW routing visual.
    echo html_writer::tag('h6', 'Reading & Writing Adaptive Route', ['class' => 'text-muted small text-uppercase mb-2']);
    echo html_writer::start_div('d-flex justify-content-between small mb-1');
    echo html_writer::tag('span', 'Module 2 Hard (Advanced)', ['class' => 'text-success fw-semibold']);
    echo html_writer::tag('span', $metrics['rwhardrate'] . '%', ['class' => 'fw-bold text-success']);
    echo html_writer::end_div();
    $rweasyrate = 100 - $metrics['rwhardrate'];
    echo html_writer::start_div('d-flex justify-content-between small mb-2');
    echo html_writer::tag('span', 'Module 2 Easy (Standard)', ['class' => 'text-secondary']);
    echo html_writer::tag('span', $rweasyrate . '%', ['class' => 'text-secondary']);
    echo html_writer::end_div();

    $rwprogbar = html_writer::div('', 'progress-bar bg-success', ['style' => 'width: ' . $metrics['rwhardrate'] . '%;']) .
                 html_writer::div('', 'progress-bar bg-secondary', ['style' => 'width: ' . $rweasyrate . '%;']);
    echo html_writer::div($rwprogbar, 'progress mb-4', ['style' => 'height: 14px;']);

    // Math routing visual.
    echo html_writer::tag('h6', 'Math Adaptive Route', ['class' => 'text-muted small text-uppercase mb-2']);
    echo html_writer::start_div('d-flex justify-content-between small mb-1');
    echo html_writer::tag('span', 'Module 2 Hard (Advanced)', ['class' => 'text-primary fw-semibold']);
    echo html_writer::tag('span', $metrics['mathhardrate'] . '%', ['class' => 'fw-bold text-primary']);
    echo html_writer::end_div();
    $matheasyrate = 100 - $metrics['mathhardrate'];
    echo html_writer::start_div('d-flex justify-content-between small mb-2');
    echo html_writer::tag('span', 'Module 2 Easy (Standard)', ['class' => 'text-secondary']);
    echo html_writer::tag('span', $matheasyrate . '%', ['class' => 'text-secondary']);
    echo html_writer::end_div();

    $mathprogbar = html_writer::div('', 'progress-bar bg-primary', ['style' => 'width: ' . $metrics['mathhardrate'] . '%;']) .
                   html_writer::div('', 'progress-bar bg-secondary', ['style' => 'width: ' . $matheasyrate . '%;']);
    echo html_writer::div($mathprogbar, 'progress', ['style' => 'height: 14px;']);

    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::end_div(); // End second row.

    // Third Section: Domain Mastery Heatmap.
    if (!empty($domains)) {
        echo html_writer::start_div('card shadow-sm p-4 border mb-4');
        echo html_writer::tag('h5', get_string('domain_heatmap', 'mod_sat'), ['class' => 'fw-bold mb-3']);
        echo html_writer::start_div('row g-3');

        foreach ($domains as $d) {
            $badgeclass = 'bg-danger';
            $badgetext = 'Needs Practice (<60%)';
            if ($d['percent'] >= 80) {
                $badgeclass = 'bg-success';
                $badgetext = 'Mastered (80%+)';
            } else if ($d['percent'] >= 60) {
                $badgeclass = 'bg-primary';
                $badgetext = 'Proficient (60-79%)';
            }

            echo html_writer::start_div('col-md-6');
            echo html_writer::start_div('p-3 border rounded bg-light');
            echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
            echo html_writer::tag('span', $d['label'], ['class' => 'fw-bold text-dark']);
            echo html_writer::tag('span', $badgetext, ['class' => 'badge ' . $badgeclass]);
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
            echo html_writer::tag('span', $d['correct'] . ' of ' . $d['total'] . ' items correct across cohort');
            echo html_writer::tag('span', $d['percent'] . '% Accuracy', ['class' => 'fw-semibold text-dark']);
            echo html_writer::end_div();

            echo html_writer::end_div();
            echo html_writer::end_div();
        }

        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

// Action Bar.
echo html_writer::start_div('d-flex justify-content-between align-items-center mt-4 pt-3 border-top');
echo html_writer::start_div('d-flex gap-2');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary px-4']);
echo html_writer::link(new moodle_url('/mod/sat/report.php', ['id' => $cm->id]), get_string('viewreports', 'mod_sat'), ['class' => 'btn btn-outline-info']);
echo html_writer::end_div();

echo html_writer::tag('button', '<i class="fa fa-print me-1"></i> Print Analytics Report', [
    'class' => 'btn btn-primary',
    'onclick' => 'window.print();',
]);
echo html_writer::end_div();

echo $OUTPUT->footer();
