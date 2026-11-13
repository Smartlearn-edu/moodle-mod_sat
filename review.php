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

// Question-by-question review from saved SAT slot state, with Question Engine fallback.
$modattempts = $DB->get_records('sat_module_attempts', ['attemptid' => $attempt->id], 'id ASC');

if (!empty($modattempts)) {
    $slotstates = $DB->get_records(
        'sat_slot_state',
        ['attemptid' => $attempt->id],
        '',
        'slot, response, flagged'
    );
    $modules = $DB->get_records('sat_modules', ['satid' => $attempt->satid]);

    echo html_writer::tag('h5', 'Question Review', ['class' => 'mb-3 fw-bold']);

    foreach ($modattempts as $modattempt) {
        $modinfo = $modules[$modattempt->moduleid] ?? null;
        $sectionlabel = ($modinfo && $modinfo->section === 'math') ? 'Math' : 'Reading and Writing';
        $modnum = $modinfo ? (int)$modinfo->moduleorder : 1;
        $routelabel = ($modinfo && !empty($modinfo->difficulty) && $modinfo->difficulty !== 'routing')
            ? ' (' . ucfirst($modinfo->difficulty) . ')'
            : '';

        echo html_writer::tag(
            'h6',
            s($sectionlabel . ' — Module ' . $modnum . $routelabel),
            ['class' => 'mt-4 mb-2 text-uppercase text-muted fw-bold']
        );

        $slots = \mod_sat\local\structure_manager::get_slots((int)$modattempt->moduleid);
        $usageslot = (int)$modattempt->firstslot;

        foreach ($slots as $slot) {
            $state = $slotstates[$usageslot] ?? null;
            $studentresponse = $state ? trim((string)($state->response ?? '')) : '';
            $isflagged = $state && !empty($state->flagged);
            $earned = \mod_sat\local\attempt_manager::grade_slot_response($slot, $studentresponse);
            $maxmark = (float)$slot->maxmark;
            $iscorrect = ($maxmark > 0 && ($earned / $maxmark) >= 0.99);

            $answers = $DB->get_records('question_answers', ['question' => $slot->questionid], 'id ASC');
            $correcttexts = [];
            $studentdisplay = $studentresponse;

            if (empty($slot->is_spr)) {
                $idx = 0;
                foreach ($answers as $ans) {
                    $letter = chr(65 + $idx);
                    $cleanans = trim(html_to_text((string)$ans->answer, 0, false));
                    if ((float)$ans->fraction >= 0.99) {
                        $correcttexts[] = $letter . '. ' . $cleanans;
                    }
                    if ($studentresponse !== '' && strtoupper($studentresponse) === $letter) {
                        $studentdisplay = $letter . '. ' . $cleanans;
                    }
                    $idx++;
                }
            } else {
                foreach ($answers as $ans) {
                    if ((float)$ans->fraction >= 0.99) {
                        $correcttexts[] = trim(html_to_text((string)$ans->answer, 0, false));
                    }
                }
            }

            if ($studentdisplay === '') {
                $studentdisplay = 'No answer submitted';
            }
            $correctdisplay = !empty($correcttexts) ? implode(' / ', $correcttexts) : 'N/A';

            $questionrec = $DB->get_record(
                'question',
                ['id' => $slot->questionid],
                'id, generalfeedback, generalfeedbackformat'
            );

            [$stimulus, $prompt] = \mod_sat\local\structure_manager::split_stimulus((string)$slot->questiontext);
            $qformat = (int)$slot->questiontextformat;

            $borderclass = $iscorrect ? 'border-success' : 'border-danger';
            echo html_writer::start_div('sat-review-question-card card mb-3 p-3 shadow-sm border-start border-4 ' . $borderclass);

            echo html_writer::start_div('d-flex justify-content-between align-items-center mb-2');
            echo html_writer::tag('span', 'Question ' . (int)$slot->slot, ['class' => 'fw-bold']);
            echo html_writer::start_div('d-flex gap-2 align-items-center');
            if ($isflagged) {
                echo html_writer::tag('span', 'Flagged', ['class' => 'badge bg-warning text-dark']);
            }
            $badgeclass = $iscorrect ? 'badge bg-success' : 'badge bg-danger';
            $statustext = ($iscorrect ? 'Correct' : 'Incorrect') . ' (' . round($earned, 2) . '/' . round($maxmark, 2) . ')';
            echo html_writer::tag('span', $statustext, ['class' => $badgeclass]);
            echo html_writer::end_div();
            echo html_writer::end_div();

            if (trim($stimulus) !== '') {
                echo html_writer::div(format_text($stimulus, $qformat), 'p-2 mb-2 bg-light rounded small');
            }
            echo html_writer::div(format_text($prompt, $qformat), 'mb-3');

            echo html_writer::start_div('row g-2 small mb-2');
            echo html_writer::div(
                html_writer::tag('strong', 'Your Answer: ') . s($studentdisplay),
                'col-md-6'
            );
            echo html_writer::div(
                html_writer::tag('strong', 'Correct Answer: ') . s($correctdisplay),
                'col-md-6 text-success'
            );
            echo html_writer::end_div();

            if ($questionrec && trim((string)$questionrec->generalfeedback) !== '') {
                echo html_writer::div(
                    html_writer::tag('strong', 'Explanation: ', ['class' => 'd-block mb-1'])
                    . format_text($questionrec->generalfeedback, (int)$questionrec->generalfeedbackformat),
                    'alert alert-info mb-0 mt-2 py-2 small'
                );
            }

            echo html_writer::end_div();
            $usageslot++;
        }
    }
} else if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
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

echo html_writer::start_div('mt-4 d-flex gap-2');
echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cm->id]), get_string('back'), ['class' => 'btn btn-secondary px-4']);
echo html_writer::link(
    new moodle_url('/mod/sat/diagnostic.php', ['id' => $cm->id, 'attemptid' => $attempt->id]),
    'View Domain Diagnostics',
    ['class' => 'btn btn-outline-primary px-4']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
