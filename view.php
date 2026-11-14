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
 * Main view page for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID.
$s  = optional_param('s', 0, PARAM_INT);  // SAT Instance ID.

if ($id) {
    $cm         = get_coursemodule_from_id('sat', $id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $sat        = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($s) {
    $sat        = $DB->get_record('sat', ['id' => $s], '*', MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $sat->course], '*', MUST_EXIST);
    $cm         = get_coursemodule_from_instance('sat', $sat->id, $course->id, false, MUST_EXIST);
} else {
    throw new \moodle_exception('missingparameter');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:view', $context);

$canmanage = has_capability('mod/sat:manage', $context);
$canattempt = has_capability('mod/sat:attempt', $context);
$canpreview = get_capability_info('mod/sat:preview') ? has_capability('mod/sat:preview', $context) : $canmanage;

// Handle teacher preview reset action before output begins.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'resetpreview' && ($canpreview || $canmanage) && confirm_sesskey()) {
    \mod_sat\local\attempt_manager::delete_preview_attempts((int)$sat->id, (int)$USER->id);
    redirect(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]));
}

// Trigger course_module_viewed event.
$event = \mod_sat\event\course_module_viewed::create([
    'objectid' => $sat->id,
    'context' => $context,
]);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('sat', $sat);
$event->trigger();

// Completion.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/sat/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($sat->name));

if (trim(strip_tags($sat->intro))) {
    echo $OUTPUT->box(format_module_intro('sat', $sat, $cm->id), 'generalbox mod_introbox', 'intro');
}

// Display exam information card using effective user/group override settings.
$now = time();
$effective = \mod_sat\local\attempt_manager::get_effective_settings($sat, $USER->id);
$infobox = html_writer::start_div('sat-exam-info card p-3 mb-4 shadow-sm');
$infobox .= html_writer::tag('h5', get_string('examdetails', 'mod_sat'), ['class' => 'card-title text-primary']);
$infobox .= html_writer::start_tag('ul', ['class' => 'list-unstyled mb-0']);

$scopestr = get_string('scope_' . $sat->exam_scope, 'mod_sat');
$infobox .= html_writer::tag(
    'li',
    html_writer::tag('strong', get_string('exam_scope', 'mod_sat') . ': ') . $scopestr,
    ['class' => 'py-1']
);

$pacingstr = get_string('pacing_' . $sat->pacing_mode, 'mod_sat');
$infobox .= html_writer::tag(
    'li',
    html_writer::tag('strong', get_string('pacing_mode', 'mod_sat') . ': ') . $pacingstr,
    ['class' => 'py-1']
);

if (!empty($effective->timeopen)) {
    $infobox .= html_writer::tag(
        'li',
        html_writer::tag('strong', get_string('timeopen', 'mod_sat') . ': ') . userdate($effective->timeopen),
        ['class' => 'py-1']
    );
}
if (!empty($effective->timeclose)) {
    $infobox .= html_writer::tag(
        'li',
        html_writer::tag('strong', get_string('timeclose', 'mod_sat') . ': ') . userdate($effective->timeclose),
        ['class' => 'py-1']
    );
}

$attemptslimit = $effective->attempts ? $effective->attempts : get_string('unlimited');
$infobox .= html_writer::tag(
    'li',
    html_writer::tag('strong', get_string('attemptsallowed', 'mod_sat') . ': ') . $attemptslimit,
    ['class' => 'py-1']
);

if ((float)$effective->timemultiplier > 1.0) {
    $infobox .= html_writer::tag(
        'li',
        html_writer::tag(
            'span',
            get_string('accommodation_badge', 'mod_sat', $effective->timemultiplier),
            ['class' => 'badge bg-info text-dark']
        ),
        ['class' => 'py-1']
    );
}

$infobox .= html_writer::end_tag('ul');
$infobox .= html_writer::end_div();
echo $infobox;

// Teacher management links.
if ($canmanage || has_capability('mod/sat:viewreports', $context)) {
    echo html_writer::start_div('mb-4 d-flex flex-wrap gap-2');
    if ($canmanage) {
        echo html_writer::link(new moodle_url('/mod/sat/edit.php', ['cmid' => $cm->id]), get_string('managequestions', 'mod_sat'), ['class' => 'btn btn-outline-secondary']);
    }
    if (has_capability('mod/sat:viewreports', $context)) {
        echo html_writer::link(new moodle_url('/mod/sat/report.php', ['id' => $cm->id]), get_string('viewreports', 'mod_sat'), ['class' => 'btn btn-outline-info']);
        echo html_writer::link(new moodle_url('/mod/sat/analytics.php', ['id' => $cm->id]), get_string('cohort_analytics', 'mod_sat'), ['class' => 'btn btn-outline-primary']);
    }
    if ($canmanage) {
        echo html_writer::link(new moodle_url('/mod/sat/overrides.php', ['cmid' => $cm->id]), get_string('overrides', 'mod_sat'), ['class' => 'btn btn-outline-warning']);
    }
    echo html_writer::end_div();
}

// Check student's past attempts.
$attempts = $DB->get_records('sat_attempts', ['satid' => $sat->id, 'userid' => $USER->id], 'attempt ASC');
$activeattempt = null;
$haspreview = false;

if (!empty($attempts)) {
    echo html_writer::tag('h5', get_string('summaryofattempts', 'mod_sat'), ['class' => 'mt-4']);
    $table = new html_table();
    $table->head = [
        get_string('attempt', 'mod_sat'),
        get_string('state', 'mod_sat'),
        get_string('timecompleted', 'mod_sat'),
        get_string('scaled_rw', 'mod_sat'),
        get_string('scaled_math', 'mod_sat'),
        get_string('scaled_total', 'mod_sat'),
        get_string('action', 'mod_sat'),
    ];
    $table->attributes['class'] = 'table table-striped table-hover';

    foreach ($attempts as $att) {
        if ($att->state === 'inprogress' || $att->state === 'paused') {
            $activeattempt = $att;
        }
        if (!empty($att->preview)) {
            $haspreview = true;
        }

        $completedstr = $att->timefinish ? userdate($att->timefinish) : '-';
        $statestr = get_string('state_' . $att->state, 'mod_sat');

        $rwstr = $att->scaled_rw !== null ? (string)$att->scaled_rw : '-';
        $mathstr = $att->scaled_math !== null ? (string)$att->scaled_math : '-';
        $totalstr = $att->scaled_total !== null ? (string)$att->scaled_total : '-';

        $actionlink = '';
        if ($att->state === 'inprogress' || $att->state === 'paused') {
            $actionlink = html_writer::link(
                new moodle_url('/mod/sat/attempt.php', ['id' => $cm->id]),
                get_string('continueattempt', 'mod_sat'),
                ['class' => 'btn btn-sm btn-primary']
            );
        } else {
            $buttons = [];
            $buttons[] = html_writer::link(
                new moodle_url('/mod/sat/diagnostic.php', ['attemptid' => $att->id]),
                get_string('diagnostic_report', 'mod_sat'),
                ['class' => 'btn btn-sm btn-primary']
            );
            $buttons[] = html_writer::link(
                new moodle_url('/mod/sat/review.php', ['attemptid' => $att->id]),
                get_string('review', 'mod_sat'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            );
            $actionlink = implode(' ', $buttons);
        }

        $attlabel = (string)$att->attempt;
        if (!empty($att->preview)) {
            $attlabel .= ' ' . html_writer::tag(
                'span',
                get_string('preview_badge', 'mod_sat'),
                ['class' => 'badge bg-info text-dark ms-1']
            );
        }

        $table->data[] = [
            $attlabel,
            $statestr,
            $completedstr,
            $rwstr,
            $mathstr,
            $totalstr,
            $actionlink,
        ];
    }
    echo html_writer::table($table);
}

// Action button: Start / Resume / Preview.
[$canstart, $reasonkey] = \mod_sat\local\attempt_manager::can_start_or_resume_attempt(
    $sat,
    $USER->id,
    $context,
    false
);

echo html_writer::start_div('mt-4 text-center d-flex justify-content-center flex-wrap gap-3');

if ($activeattempt && $canstart) {
    // Resume current attempt.
    echo html_writer::link(
        new moodle_url('/mod/sat/attempt.php', ['id' => $cm->id]),
        get_string('continueattempt', 'mod_sat'),
        ['class' => 'btn btn-lg btn-success px-5 shadow']
    );
} else if (!$activeattempt && $canstart) {
    // Start new attempt.
    echo html_writer::link(
        new moodle_url('/mod/sat/attempt.php', ['id' => $cm->id]),
        get_string('startexam', 'mod_sat'),
        ['class' => 'btn btn-lg btn-primary px-5 shadow']
    );
} else if ($canpreview) {
    // Teacher preview.
    echo html_writer::link(
        new moodle_url('/mod/sat/attempt.php', ['id' => $cm->id, 'preview' => 1]),
        get_string('previewexam', 'mod_sat'),
        ['class' => 'btn btn-lg btn-info px-5 text-white shadow']
    );
} else if ($reasonkey === 'nomoreattempts') {
    echo $OUTPUT->notification(get_string('nomoreattempts', 'mod_sat'), 'info');
} else {
    echo $OUTPUT->notification(get_string('examnotopen', 'mod_sat'), 'warning');
}

if ($haspreview && ($canpreview || $canmanage)) {
    $reseturl = new moodle_url('/mod/sat/view.php', [
        'id' => $cm->id,
        'action' => 'resetpreview',
        'sesskey' => sesskey(),
    ]);
    echo html_writer::link(
        $reseturl,
        get_string('resetpreview', 'mod_sat'),
        ['class' => 'btn btn-lg btn-outline-danger px-4']
    );
}

echo html_writer::end_div();

echo $OUTPUT->footer();
