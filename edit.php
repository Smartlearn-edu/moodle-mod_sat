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
 * Question management page for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = required_param('cmid', PARAM_INT);
$selectedmoduleid = optional_param('modid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$slotid = optional_param('slotid', 0, PARAM_INT);

$cm = get_coursemodule_from_id('sat', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:manage', $context);

// Process delete action.
if ($action === 'delete' && $slotid && confirm_sesskey()) {
    \mod_sat\local\structure_manager::delete_slot($slotid);
    redirect(new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $selectedmoduleid]));
}

// Process move up / down actions.
if (($action === 'moveup' || $action === 'movedown') && $slotid && confirm_sesskey()) {
    $dir = ($action === 'moveup') ? 'up' : 'down';
    \mod_sat\local\structure_manager::move_slot($slotid, $dir);
    redirect(new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $selectedmoduleid]));
}

// Process toggle scored action.
if ($action === 'togglescored' && $slotid && confirm_sesskey()) {
    \mod_sat\local\structure_manager::toggle_slot_scored($slotid);
    redirect(new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $selectedmoduleid]));
}

// Process bulk add from Question Bank selector.
if ($action === 'addbulk' && confirm_sesskey()) {
    $qentryids = optional_param_array('qentryids', [], PARAM_INT);
    $targetmodid = required_param('targetmodid', PARAM_INT);
    $isscored = optional_param('isscored', 1, PARAM_INT);

    if (!empty($qentryids)) {
        $bankquestions = \mod_sat\local\structure_manager::get_available_bank_questions($context->id);
        foreach ($qentryids as $qentryid) {
            $isspr = false;
            if (isset($bankquestions[$qentryid])) {
                $qtype = $bankquestions[$qentryid]->qtype;
                $isspr = in_array($qtype, ['shortanswer', 'numerical'], true);
            }
            \mod_sat\local\structure_manager::add_fixed_slot(
                $sat->id,
                $targetmodid,
                $context->id,
                (int)$qentryid,
                1.0,
                (bool)$isscored,
                $isspr
            );
        }
    }

    redirect(new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $targetmodid]));
}

// Process manual single question add action.
if ($action === 'add' && confirm_sesskey()) {
    $qbankentryid = required_param('qentryid', PARAM_INT);
    $isspr = optional_param('isspr', 0, PARAM_INT);
    $isscored = optional_param('isscored', 1, PARAM_INT);
    $targetmodid = required_param('targetmodid', PARAM_INT);

    \mod_sat\local\structure_manager::add_fixed_slot(
        $sat->id,
        $targetmodid,
        $context->id,
        $qbankentryid,
        1.0,
        (bool)$isscored,
        (bool)$isspr
    );
    redirect(new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $targetmodid]));
}

$modules = \mod_sat\local\structure_manager::get_modules($sat->id);
if (empty($modules)) {
    // If not provisioned yet, provision now.
    sat_provision_default_modules($sat);
    $modules = \mod_sat\local\structure_manager::get_modules($sat->id);
}

if (!$selectedmoduleid && !empty($modules)) {
    $firstmod = reset($modules);
    $selectedmoduleid = $firstmod->id;
}

$PAGE->set_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $selectedmoduleid]);
$PAGE->set_title(get_string('managequestions', 'mod_sat') . ': ' . format_string($sat->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managequestions', 'mod_sat') . ' — ' . format_string($sat->name));

// Render module tabs.
echo html_writer::start_tag('ul', ['class' => 'nav nav-pills mb-4 gap-2']);
foreach ($modules as $m) {
    $isactive = ($m->id == $selectedmoduleid);
    $stagestr = get_string('stage_' . $m->stage, 'mod_sat');
    $secstr = strtoupper($m->section);
    $label = "$secstr: $stagestr";

    $link = new moodle_url('/mod/sat/edit.php', ['cmid' => $cmid, 'modid' => $m->id]);
    $btnclass = $isactive ? 'nav-link active' : 'nav-link';
    echo html_writer::tag('li', html_writer::link($link, $label, ['class' => $btnclass]), ['class' => 'nav-item']);
}
echo html_writer::end_tag('ul');

// Render slots table for current module.
$currentmod = $modules[$selectedmoduleid] ?? reset($modules);
if ($currentmod) {
    $slots = \mod_sat\local\structure_manager::get_slots($currentmod->id);
    $count = count($slots);
    $targetcount = $currentmod->questioncount;

    echo html_writer::start_div('alert alert-info d-flex justify-content-between align-items-center mb-3');
    echo html_writer::tag('span', "Total Questions in this module: <strong>$count / $targetcount</strong>");
    echo html_writer::start_div('d-flex gap-2');
    $qbankurl = new moodle_url('/question/edit.php', ['courseid' => $course->id]);
    echo html_writer::link($qbankurl, 'Open Question Bank', ['class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank']);
    $backurl = new moodle_url('/mod/sat/view.php', ['id' => $cmid]);
    echo html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-sm btn-outline-dark']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    $existingentries = [];
    if (empty($slots)) {
        echo $OUTPUT->notification(get_string('noslotsinmodule', 'mod_sat'), 'info');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('slot', 'mod_sat'),
            get_string('questionname', 'mod_sat'),
            get_string('qtype', 'mod_sat'),
            get_string('scored', 'mod_sat'),
            get_string('actions'),
        ];
        $table->attributes['class'] = 'table table-bordered table-striped align-middle';

        $slotlist = array_values($slots);
        $slotcount = count($slotlist);

        foreach ($slotlist as $idx => $s) {
            if (!empty($s->questionbankentryid)) {
                $existingentries[(int)$s->questionbankentryid] = true;
            }

            $upurl = new moodle_url('/mod/sat/edit.php', [
                'cmid' => $cmid,
                'modid' => $currentmod->id,
                'action' => 'moveup',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $downurl = new moodle_url('/mod/sat/edit.php', [
                'cmid' => $cmid,
                'modid' => $currentmod->id,
                'action' => 'movedown',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $toggleurl = new moodle_url('/mod/sat/edit.php', [
                'cmid' => $cmid,
                'modid' => $currentmod->id,
                'action' => 'togglescored',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $deleteurl = new moodle_url('/mod/sat/edit.php', [
                'cmid' => $cmid,
                'modid' => $currentmod->id,
                'action' => 'delete',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);

            $actionbuttons = [];
            if ($idx > 0) {
                $actionbuttons[] = html_writer::link($upurl, '↑', [
                    'class' => 'btn btn-sm btn-outline-secondary',
                    'title' => 'Move up',
                ]);
            }
            if ($idx < $slotcount - 1) {
                $actionbuttons[] = html_writer::link($downurl, '↓', [
                    'class' => 'btn btn-sm btn-outline-secondary',
                    'title' => 'Move down',
                ]);
            }
            $togglelabel = $s->is_scored ? 'Set Pretest' : 'Set Scored';
            $actionbuttons[] = html_writer::link($toggleurl, $togglelabel, [
                'class' => 'btn btn-sm btn-outline-info',
            ]);
            $actionbuttons[] = html_writer::link($deleteurl, get_string('delete'), [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("' . get_string('confirmdeleteslot', 'mod_sat') . '");',
            ]);

            $qname = format_string($s->questionname ?? ('[' . get_string('missingquestion', 'mod_sat') . ']'));
            $typestr = $s->is_spr ? 'Math SPR (Free Response)' : ($s->qtype ?? 'MCQ');
            $scoredstr = $s->is_scored
                ? '<span class="badge bg-success">Scored</span>'
                : '<span class="badge bg-secondary">Pretest (Unscored)</span>';

            // Split preview.
            if (!empty($s->questiontext)) {
                [$stimulus, $prompt] = \mod_sat\local\structure_manager::split_stimulus($s->questiontext);
                if ($stimulus) {
                    $qname .= '<br><small class="text-muted"><span class="badge bg-info text-dark">Split Stimulus</span> '
                        . shorten_text(strip_tags($stimulus), 60) . '</small>';
                }
            }

            $table->data[] = [
                $s->slot,
                $qname,
                $typestr,
                $scoredstr,
                html_writer::div(implode(' ', $actionbuttons), 'd-flex flex-wrap gap-1'),
            ];
        }

        echo html_writer::table($table);
    }

    // Visual Question Bank Picker (Bulk Add).
    $bankquestions = \mod_sat\local\structure_manager::get_available_bank_questions($context->id);

    echo html_writer::start_div('card p-3 mt-4 shadow-sm border');
    echo html_writer::tag('h5', 'Select Questions from Course Question Bank', ['class' => 'card-title mb-3']);

    if (empty($bankquestions)) {
        echo $OUTPUT->notification('No ready questions found in this course Question Bank yet.', 'warning');
    } else {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => new moodle_url('/mod/sat/edit.php'),
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetmodid', 'value' => $currentmod->id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addbulk']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        $picker = new html_table();
        $picker->head = [
            'Select',
            'Category',
            get_string('questionname', 'mod_sat'),
            get_string('qtype', 'mod_sat'),
            'Entry ID',
        ];
        $picker->attributes['class'] = 'table table-sm table-hover align-middle';

        foreach ($bankquestions as $bq) {
            $alreadyadded = isset($existingentries[(int)$bq->qbankentryid]);
            $checkbox = html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'qentryids[]',
                'value' => $bq->qbankentryid,
                'class' => 'form-check-input',
            ]);
            if ($alreadyadded) {
                $checkbox .= ' <span class="badge bg-secondary ms-1">In module</span>';
            }
            $issprtype = in_array($bq->qtype, ['shortanswer', 'numerical'], true);
            $typelabel = $issprtype ? ($bq->qtype . ' (Auto SPR)') : $bq->qtype;

            $picker->data[] = [
                $checkbox,
                format_string($bq->categoryname),
                format_string($bq->name),
                $typelabel,
                (int)$bq->qbankentryid,
            ];
        }

        echo html_writer::div(html_writer::table($picker), '', ['style' => 'max-height: 360px; overflow-y: auto;']);

        echo html_writer::start_div('d-flex justify-content-between align-items-center mt-3 pt-2 border-top');
        echo html_writer::start_div('form-check');
        echo html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'isscored',
            'value' => '1',
            'checked' => 'checked',
            'id' => 'check_bulk_scored',
            'class' => 'form-check-input',
        ]);
        echo html_writer::tag('label', 'Mark selected slots as Scored (uncheck for Pretest)', [
            'for' => 'check_bulk_scored',
            'class' => 'form-check-label',
        ]);
        echo html_writer::end_div();

        echo html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => 'Add Selected Questions to Module',
            'class' => 'btn btn-primary',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_tag('form');
    }
    echo html_writer::end_div();

    // Fallback Quick Add Question by Entry ID form.
    echo html_writer::start_tag('details', ['class' => 'card p-3 mt-3 bg-light']);
    echo html_writer::tag('summary', get_string('addquestionfrombank', 'mod_sat') . ' (Manual Entry ID)', [
        'class' => 'fw-semibold text-secondary',
        'style' => 'cursor: pointer;',
    ]);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/sat/edit.php'),
        'class' => 'row g-3 align-items-center mt-2',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetmodid', 'value' => $currentmod->id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'add']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_div('col-auto');
    echo html_writer::tag('label', get_string('qbankentryid', 'mod_sat'), ['class' => 'form-label me-2']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'qentryid',
        'class' => 'form-control',
        'placeholder' => 'Question Entry ID',
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::start_div('col-auto');
    echo html_writer::start_div('form-check');
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'isspr',
        'value' => '1',
        'id' => 'check_isspr',
        'class' => 'form-check-input',
    ]);
    echo html_writer::tag('label', 'Math SPR (Free Response)', ['for' => 'check_isspr', 'class' => 'form-check-label']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    echo html_writer::start_div('col-auto');
    echo html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('addslot', 'mod_sat'),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo html_writer::end_tag('details');
}

echo $OUTPUT->footer();
