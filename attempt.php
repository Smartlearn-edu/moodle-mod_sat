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
 * Computer-based exam simulator runner for mod_sat.
 *
 * Provides the immersive, distraction-free testing environment with split-screen
 * reading stimulus, embedded graphing/scientific calculator, and reference sheet.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID.
$preview = optional_param('preview', 0, PARAM_INT);

$cm = get_coursemodule_from_id('sat', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$sat = $DB->get_record('sat', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);

$canattempt = has_capability('mod/sat:attempt', $context);
$canpreview = get_capability_info('mod/sat:preview') ? has_capability('mod/sat:preview', $context) : has_capability('mod/sat:manage', $context);

if (!$canattempt && !$canpreview) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('sat:attempt', 'mod_sat'));
}

// Check for existing active attempt (or create new).
$activeattempt = $DB->get_record_select(
    'sat_attempts',
    'satid = :satid AND userid = :userid AND (state = :inprogress OR state = :paused)',
    [
        'satid' => $sat->id,
        'userid' => $USER->id,
        'inprogress' => \mod_sat\local\attempt_manager::STATE_INPROGRESS,
        'paused' => \mod_sat\local\attempt_manager::STATE_PAUSED,
    ],
    '*',
    IGNORE_MULTIPLE
);

if (!$activeattempt) {
    // Start new attempt.
    [$activeattempt, $activemodattempt] = \mod_sat\local\attempt_manager::start_new_attempt(
        $sat,
        $USER->id,
        $cm,
        (bool)$preview
    );
} else {
    // Fetch active module attempt.
    $activemodattempt = $DB->get_record_select(
        'sat_module_attempts',
        'attemptid = :attemptid AND state = :inprogress',
        ['attemptid' => $activeattempt->id, 'inprogress' => \mod_sat\local\attempt_manager::STATE_INPROGRESS],
        '*',
        IGNORE_MULTIPLE
    );

    if (!$activemodattempt) {
        $activemodattempt = $DB->get_record(
            'sat_module_attempts',
            ['attemptid' => $activeattempt->id],
            '*',
            IGNORE_MULTIPLE
        );
    }
}

// Minimal, distraction-free page layout.
$PAGE->set_url('/mod/sat/attempt.php', ['id' => $cm->id]);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(format_string($sat->name) . ' — ' . get_string('modulename', 'mod_sat'));
$PAGE->set_context($context);

// Pass initialization parameters to AMD runner.
$initparams = [
    'attemptid' => (int)$activeattempt->id,
    'modattemptid' => (int)$activemodattempt->id,
    'cmid' => (int)$cm->id,
    'satid' => (int)$sat->id,
    'sesskey' => sesskey(),
    'examScope' => (string)$sat->exam_scope,
    'pacingMode' => (string)$sat->pacing_mode,
];

$PAGE->requires->js_call_amd('mod_sat/runner', 'init', [$initparams]);

echo $OUTPUT->header();

// Render full exam simulator DOM structure.
?>
<div id="sat-simulator-app" class="sat-simulator d-flex flex-column vh-100">
    <!-- Top Exam Bar -->
    <header class="sat-top-bar d-flex justify-content-between align-items-center px-4 py-2 bg-dark text-white shadow-sm">
        <div class="sat-module-title d-flex align-items-center gap-2">
            <span class="badge bg-primary px-3 py-2 fs-6" id="sat-stage-badge">Loading...</span>
            <span class="fw-semibold text-light" id="sat-section-title"></span>
        </div>

        <div class="sat-timer-container d-flex align-items-center gap-2">
            <div id="sat-timer-display" class="fw-bold fs-5 px-3 py-1 bg-black rounded border border-secondary text-warning">
                --:--
            </div>
            <button id="sat-timer-toggle" class="btn btn-sm btn-outline-light" title="Hide/Show Timer">Hide</button>
        </div>

        <div class="sat-tools-tray d-flex align-items-center gap-2">
            <button id="sat-btn-calculator" class="btn btn-sm btn-outline-info d-flex align-items-center gap-1" title="Graphing & Scientific Calculator">
                <i class="fa fa-calculator"></i> Calculator
            </button>
            <button id="sat-btn-formulas" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1" title="Math Reference Sheet">
                <i class="fa fa-book"></i> Formulas
            </button>
            <button id="sat-btn-annotate" class="btn btn-sm btn-outline-success d-flex align-items-center gap-1" title="Highlighter and Notes">
                <i class="fa fa-pencil"></i> Annotate
            </button>
            <button id="sat-btn-more" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" title="Accessibility & Options">
                <i class="fa fa-cog"></i>
            </button>
        </div>
    </header>

    <!-- Main Question Workspace -->
    <main class="sat-workspace flex-grow-1 overflow-hidden d-flex position-relative">
        <!-- Reading Passage Stimulus Pane (Left) -->
        <div id="sat-stimulus-pane" class="sat-pane sat-stimulus-pane col-6 p-4 overflow-y-auto border-end bg-white">
            <div class="sat-pane-header text-muted small fw-bold text-uppercase mb-2">Stimulus / Passage</div>
            <div id="sat-stimulus-content" class="sat-reading-text lh-lg fs-5">
                <!-- Injected via JavaScript -->
            </div>
        </div>

        <!-- Question Prompt & Answers Pane (Right) -->
        <div id="sat-question-pane" class="sat-pane sat-question-pane col-6 p-4 overflow-y-auto bg-light d-flex flex-column justify-content-between">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-secondary fs-6" id="sat-question-number-badge">Question 1</span>
                    <button id="sat-btn-flag" class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1">
                        <i class="fa fa-bookmark-o" id="sat-flag-icon"></i> Mark for Review
                    </button>
                </div>

                <div id="sat-prompt-content" class="sat-prompt-text fs-5 mb-4">
                    <!-- Injected via JavaScript -->
                </div>

                <!-- Multiple Choice Options Area -->
                <div id="sat-mcq-area" class="d-flex flex-column gap-3">
                    <!-- Option A, B, C, D injected via JavaScript with elimination cross-outs -->
                </div>

                <!-- Student-Produced Response (SPR) Free-Response Area -->
                <div id="sat-spr-area" class="card p-3 shadow-sm d-none">
                    <label for="sat-spr-input" class="form-label fw-bold">Student-Produced Response (Grid-In):</label>
                    <div class="input-group input-group-lg mb-2">
                        <input type="text" id="sat-spr-input" class="form-control text-center font-monospace fs-3"
                               placeholder="e.g. 2/3, 0.75, -5" maxlength="6" autocomplete="off">
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Max 5 chars (positive), 6 chars (negative)</span>
                        <span id="sat-spr-fraction-preview" class="fw-bold text-primary"></span>
                    </div>
                </div>
            </div>

            <!-- Strikethrough eliminator helper banner -->
            <div class="alert alert-secondary py-2 small text-muted mt-4 d-flex justify-content-between align-items-center">
                <span>Click the <strong>ABC</strong> cross-out icon to eliminate wrong choices.</span>
                <span class="text-uppercase badge bg-dark" id="sat-domain-indicator"></span>
            </div>
        </div>
    </main>

    <!-- Bottom Navigation Bar -->
    <footer class="sat-bottom-bar d-flex justify-content-between align-items-center px-4 py-2 bg-dark text-white border-top">
        <div class="d-flex align-items-center gap-3">
            <button id="sat-btn-drawer-trigger" class="btn btn-outline-light d-flex align-items-center gap-2">
                <span id="sat-footer-counter">Question 1 of 27</span>
                <i class="fa fa-chevron-up"></i>
            </button>
        </div>

        <div class="d-flex align-items-center gap-2">
            <button id="sat-btn-prev" class="btn btn-outline-light px-4" disabled>Back</button>
            <button id="sat-btn-next" class="btn btn-primary px-4">Next</button>
        </div>
    </footer>

    <!-- Question Matrix Drawer (Popover / Modal) -->
    <div id="sat-matrix-drawer" class="sat-matrix-drawer position-absolute bottom-0 start-50 translate-middle-x bg-white text-dark p-4 rounded-top shadow-lg border d-none" style="z-index: 1050; width: 650px; max-height: 400px; overflow-y: auto;">
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
            <h6 class="mb-0 fw-bold">Question Navigation Matrix</h6>
            <button id="sat-btn-drawer-close" class="btn btn-sm btn-close"></button>
        </div>
        <div id="sat-matrix-grid" class="d-flex flex-wrap gap-2 justify-content-center">
            <!-- Question buttons (1..27) injected via JavaScript -->
        </div>
        <div class="d-flex justify-content-center gap-4 mt-3 pt-2 border-top small text-muted">
            <span><i class="fa fa-circle text-primary"></i> Current</span>
            <span><i class="fa fa-square text-dark"></i> Answered</span>
            <span><i class="fa fa-square-o text-muted"></i> Unanswered</span>
            <span><i class="fa fa-bookmark text-warning"></i> Marked for Review</span>
        </div>
    </div>

    <!-- Self-Hosted Draggable Graphing & Scientific Calculator Modal -->
    <div id="sat-calculator-panel" class="sat-floating-modal card shadow-lg position-absolute d-none" style="width: 440px; top: 70px; right: 20px; z-index: 1060;">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2" id="sat-calc-drag-handle" style="cursor: move;">
            <span class="small fw-bold"><i class="fa fa-calculator me-1"></i> Calculator</span>
            <div class="d-flex gap-1">
                <button id="sat-calc-mode-toggle" class="btn btn-xs btn-outline-light py-0 px-2" style="font-size: 11px;">Graph</button>
                <button id="sat-calc-close" class="btn-close btn-close-white btn-sm"></button>
            </div>
        </div>
        <div class="card-body p-2">
            <!-- Scientific Mode -->
            <div id="sat-calc-scientific" class="d-flex flex-column gap-2">
                <input type="text" id="sat-calc-screen" class="form-control text-end fs-4 font-monospace bg-light" readonly value="0">
                <div class="d-grid gap-1" style="grid-template-columns: repeat(5, 1fr);">
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="sin">sin</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="cos">cos</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="tan">tan</button>
                    <button class="btn btn-sm btn-warning calc-btn" data-val="C">C</button>
                    <button class="btn btn-sm btn-danger calc-btn" data-val="DEL">⌫</button>

                    <button class="btn btn-sm btn-secondary calc-btn" data-val="^">^</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="sqrt">√</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="(">(</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val=")">)</button>
                    <button class="btn btn-sm btn-primary calc-btn" data-val="/">÷</button>

                    <button class="btn btn-sm btn-light calc-btn" data-val="7">7</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="8">8</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="9">9</button>
                    <button class="btn btn-sm btn-primary calc-btn" data-val="*">×</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="pi">π</button>

                    <button class="btn btn-sm btn-light calc-btn" data-val="4">4</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="5">5</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="6">6</button>
                    <button class="btn btn-sm btn-primary calc-btn" data-val="-">−</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="e">e</button>

                    <button class="btn btn-sm btn-light calc-btn" data-val="1">1</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="2">2</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val="3">3</button>
                    <button class="btn btn-sm btn-primary calc-btn" data-val="+">+</button>
                    <button class="btn btn-sm btn-success calc-btn" data-val="=">=</button>

                    <button class="btn btn-sm btn-light calc-btn" data-val="0" style="grid-column: span 2;">0</button>
                    <button class="btn btn-sm btn-light calc-btn" data-val=".">.</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="log">log</button>
                    <button class="btn btn-sm btn-secondary calc-btn" data-val="ln">ln</button>
                </div>
            </div>

            <!-- Graphing Mode (HTML5 Canvas Plotter) -->
            <div id="sat-calc-graphing" class="d-none">
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text font-monospace">y =</span>
                    <input type="text" id="sat-graph-function" class="form-control font-monospace" placeholder="e.g. x^2 - 4">
                    <button id="sat-graph-plot-btn" class="btn btn-primary">Plot</button>
                </div>
                <canvas id="sat-graph-canvas" width="410" height="260" class="border rounded bg-white w-100"></canvas>
            </div>
        </div>
    </div>

    <!-- Math Formula Reference Sheet Modal -->
    <div id="sat-formulas-modal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white py-2">
                    <h5 class="modal-title fs-6"><i class="fa fa-book me-2"></i> Standard SAT Math Reference Formulas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4 text-center">
                        <div class="col-6 col-md-3 border rounded p-2">
                            <svg viewBox="0 0 100 80" class="w-75 mb-2">
                                <circle cx="50" cy="40" r="30" fill="none" stroke="#2563eb" stroke-width="2"/>
                                <line x1="50" y1="40" x2="80" y2="40" stroke="#ef4444" stroke-width="2"/>
                                <text x="62" y="36" font-size="12" fill="#ef4444">r</text>
                            </svg>
                            <div class="fw-bold">Circle</div>
                            <small class="text-muted">A = πr²<br>C = 2πr</small>
                        </div>
                        <div class="col-6 col-md-3 border rounded p-2">
                            <svg viewBox="0 0 100 80" class="w-75 mb-2">
                                <rect x="15" y="20" width="70" height="40" fill="none" stroke="#2563eb" stroke-width="2"/>
                                <text x="45" y="16" font-size="12" fill="#ef4444">w</text>
                                <text x="88" y="44" font-size="12" fill="#ef4444">l</text>
                            </svg>
                            <div class="fw-bold">Rectangle</div>
                            <small class="text-muted">A = lw</small>
                        </div>
                        <div class="col-6 col-md-3 border rounded p-2">
                            <svg viewBox="0 0 100 80" class="w-75 mb-2">
                                <polygon points="20,65 80,65 50,15" fill="none" stroke="#2563eb" stroke-width="2"/>
                                <line x1="50" y1="15" x2="50" y2="65" stroke="#ef4444" stroke-dasharray="2,2"/>
                                <text x="54" y="42" font-size="12" fill="#ef4444">h</text>
                                <text x="48" y="76" font-size="12" fill="#2563eb">b</text>
                            </svg>
                            <div class="fw-bold">Triangle</div>
                            <small class="text-muted">A = ½bh</small>
                        </div>
                        <div class="col-6 col-md-3 border rounded p-2">
                            <svg viewBox="0 0 100 80" class="w-75 mb-2">
                                <polygon points="20,65 80,65 20,15" fill="none" stroke="#2563eb" stroke-width="2"/>
                                <text x="10" y="42" font-size="12" fill="#ef4444">a</text>
                                <text x="48" y="76" font-size="12" fill="#ef4444">b</text>
                                <text x="55" y="38" font-size="12" fill="#2563eb">c</text>
                            </svg>
                            <div class="fw-bold">Pythagorean</div>
                            <small class="text-muted">a² + b² = c²</small>
                        </div>
                    </div>
                    <div class="alert alert-secondary mt-4 py-2 small">
                        <strong>Facts:</strong> The number of degrees of arc in a circle is 360. The number of radians of arc in a circle is 2π. The sum of the measures in degrees of the angles of a triangle is 180.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php

echo $OUTPUT->footer();
