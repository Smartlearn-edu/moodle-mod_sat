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
$bankcontextid = optional_param('bankcontextid', 0, PARAM_INT);
$catid = optional_param('catid', 0, PARAM_INT);
$recurse = optional_param('recurse', 1, PARAM_INT);
$qsearch = trim(optional_param('qsearch', '', PARAM_TEXT));
$qtypefilter = optional_param('qtypefilter', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('sat', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/sat:manage', $context);

$buildediturl = static function (
    int $modid,
    array $extra = [],
    string $anchor = ''
) use (
    $cmid,
    $bankcontextid,
    $catid,
    $recurse,
    $qsearch,
    $qtypefilter
): moodle_url {
    $params = [
        'cmid' => $cmid,
        'modid' => $modid,
    ];
    if ($bankcontextid > 0) {
        $params['bankcontextid'] = $bankcontextid;
    }
    if ($catid > 0) {
        $params['catid'] = $catid;
    }
    if ((int)$recurse === 0) {
        $params['recurse'] = 0;
    }
    if ($qsearch !== '') {
        $params['qsearch'] = $qsearch;
    }
    if ($qtypefilter !== '') {
        $params['qtypefilter'] = $qtypefilter;
    }
    $params = array_merge($params, $extra);
    return new moodle_url('/mod/sat/edit.php', $params, $anchor);
};

// Process delete action.
if ($action === 'delete' && $slotid && confirm_sesskey()) {
    \mod_sat\local\structure_manager::delete_slot($slotid);
    redirect($buildediturl($selectedmoduleid));
}

// Process move up / down actions.
if (($action === 'moveup' || $action === 'movedown') && $slotid && confirm_sesskey()) {
    $dir = ($action === 'moveup') ? 'up' : 'down';
    \mod_sat\local\structure_manager::move_slot($slotid, $dir);
    redirect($buildediturl($selectedmoduleid));
}

// Process toggle scored action.
if ($action === 'togglescored' && $slotid && confirm_sesskey()) {
    \mod_sat\local\structure_manager::toggle_slot_scored($slotid);
    redirect($buildediturl($selectedmoduleid));
}

// Process single-click quick add from Question Bank picker.
if ($action === 'addsingle' && confirm_sesskey()) {
    $qbankentryid = required_param('qentryid', PARAM_INT);
    $targetmodid = required_param('targetmodid', PARAM_INT);
    $isscored = optional_param('isscored', 1, PARAM_INT);

    $qtypes = \mod_sat\local\structure_manager::get_qtypes_for_entries([$qbankentryid]);
    $qtype = $qtypes[(int)$qbankentryid] ?? '';
    $isspr = in_array($qtype, ['shortanswer', 'numerical'], true);

    \mod_sat\local\structure_manager::add_fixed_slot(
        $sat->id,
        $targetmodid,
        $context->id,
        $qbankentryid,
        1.0,
        (bool)$isscored,
        $isspr
    );
    redirect($buildediturl($targetmodid, [], 'sat-qbank-picker'));
}

// Process bulk add from Question Bank selector.
if ($action === 'addbulk' && confirm_sesskey()) {
    $qentryids = optional_param_array('qentryids', [], PARAM_INT);
    $targetmodid = required_param('targetmodid', PARAM_INT);
    $isscored = optional_param('isscored', 0, PARAM_INT);

    if (!empty($qentryids)) {
        $qtypes = \mod_sat\local\structure_manager::get_qtypes_for_entries($qentryids);
        foreach ($qentryids as $qentryid) {
            $qtype = $qtypes[(int)$qentryid] ?? '';
            $isspr = in_array($qtype, ['shortanswer', 'numerical'], true);
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

    redirect($buildediturl($targetmodid, [], 'sat-qbank-picker'));
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
    redirect($buildediturl($targetmodid));
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
$PAGE->requires->js_call_amd('mod_sat/question_picker', 'init');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managequestions', 'mod_sat') . ' — ' . format_string($sat->name));

// Render module tabs.
echo html_writer::start_tag('ul', ['class' => 'nav nav-pills mb-4 gap-2']);
foreach ($modules as $m) {
    $isactive = ($m->id == $selectedmoduleid);
    $stagestr = get_string('stage_' . $m->stage, 'mod_sat');
    $secstr = strtoupper($m->section);
    $label = "$secstr: $stagestr";

    $link = $buildediturl((int)$m->id);
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

    // Resolve accessible Question Banks and Categories for the Moodle Quiz-style picker.
    $banks = \mod_sat\local\structure_manager::get_available_question_banks((int)$course->id, (int)$context->id);
    if ($bankcontextid === 0 || !isset($banks[$bankcontextid])) {
        $firstbank = reset($banks);
        $bankcontextid = $firstbank ? (int)$firstbank->contextid : 0;
    }
    $currentbank = ($bankcontextid && isset($banks[$bankcontextid])) ? $banks[$bankcontextid] : null;
    $categories = $bankcontextid ? \mod_sat\local\structure_manager::get_bank_categories($bankcontextid) : [];
    if ($catid !== 0 && !isset($categories[$catid])) {
        $catid = 0;
    }

    if ($currentbank && $currentbank->cmid > 0) {
        $qbankurl = new moodle_url('/question/edit.php', ['cmid' => $currentbank->cmid]);
    } else {
        $qbankurl = new moodle_url('/question/edit.php', ['courseid' => $course->id]);
    }

    echo html_writer::start_div('alert alert-info d-flex justify-content-between align-items-center mb-3');
    echo html_writer::tag('span', "Total Questions in this module: <strong>$count / $targetcount</strong>");
    echo html_writer::start_div('d-flex gap-2');
    echo html_writer::link($qbankurl, get_string('openquestionbank', 'mod_sat'), [
        'class' => 'btn btn-sm btn-outline-primary',
        'target' => '_blank',
    ]);
    $backurl = new moodle_url('/mod/sat/view.php', ['id' => $cmid]);
    echo html_writer::link($backurl, get_string('back'), ['class' => 'btn btn-sm btn-outline-dark']);
    echo html_writer::end_div();
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

        $slotlist = array_values($slots);
        $slotcount = count($slotlist);

        foreach ($slotlist as $idx => $s) {
            $upurl = $buildediturl((int)$currentmod->id, [
                'action' => 'moveup',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $downurl = $buildediturl((int)$currentmod->id, [
                'action' => 'movedown',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $toggleurl = $buildediturl((int)$currentmod->id, [
                'action' => 'togglescored',
                'slotid' => $s->id,
                'sesskey' => sesskey(),
            ]);
            $deleteurl = $buildediturl((int)$currentmod->id, [
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
                'class' => 'btn btn-sm btn-outline-danger sat-delete-slot',
                'data-confirm-title' => get_string('delete'),
                'data-confirm-message' => get_string('confirmdeleteslot', 'mod_sat'),
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

    // Moodle Quiz-style Question Bank & Category Picker.
    echo html_writer::start_div('card p-3 mt-4 shadow-sm border', ['id' => 'sat-qbank-picker']);
    echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3');
    echo html_writer::tag('h5', get_string('addfromquestionbank', 'mod_sat'), ['class' => 'card-title mb-0']);
    if ($currentbank) {
        echo html_writer::link($qbankurl, get_string('openquestionbank', 'mod_sat'), [
            'class' => 'btn btn-sm btn-outline-secondary',
            'target' => '_blank',
        ]);
    }
    echo html_writer::end_div();

    if (empty($banks)) {
        echo $OUTPUT->notification(get_string('noquestionsincategory', 'mod_sat'), 'warning');
    } else {
        // Step 1 & 2: Bank, Category, Subcategories, QType, and Search filter form.
        echo html_writer::start_tag('form', [
            'method' => 'get',
            'action' => new moodle_url('/mod/sat/edit.php', [], 'sat-qbank-picker'),
            'id' => 'sat-qbank-filter-form',
            'class' => 'bg-light border rounded p-3 mb-3',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'modid', 'value' => $currentmod->id]);

        // Row 1: Question Bank & Category selectors.
        echo html_writer::start_div('row g-3 mb-2');

        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('label', get_string('questionbank', 'mod_sat'), [
            'for' => 'sat-select-bank',
            'class' => 'form-label fw-semibold mb-1',
        ]);
        echo html_writer::start_tag('select', [
            'name' => 'bankcontextid',
            'id' => 'sat-select-bank',
            'class' => 'form-select',
        ]);
        foreach ($banks as $bank) {
            $attrs = ['value' => (int)$bank->contextid];
            if ((int)$bank->contextid === $bankcontextid) {
                $attrs['selected'] = 'selected';
            }
            $banklabel = $bank->name . ' (' . (int)$bank->questioncount . ')';
            echo html_writer::tag('option', s($banklabel), $attrs);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-6');
        echo html_writer::tag('label', get_string('category', 'mod_sat'), [
            'for' => 'sat-select-category',
            'class' => 'form-label fw-semibold mb-1',
        ]);
        echo html_writer::start_tag('select', [
            'name' => 'catid',
            'id' => 'sat-select-category',
            'class' => 'form-select',
        ]);
        $allcatattrs = ['value' => 0];
        if ($catid === 0) {
            $allcatattrs['selected'] = 'selected';
        }
        $totalinbank = $currentbank ? (int)$currentbank->questioncount : 0;
        echo html_writer::tag('option', s(get_string('allcategoriesinbank', 'mod_sat', $totalinbank)), $allcatattrs);
        foreach ($categories as $cat) {
            $catattrs = ['value' => (int)$cat->id];
            if ((int)$cat->id === $catid) {
                $catattrs['selected'] = 'selected';
            }
            echo html_writer::tag('option', s($cat->label), $catattrs);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        echo html_writer::end_div(); // End Row 1.

        // Row 2: Subcategory toggle, QType filter, Search box.
        echo html_writer::start_div('row g-3 align-items-center');

        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('form-check');
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'recurse', 'value' => '0']);
        $recurseattrs = [
            'type' => 'checkbox',
            'name' => 'recurse',
            'value' => '1',
            'id' => 'sat-check-recurse',
            'class' => 'form-check-input',
        ];
        if ($recurse) {
            $recurseattrs['checked'] = 'checked';
        }
        echo html_writer::empty_tag('input', $recurseattrs);
        echo html_writer::tag('label', get_string('includesubcategories', 'mod_sat'), [
            'for' => 'sat-check-recurse',
            'class' => 'form-check-label',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-4');
        echo html_writer::start_tag('select', [
            'name' => 'qtypefilter',
            'id' => 'sat-select-qtype',
            'class' => 'form-select form-select-sm',
            'aria-label' => get_string('qtype', 'mod_sat'),
        ]);
        $qtypeoptions = [
            '' => get_string('allqtypes', 'mod_sat'),
            'mcq' => get_string('qtype_mcq', 'mod_sat'),
            'spr' => get_string('qtype_spr', 'mod_sat'),
        ];
        foreach ($qtypeoptions as $optval => $optlabel) {
            $optattrs = ['value' => $optval];
            if ($qtypefilter === $optval) {
                $optattrs['selected'] = 'selected';
            }
            echo html_writer::tag('option', s($optlabel), $optattrs);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        echo html_writer::start_div('col-md-4');
        echo html_writer::start_div('input-group input-group-sm');
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'qsearch',
            'value' => $qsearch,
            'class' => 'form-control',
            'placeholder' => get_string('searchquestions', 'mod_sat'),
            'aria-label' => get_string('searchquestions', 'mod_sat'),
        ]);
        echo html_writer::tag('button', get_string('filterquestions', 'mod_sat'), [
            'type' => 'submit',
            'class' => 'btn btn-outline-secondary',
        ]);
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::end_div(); // End Row 2.
        echo html_writer::end_tag('form');

        // Load questions for the selected Bank + Category + Filters.
        $bankquestions = \mod_sat\local\structure_manager::get_questions_for_category(
            $bankcontextid,
            $catid,
            (bool)$recurse,
            $qsearch,
            $qtypefilter
        );
        $usedentries = \mod_sat\local\structure_manager::get_exam_used_entries((int)$sat->id);

        if (empty($bankquestions)) {
            echo $OUTPUT->notification(get_string('noquestionsincategory', 'mod_sat'), 'info');
        } else {
            echo html_writer::start_tag('form', [
                'method' => 'post',
                'action' => new moodle_url('/mod/sat/edit.php', [], 'sat-qbank-picker'),
                'id' => 'sat-qbank-bulk-form',
            ]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'targetmodid', 'value' => $currentmod->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'addbulk']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bankcontextid', 'value' => $bankcontextid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catid', 'value' => $catid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'recurse', 'value' => $recurse]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qsearch', 'value' => $qsearch]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtypefilter', 'value' => $qtypefilter]);

            // Selection toolbar above table.
            echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2');
            echo html_writer::start_div('d-flex align-items-center gap-2');
            echo html_writer::tag('span', get_string('questionsfound', 'mod_sat', count($bankquestions)), [
                'class' => 'badge bg-light text-dark border',
            ]);
            $selectedbadge = get_string('selectedcount', 'mod_sat', '<span id="sat-selected-count">0</span>');
            echo html_writer::tag('span', $selectedbadge, ['class' => 'badge bg-primary']);
            echo html_writer::end_div();

            echo html_writer::start_div('d-flex gap-2');
            echo html_writer::tag('button', get_string('selectallunused', 'mod_sat'), [
                'type' => 'button',
                'id' => 'sat-btn-select-unused',
                'class' => 'btn btn-sm btn-outline-secondary',
            ]);
            echo html_writer::tag('button', get_string('deselectall', 'mod_sat'), [
                'type' => 'button',
                'id' => 'sat-btn-deselect-all',
                'class' => 'btn btn-sm btn-outline-secondary',
            ]);
            echo html_writer::end_div();
            echo html_writer::end_div();

            // Questions table.
            $picker = new html_table();
            $mastercheck = html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'id' => 'sat-check-all',
                'class' => 'form-check-input',
                'title' => get_string('selectall', 'mod_sat'),
                'aria-label' => get_string('selectall', 'mod_sat'),
            ]);
            $picker->head = [
                $mastercheck,
                '',
                get_string('questionname', 'mod_sat'),
                get_string('category', 'mod_sat'),
                get_string('qtype', 'mod_sat'),
                get_string('state', 'mod_sat'),
            ];
            $picker->attributes['class'] = 'table table-sm table-hover align-middle mb-0';

            foreach ($bankquestions as $bq) {
                $entryid = (int)$bq->qbankentryid;
                $usages = $usedentries[$entryid] ?? [];
                $incurrentslots = [];
                $othermodlabels = [];

                foreach ($usages as $u) {
                    if ($u->moduleid === (int)$currentmod->id) {
                        $incurrentslots[] = $u->slot;
                    } else {
                        $modlabel = strtoupper($u->section) . ' ' . get_string('stage_' . $u->stage, 'mod_sat');
                        $othermodlabels[$u->moduleid] = $modlabel;
                    }
                }

                $checkbox = html_writer::empty_tag('input', [
                    'type' => 'checkbox',
                    'name' => 'qentryids[]',
                    'value' => $entryid,
                    'class' => 'form-check-input sat-qentry-checkbox',
                    'data-in-module' => !empty($incurrentslots) ? '1' : '0',
                    'aria-label' => format_string($bq->name),
                ]);

                $singleurl = $buildediturl((int)$currentmod->id, [
                    'targetmodid' => $currentmod->id,
                    'action' => 'addsingle',
                    'qentryid' => $entryid,
                    'sesskey' => sesskey(),
                ], 'sat-qbank-picker');
                $quickadd = html_writer::link($singleurl, '+', [
                    'class' => 'btn btn-sm btn-outline-primary fw-bold px-2 py-0',
                    'title' => get_string('addtomodule', 'mod_sat'),
                    'aria-label' => get_string('addtomodule', 'mod_sat') . ': ' . format_string($bq->name),
                ]);

                $qnamehtml = html_writer::tag('div', format_string($bq->name), ['class' => 'fw-semibold']);
                [$stimulus, $prompt] = \mod_sat\local\structure_manager::split_stimulus((string)$bq->questiontext);
                $snippet = shorten_text(trim(strip_tags($prompt ?: $stimulus)), 90);
                if ($snippet !== '') {
                    $qnamehtml .= html_writer::tag('small', s($snippet), ['class' => 'text-muted d-block']);
                }
                if ($stimulus !== '') {
                    $qnamehtml .= html_writer::tag('span', 'Split Stimulus', ['class' => 'badge bg-info text-dark mt-1']);
                }

                $issprtype = in_array($bq->qtype, ['shortanswer', 'numerical'], true);
                $typelabel = $issprtype
                    ? '<span class="badge bg-warning text-dark">SPR (' . s($bq->qtype) . ')</span>'
                    : '<span class="badge bg-light text-dark border">' . s($bq->qtype) . '</span>';

                $statushtml = html_writer::tag('span', '#' . $entryid, ['class' => 'text-muted small d-block']);
                if (!empty($incurrentslots)) {
                    $statushtml .= html_writer::tag(
                        'span',
                        get_string('inthismodule', 'mod_sat', implode(', #', $incurrentslots)),
                        ['class' => 'badge bg-success me-1']
                    );
                }
                foreach ($othermodlabels as $otherlabel) {
                    $statushtml .= html_writer::tag(
                        'span',
                        get_string('inothermodule', 'mod_sat', $otherlabel),
                        ['class' => 'badge bg-secondary me-1']
                    );
                }

                $picker->data[] = [
                    $checkbox,
                    $quickadd,
                    $qnamehtml,
                    html_writer::tag('small', s((string)$bq->categorypath), ['class' => 'text-secondary']),
                    $typelabel,
                    $statushtml,
                ];
            }

            echo html_writer::div(html_writer::table($picker), 'border rounded', [
                'style' => 'max-height: 440px; overflow-y: auto;',
            ]);

            echo html_writer::start_div('d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 pt-2 border-top');
            echo html_writer::start_div('form-check');
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'isscored', 'value' => '0']);
            echo html_writer::empty_tag('input', [
                'type' => 'checkbox',
                'name' => 'isscored',
                'value' => '1',
                'checked' => 'checked',
                'id' => 'check_bulk_scored',
                'class' => 'form-check-input',
            ]);
            echo html_writer::tag('label', get_string('markselectedscored', 'mod_sat'), [
                'for' => 'check_bulk_scored',
                'class' => 'form-check-label',
            ]);
            echo html_writer::end_div();

            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'id' => 'sat-btn-add-selected',
                'value' => get_string('addselectedtomodule', 'mod_sat'),
                'class' => 'btn btn-primary',
            ]);
            echo html_writer::end_div();
            echo html_writer::end_tag('form');
        }
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
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bankcontextid', 'value' => $bankcontextid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catid', 'value' => $catid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'recurse', 'value' => $recurse]);

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
