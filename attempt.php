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

// Enforce availability windows, attempt limits, and preview capability.
[$canstart, $reasonkey] = \mod_sat\local\attempt_manager::can_start_or_resume_attempt(
    $sat,
    $USER->id,
    $context,
    (bool)$preview
);
if (!$canstart) {
    redirect(
        new moodle_url('/mod/sat/view.php', ['id' => $cm->id]),
        get_string($reasonkey ?: 'examnotopen', 'mod_sat'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
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
    // Fetch or resume active module attempt (supports Paced Mode resume).
    $activemodattempt = \mod_sat\local\attempt_manager::get_or_resume_active_module($sat, $activeattempt);
}

// Minimal, distraction-free page layout.
$PAGE->set_url('/mod/sat/attempt.php', ['id' => $cm->id]);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(format_string($sat->name) . ' — ' . get_string('modulename', 'mod_sat'));
$PAGE->set_context($context);

// Pass initialization parameters and site calculator policy to AMD runner.
$config = get_config('mod_sat');
$initparams = [
    'attemptid' => (int)$activeattempt->id,
    'modattemptid' => (int)$activemodattempt->id,
    'cmid' => (int)$cm->id,
    'satid' => (int)$sat->id,
    'sesskey' => sesskey(),
    'examScope' => (string)$sat->exam_scope,
    'pacingMode' => (string)$sat->pacing_mode,
    'enableGraphingCalc' => !isset($config->enable_graphing_calc) || !empty($config->enable_graphing_calc),
    'enableScientificCalc' => !isset($config->enable_scientific_calc) || !empty($config->enable_scientific_calc),
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
            <button id="sat-btn-directions" type="button" class="btn btn-sm btn-outline-light d-flex align-items-center gap-1 ms-2" title="Section Directions">
                <i class="fa fa-info-circle"></i> <span>Directions</span>
            </button>
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
            <button id="sat-btn-annotate" type="button" class="btn btn-sm btn-outline-success d-flex align-items-center gap-1" title="Highlighter and Notes">
                <i class="fa fa-pencil"></i> <span>Annotate</span>
                <span id="sat-annotate-count" class="badge bg-success text-white d-none">0</span>
            </button>
            <button id="sat-btn-top-submit" class="btn btn-sm btn-warning d-flex align-items-center gap-1 fw-bold text-dark" title="Review questions and submit this module">
                <i class="fa fa-check-circle"></i> Submit Module
            </button>
            <button id="sat-btn-more" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1" title="Accessibility & Options">
                <i class="fa fa-cog"></i>
            </button>
        </div>
    </header>

    <!-- Collapsible Section Directions Panel -->
    <div id="sat-directions-panel" class="sat-floating-modal card shadow-lg position-absolute d-none" style="width: 460px; max-width: calc(100vw - 24px); top: 64px; left: 20px; z-index: 1055;">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
            <span class="small fw-bold"><i class="fa fa-info-circle me-1"></i> Section Directions</span>
            <button id="sat-directions-close" type="button" class="btn btn-sm btn-outline-light py-0 px-2" aria-label="Close Directions">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div id="sat-directions-body" class="card-body p-3 small lh-base"></div>
    </div>

    <!-- Main Question Workspace -->
    <main class="sat-workspace flex-grow-1 overflow-hidden d-flex position-relative">
        <!-- Scheduled Inter-Section Break Screen -->
        <div id="sat-break-screen" class="sat-workspace-overlay w-100 h-100 d-none align-items-center justify-content-center p-4 overflow-y-auto">
            <div class="card shadow-lg border-0 text-center p-4 p-md-5" style="max-width: 600px; width: 100%;">
                <div class="mb-2">
                    <span class="badge bg-info text-dark text-uppercase px-3 py-2">Inter-Section Break</span>
                </div>
                <h2 class="fw-bold mb-1">Scheduled Section Break</h2>
                <p class="text-muted mb-3">Reading and Writing Section Complete &bull; Up Next: Math Section</p>
                <div id="sat-break-timer" class="display-4 fw-bold font-monospace text-warning bg-dark rounded-3 py-3 px-4 mx-auto my-3 border border-secondary" style="min-width: 200px;">10:00</div>
                <div class="alert alert-light border text-start small mb-4">
                    <ul class="mb-0 ps-3">
                        <li>Your Reading and Writing answers are saved and locked.</li>
                        <li>Testing tools and exam questions remain hidden during the break.</li>
                        <li>The Math section starts automatically when the countdown reaches <code>00:00</code>, or you may resume early at any time.</li>
                    </ul>
                </div>
                <div>
                    <button id="sat-btn-end-break" type="button" class="btn btn-lg btn-primary px-5 fw-bold">
                        <i class="fa fa-play me-2"></i>Resume to Math Section Now
                    </button>
                </div>
            </div>
        </div>

        <!-- In-App Check Your Work Pre-Submit Overlay -->
        <div id="sat-review-overlay" class="sat-workspace-overlay w-100 h-100 d-none overflow-y-auto p-4 bg-light">
            <div class="container py-3" style="max-width: 820px;">
                <div class="card shadow-sm border p-4">
                    <div class="text-center mb-3">
                        <h3 class="fw-bold mb-1">Check Your Work</h3>
                        <div id="sat-review-subtitle" class="text-muted small">Review your progress before submitting this module.</div>
                    </div>
                    <div class="row g-2 text-center mb-3">
                        <div class="col-4">
                            <div class="border rounded p-2 bg-white">
                                <div class="small text-muted">Answered</div>
                                <div id="sat-review-answered-count" class="fs-4 fw-bold text-success">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-white">
                                <div class="small text-muted">Unanswered</div>
                                <div id="sat-review-unanswered-count" class="fs-4 fw-bold text-danger">0</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded p-2 bg-white">
                                <div class="small text-muted">Marked for Review</div>
                                <div id="sat-review-flagged-count" class="fs-4 fw-bold text-warning">0</div>
                            </div>
                        </div>
                    </div>
                    <div id="sat-review-unanswered-warning" class="alert alert-warning py-2 small d-none"></div>
                    <div id="sat-review-grid" class="d-flex flex-wrap gap-2 justify-content-center my-4"></div>
                    <div id="sat-transition-status" class="alert alert-info py-2 fw-semibold text-center d-none"></div>
                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <button id="sat-btn-review-back" type="button" class="btn btn-outline-secondary px-4">
                            <i class="fa fa-arrow-left me-1"></i>Back to Questions
                        </button>
                        <button id="sat-btn-review-confirm" type="button" class="btn btn-warning px-4 fw-bold text-dark">
                            <i class="fa fa-check-circle me-1"></i>Confirm &amp; Submit Module
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reading Passage Stimulus Pane (Left) -->
        <div id="sat-stimulus-pane" class="sat-pane sat-stimulus-pane col-6 p-4 overflow-y-auto border-end bg-white">
            <div id="sat-pane-header-title" class="sat-pane-header text-muted small fw-bold text-uppercase mb-2">Stimulus / Passage</div>
            <div id="sat-stimulus-content" class="sat-reading-text lh-lg fs-5">
                <!-- Injected via JavaScript -->
            </div>
            <div id="sat-spr-guide-container" class="d-none"></div>
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
                    <div id="sat-spr-validation-msg" class="small mt-1 d-none" role="status" aria-live="polite"></div>
                    <div class="d-flex justify-content-between text-muted small mt-1">
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
            <button id="sat-btn-submit-module" class="btn btn-warning px-3 d-flex align-items-center gap-1 fw-bold text-dark" title="Review questions and submit this module">
                <i class="fa fa-check-circle"></i> Review & Submit
            </button>
        </div>
    </footer>

    <!-- Question Matrix Drawer (Popover / Modal) -->
    <div id="sat-matrix-drawer" class="sat-matrix-drawer position-absolute bottom-0 start-50 translate-middle-x bg-white text-dark p-4 rounded-top shadow-lg border d-none" style="z-index: 1050; width: 650px; max-height: 400px; overflow-y: auto;">
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
            <h6 class="mb-0 fw-bold">Question Navigation Matrix</h6>
            <button id="sat-btn-drawer-close" type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center" aria-label="Close">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
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
        <div class="mt-3 pt-3 border-top text-center">
            <button id="sat-btn-drawer-submit" class="btn btn-warning w-100 fw-bold text-dark d-flex align-items-center justify-content-center gap-2">
                <i class="fa fa-check-circle"></i> Review & Submit Module
            </button>
        </div>
    </div>

    <!-- Self-Hosted Draggable Graphing & Scientific Calculator Modal -->
    <div id="sat-calculator-panel" class="sat-floating-modal card shadow-lg position-absolute d-none" style="width: 440px; top: 70px; right: 20px; z-index: 1060;">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2" id="sat-calc-drag-handle" style="cursor: move;">
            <span class="small fw-bold"><i class="fa fa-calculator me-1"></i> Calculator</span>
            <div class="d-flex gap-1 align-items-center">
                <button id="sat-calc-angle-mode" type="button" class="btn btn-xs btn-outline-warning py-0 px-2 font-monospace" style="font-size: 11px;" title="Toggle Radians / Degrees">RAD</button>
                <button id="sat-calc-mode-toggle" type="button" class="btn btn-xs btn-outline-light py-0 px-2" style="font-size: 11px;">Graph</button>
                <button id="sat-calc-close" type="button" class="btn btn-sm btn-outline-light py-0 px-2" aria-label="Close Calculator">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="card-body p-2">
            <!-- Scientific Mode -->
            <div id="sat-calc-scientific" class="d-flex flex-column gap-2">
                <input type="text" id="sat-calc-screen" class="form-control text-end fs-4 font-monospace bg-light" value="0" aria-label="Calculator display">
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
                <div class="input-group input-group-sm mb-1">
                    <span class="input-group-text font-monospace fw-bold text-primary">y₁ =</span>
                    <input type="text" id="sat-graph-function" class="form-control font-monospace" placeholder="e.g. x^2 - 4">
                    <button id="sat-graph-plot-btn" type="button" class="btn btn-primary">Plot</button>
                </div>
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text font-monospace fw-bold text-success">y₂ =</span>
                    <input type="text" id="sat-graph-function-2" class="form-control font-monospace" placeholder="Optional second curve, e.g. 2x + 1">
                </div>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="btn-group btn-group-sm" role="group" aria-label="Graph zoom controls">
                        <button id="sat-graph-zoom-in" type="button" class="btn btn-outline-secondary py-0 px-2" title="Zoom In">+</button>
                        <button id="sat-graph-zoom-out" type="button" class="btn btn-outline-secondary py-0 px-2" title="Zoom Out">&minus;</button>
                        <button id="sat-graph-zoom-reset" type="button" class="btn btn-outline-secondary py-0 px-2" title="Reset Zoom">1:1</button>
                    </div>
                    <small id="sat-graph-coords" class="font-monospace text-muted">x: --, y: --</small>
                </div>
                <canvas id="sat-graph-canvas" width="410" height="260" class="border rounded bg-white w-100"></canvas>
            </div>
        </div>
    </div>

    <!-- Draggable Highlighter & Notes (Annotate) Panel -->
    <div id="sat-annotate-panel" class="sat-floating-modal card shadow-lg position-absolute d-none" style="width: 340px; max-width: calc(100vw - 24px); top: 68px; right: 20px; z-index: 1055;">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2" id="sat-annotate-drag-handle" style="cursor: move;">
            <span class="small fw-bold"><i class="fa fa-pencil me-1"></i> Highlighter &amp; Notes</span>
            <button id="sat-annotate-close" type="button" class="btn btn-sm btn-outline-light py-0 px-2" aria-label="Close Annotate Panel">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="small fw-semibold">Highlight Color:</span>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="sat-hl-swatch active" data-color="yellow" title="Yellow highlight" aria-label="Yellow highlight"></button>
                    <button type="button" class="sat-hl-swatch" data-color="blue" title="Blue highlight" aria-label="Blue highlight"></button>
                    <button type="button" class="sat-hl-swatch" data-color="pink" title="Pink highlight" aria-label="Pink highlight"></button>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                <small class="text-muted lh-sm">Select text in the passage or question to highlight.</small>
                <button id="sat-btn-hl-selection" type="button" class="btn btn-xs btn-outline-primary text-nowrap">Highlight</button>
            </div>
            <hr class="my-2">
            <label for="sat-note-input" class="form-label small fw-semibold mb-1">Question Note / Scratchpad:</label>
            <textarea id="sat-note-input" class="form-control form-control-sm mb-2" rows="2" placeholder="Write a note for this question..."></textarea>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <button id="sat-note-save" type="button" class="btn btn-sm btn-success">
                    <i class="fa fa-plus me-1"></i> Save Note
                </button>
                <button id="sat-annotate-clear" type="button" class="btn btn-sm btn-outline-danger">Clear All</button>
            </div>
            <div id="sat-annotations-list" class="sat-annotations-list d-flex flex-column gap-2 mt-2" style="max-height: 180px; overflow-y: auto;">
                <!-- Saved highlights & notes for current question injected via JS -->
            </div>
        </div>
    </div>

    <!-- Accessibility & Display Options Popover -->
    <div id="sat-options-panel" class="sat-floating-modal card shadow-lg position-absolute d-none" style="width: 280px; top: 68px; right: 20px; z-index: 1055;">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
            <span class="small fw-bold"><i class="fa fa-cog me-1"></i> Display &amp; Accessibility</span>
            <button id="sat-options-close" type="button" class="btn btn-sm btn-outline-light py-0 px-2" aria-label="Close Options">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="card-body p-3">
            <div class="mb-3">
                <div class="small fw-semibold mb-1">Text Size</div>
                <div class="btn-group btn-group-sm w-100" role="group" aria-label="Text size">
                    <button type="button" class="btn btn-outline-secondary sat-font-btn active" data-size="normal">Normal</button>
                    <button type="button" class="btn btn-outline-secondary sat-font-btn" data-size="lg">Large</button>
                    <button type="button" class="btn btn-outline-secondary sat-font-btn" data-size="xl">XL</button>
                </div>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="sat-toggle-contrast">
                <label class="form-check-label small fw-semibold" for="sat-toggle-contrast">High Contrast Mode</label>
            </div>
            <hr class="my-2">
            <div class="small fw-semibold mb-1">Keyboard Shortcuts</div>
            <ul class="list-unstyled small text-muted mb-0">
                <li><code>Alt + &larr; / &rarr;</code> Previous / Next question</li>
                <li><code>Alt + F</code> Mark for Review</li>
                <li><code>Esc</code> Close open panels</li>
            </ul>
        </div>
    </div>

    <!-- Math Formula Reference Sheet Modal -->
    <div id="sat-formulas-modal" class="modal fade" tabindex="-1" role="dialog" aria-modal="true" aria-labelledby="sat-formulas-title">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2 d-flex justify-content-between align-items-center">
                    <h5 id="sat-formulas-title" class="modal-title fs-6 fw-bold mb-0"><i class="fa fa-book me-2"></i> Math Reference Formulas</h5>
                    <button type="button" id="sat-formulas-close" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1" data-bs-dismiss="modal" data-dismiss="modal" aria-label="Close Formulas">
                        <i class="fa fa-times" aria-hidden="true"></i> <span>Close</span>
                    </button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 text-center">
                        <!-- Row 1: 2D Geometry & Right Triangle -->
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <circle cx="55" cy="42" r="30" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <circle cx="55" cy="42" r="2" fill="#0f172a"/>
                                    <line x1="55" y1="42" x2="85" y2="42" stroke="#ef4444" stroke-width="2"/>
                                    <text x="68" y="37" font-size="12" fill="#ef4444" font-style="italic">r</text>
                                </svg>
                                <div class="fw-bold small">Circle</div>
                                <small class="text-muted d-block">A = &pi;r&sup2;<br>C = 2&pi;r</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <rect x="18" y="22" width="74" height="42" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <text x="51" y="16" font-size="12" fill="#ef4444" font-style="italic">l</text>
                                    <text x="96" y="46" font-size="12" fill="#ef4444" font-style="italic">w</text>
                                </svg>
                                <div class="fw-bold small">Rectangle</div>
                                <small class="text-muted d-block">A = lw</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <polygon points="18,68 92,68 58,18" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="58" y1="18" x2="58" y2="68" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="3,2"/>
                                    <polyline points="58,61 65,61 65,68" fill="none" stroke="#64748b" stroke-width="1.2"/>
                                    <text x="62" y="46" font-size="12" fill="#ef4444" font-style="italic">h</text>
                                    <text x="52" y="80" font-size="12" fill="#2563eb" font-style="italic">b</text>
                                </svg>
                                <div class="fw-bold small">Triangle</div>
                                <small class="text-muted d-block">A = &frac12;bh</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <polygon points="22,68 88,68 22,18" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <polyline points="22,60 30,60 30,68" fill="none" stroke="#64748b" stroke-width="1.5"/>
                                    <text x="11" y="46" font-size="12" fill="#ef4444" font-style="italic">a</text>
                                    <text x="52" y="80" font-size="12" fill="#ef4444" font-style="italic">b</text>
                                    <text x="58" y="38" font-size="12" fill="#2563eb" font-style="italic">c</text>
                                </svg>
                                <div class="fw-bold small">Right Triangle</div>
                                <small class="text-muted d-block">c&sup2; = a&sup2; + b&sup2;</small>
                            </div>
                        </div>

                        <!-- Row 2: Special Right Triangles & 3D Prisms -->
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <polygon points="18,68 92,68 18,22" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <polyline points="18,60 26,60 26,68" fill="none" stroke="#64748b" stroke-width="1.5"/>
                                    <text x="20" y="39" font-size="9" fill="#0f172a">60&deg;</text>
                                    <text x="68" y="65" font-size="9" fill="#0f172a">30&deg;</text>
                                    <text x="8" y="48" font-size="11" fill="#ef4444" font-style="italic">x</text>
                                    <text x="45" y="80" font-size="11" fill="#ef4444">x&radic;3</text>
                                    <text x="56" y="40" font-size="11" fill="#2563eb">2x</text>
                                </svg>
                                <div class="fw-bold small">30&deg;&ndash;60&deg;&ndash;90&deg;</div>
                                <small class="text-muted d-block">x, x&radic;3, 2x</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <polygon points="24,68 82,68 24,14" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <polyline points="24,60 32,60 32,68" fill="none" stroke="#64748b" stroke-width="1.5"/>
                                    <text x="27" y="32" font-size="9" fill="#0f172a">45&deg;</text>
                                    <text x="61" y="65" font-size="9" fill="#0f172a">45&deg;</text>
                                    <text x="12" y="45" font-size="11" fill="#ef4444" font-style="italic">s</text>
                                    <text x="50" y="80" font-size="11" fill="#ef4444" font-style="italic">s</text>
                                    <text x="56" y="38" font-size="11" fill="#2563eb">s&radic;2</text>
                                </svg>
                                <div class="fw-bold small">45&deg;&ndash;45&deg;&ndash;90&deg;</div>
                                <small class="text-muted d-block">s, s, s&radic;2</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <rect x="20" y="30" width="52" height="38" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <polyline points="20,30 38,16 90,16 90,54 72,68" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="72" y1="30" x2="90" y2="16" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="38" y1="16" x2="38" y2="54" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="20" y1="68" x2="38" y2="54" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="38" y1="54" x2="90" y2="54" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <text x="43" y="80" font-size="11" fill="#ef4444" font-style="italic">l</text>
                                    <text x="83" y="67" font-size="11" fill="#ef4444" font-style="italic">w</text>
                                    <text x="93" y="39" font-size="11" fill="#ef4444" font-style="italic">h</text>
                                </svg>
                                <div class="fw-bold small">Rectangular Prism</div>
                                <small class="text-muted d-block">V = lwh</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1">
                                    <ellipse cx="55" cy="22" rx="28" ry="9" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="27" y1="22" x2="27" y2="66" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="83" y1="22" x2="83" y2="66" stroke="#2563eb" stroke-width="2"/>
                                    <path d="M 27 66 A 28 9 0 0 0 83 66" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <path d="M 27 66 A 28 9 0 0 1 83 66" fill="none" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="55" y1="22" x2="83" y2="22" stroke="#ef4444" stroke-width="1.8"/>
                                    <text x="66" y="19" font-size="11" fill="#ef4444" font-style="italic">r</text>
                                    <text x="88" y="48" font-size="11" fill="#ef4444" font-style="italic">h</text>
                                </svg>
                                <div class="fw-bold small">Right Cylinder</div>
                                <small class="text-muted d-block">V = &pi;r&sup2;h</small>
                            </div>
                        </div>

                        <!-- Row 3: Curved & Tapered Solids -->
                        <div class="col-6 col-md-4">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1" style="max-width: 110px;">
                                    <circle cx="55" cy="42" r="30" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <path d="M 25 42 A 30 10 0 0 0 85 42" fill="none" stroke="#2563eb" stroke-width="1.5"/>
                                    <path d="M 25 42 A 30 10 0 0 1 85 42" fill="none" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="55" y1="42" x2="85" y2="42" stroke="#ef4444" stroke-width="1.8"/>
                                    <text x="67" y="37" font-size="12" fill="#ef4444" font-style="italic">r</text>
                                </svg>
                                <div class="fw-bold small">Sphere</div>
                                <small class="text-muted d-block">V = &#x2074;&#x2044;&#x2083;&pi;r&sup3;</small>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1" style="max-width: 110px;">
                                    <line x1="55" y1="14" x2="25" y2="64" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="55" y1="14" x2="85" y2="64" stroke="#2563eb" stroke-width="2"/>
                                    <path d="M 25 64 A 30 9 0 0 0 85 64" fill="none" stroke="#2563eb" stroke-width="2"/>
                                    <path d="M 25 64 A 30 9 0 0 1 85 64" fill="none" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="55" y1="14" x2="55" y2="64" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="3,2"/>
                                    <line x1="55" y1="64" x2="85" y2="64" stroke="#ef4444" stroke-width="1.5"/>
                                    <text x="45" y="45" font-size="11" fill="#ef4444" font-style="italic">h</text>
                                    <text x="68" y="61" font-size="11" fill="#ef4444" font-style="italic">r</text>
                                </svg>
                                <div class="fw-bold small">Right Circular Cone</div>
                                <small class="text-muted d-block">V = &#x2153;&pi;r&sup2;h</small>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-2 h-100">
                                <svg viewBox="0 0 110 85" class="w-75 mb-1" style="max-width: 110px;">
                                    <polygon points="20,68 74,68 92,52 38,52" fill="none" stroke="#2563eb" stroke-width="1.8"/>
                                    <line x1="56" y1="14" x2="20" y2="68" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="56" y1="14" x2="74" y2="68" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="56" y1="14" x2="92" y2="52" stroke="#2563eb" stroke-width="2"/>
                                    <line x1="56" y1="14" x2="38" y2="52" stroke="#94a3b8" stroke-width="1.2" stroke-dasharray="3,2"/>
                                    <line x1="56" y1="14" x2="56" y2="60" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="3,2"/>
                                    <text x="59" y="42" font-size="11" fill="#ef4444" font-style="italic">h</text>
                                    <text x="44" y="80" font-size="11" fill="#ef4444" font-style="italic">l</text>
                                    <text x="86" y="66" font-size="11" fill="#ef4444" font-style="italic">w</text>
                                </svg>
                                <div class="fw-bold small">Rectangular Pyramid</div>
                                <small class="text-muted d-block">V = &#x2153;lwh</small>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-secondary mt-3 py-2 small mb-0">
                        <strong>Reference Facts:</strong>
                        The number of degrees of arc in a circle is 360.
                        The number of radians of arc in a circle is 2&pi;.
                        The sum of the measures in degrees of the angles of a triangle is 180.
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" id="sat-formulas-footer-close" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php

echo $OUTPUT->footer();
