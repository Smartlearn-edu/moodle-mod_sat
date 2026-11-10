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

// Process add question action.
if ($action === 'add' && confirm_sesskey()) {
    $qbankentryid = required_param('qentryid', PARAM_INT);
    $isspr = optional_param('isspr', 0, PARAM_INT);
    $isscored = optional_param('isscored', 1, PARAM_INT);
    $targetmodid = required_param('targetmodid', PARAM_INT);

    \mod_sat\local\structure_manager::add_fixed_slot($sat->id, $targetmodid, $context->id, $qbankentryid, 1.0, (bool)$isscored, (bool)$isspr);
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
    echo html_writer::link(new moodle_url('/mod/sat/view.php', ['id' => $cmid]), get_string('back'), ['class' => 'btn btn-sm btn-outline-dark']);
    echo html_writer::end_div();

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

        foreach ($slots as $s) {
            $deleteurl = new moodle_url('/mod/sat/edit.php', [
                'cmid' => $cmid,
                'modid' => $currentmod->id,
                'action' => 'delete',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $deletelink = html_writer::link($deleteurl, get_string('delete'), [
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("' . get_string('confirmdeleteslot', 'mod_sat') . '");',
            ]);

            $qname = $s->questionname ?? ('[' . get_string('missingquestion', 'mod_sat') . ']');
            $typestr = $s->is_spr ? 'Math SPR (Free Response)' : ($s->qtype ?? 'MCQ');
            $scoredstr = $s->is_scored ? '<span class="badge bg-success">Scored</span>' : '<span class="badge bg-secondary">Pretest (Unscored)</span>';

            // Split preview:
            if (!empty($s->questiontext)) {
                [$stimulus, $prompt] = \mod_sat\local\structure_manager::split_stimulus($s->questiontext);
                if ($stimulus) {
                    $qname .= '<br><small class="text-muted"><span class="badge bg-info text-dark">Split Stimulus Detected</span> ' . shorten_text(strip_tags($stimulus), 60) . '</small>';
                }
            }

            $table->data[] = [
                $s->slot,
                $qname,
                $typestr,
                $scoredstr,
                $deletelink,
            ];
        }

        echo html_writer::table($table);
    }

    // Quick Add Question form.
    echo html_writer::start_div('card p-3 mt-4 bg-light');
    echo html_writer::tag('h6', get_string('addquestionfrombank', 'mod_sat'), ['class' => 'card-title text-secondary']);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/sat/edit.php'),
        'class' => 'row g-3 align-items-center',
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
        'class' => 'btn btn-primary',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
