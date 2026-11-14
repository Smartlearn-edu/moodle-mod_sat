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
 * Computer-based exam simulator orchestrator for mod_sat.
 *
 * @module     mod_sat/runner
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/ajax', 'core/notification', 'mod_sat/calculator'], function($, Ajax, Notification, Calculator) {
    'use strict';

    var Runner = {
        config: null,
        attemptData: null,
        currentIndex: 0,
        timerInterval: null,
        breakInterval: null,
        remainingSeconds: 0,
        isBreakActive: false,
        isSubmitting: false,
        responses: {},
        eliminations: {},
        flagged: {},
        annotations: {},
        annotateMode: false,
        highlightColor: 'yellow',

        init: function(config) {
            var self = this;
            this.config = config;

            // Initialize built-in calculator with site policy flags.
            Calculator.init(this.config);

            // Restore local accessibility preferences.
            this.restoreAccessibilityPrefs();

            // Formula modal triggers & reliable close handlers.
            $('#sat-btn-formulas').on('click', function(e) {
                e.preventDefault();
                self.openFormulasModal();
            });
            $('#sat-formulas-close, #sat-formulas-footer-close').on('click', function(e) {
                e.preventDefault();
                self.closeFormulasModal();
            });
            $('#sat-formulas-modal').on('click', function(e) {
                if (e.target === e.currentTarget) {
                    self.closeFormulasModal();
                }
            });

            // Annotate (Highlighter & Notes) tool triggers.
            $('#sat-btn-annotate').on('click', function(e) {
                e.preventDefault();
                self.toggleAnnotatePanel();
            });
            $('#sat-annotate-close').on('click', function(e) {
                e.preventDefault();
                self.closeAnnotatePanel();
            });
            $('.sat-hl-swatch').on('click', function() {
                $('.sat-hl-swatch').removeClass('active');
                $(this).addClass('active');
                self.highlightColor = $(this).data('color') || 'yellow';
            });
            $('#sat-btn-hl-selection').on('click', function(e) {
                e.preventDefault();
                self.captureCurrentSelection(false);
            });
            $('#sat-stimulus-content, #sat-prompt-content').on('mouseup touchend', function() {
                if (self.annotateMode) {
                    setTimeout(function() {
                        self.captureCurrentSelection(true);
                    }, 10);
                }
            });
            $('#sat-stimulus-content, #sat-prompt-content').on('click', 'mark.sat-hl', function(e) {
                if (!self.annotateMode) {
                    return;
                }
                var sel = window.getSelection();
                if (sel && !sel.isCollapsed) {
                    return;
                }
                e.stopPropagation();
                var hlId = $(this).attr('data-hl-id');
                if (hlId) {
                    self.removeAnnotation(hlId);
                }
            });
            $('#sat-note-save').on('click', function(e) {
                e.preventDefault();
                self.saveCurrentNote();
            });
            $('#sat-annotate-clear').on('click', function(e) {
                e.preventDefault();
                self.clearCurrentSlotAnnotations();
            });
            this.setupPanelDrag('#sat-annotate-panel', '#sat-annotate-drag-handle');

            // Accessibility & Display options gear menu.
            $('#sat-btn-more').on('click', function(e) {
                e.preventDefault();
                $('#sat-annotate-panel, #sat-directions-panel').addClass('d-none');
                self.annotateMode = false;
                self.updateAnnotateButtonState();
                $('#sat-options-panel').toggleClass('d-none');
            });
            $('#sat-options-close').on('click', function(e) {
                e.preventDefault();
                $('#sat-options-panel').addClass('d-none');
            });
            $('.sat-font-btn').on('click', function() {
                var size = $(this).data('size') || 'normal';
                self.applyFontSize(size);
            });
            $('#sat-toggle-contrast').on('change', function() {
                self.applyHighContrast($(this).is(':checked'));
            });

            // Section Directions panel toggles.
            $('#sat-btn-directions').on('click', function(e) {
                e.preventDefault();
                self.toggleDirectionsPanel();
            });
            $('#sat-directions-close').on('click', function(e) {
                e.preventDefault();
                $('#sat-directions-panel').addClass('d-none');
            });

            // Scheduled Break resume button.
            $('#sat-btn-end-break').on('click', function(e) {
                e.preventDefault();
                self.endBreak();
            });

            // In-app Check Your Work review overlay buttons.
            $('#sat-btn-review-back').on('click', function(e) {
                e.preventDefault();
                if (!self.isSubmitting) {
                    $('#sat-review-overlay').addClass('d-none');
                }
            });
            $('#sat-btn-review-confirm').on('click', function(e) {
                e.preventDefault();
                self.submitModule();
            });

            // Close open panels on Escape and support exam keyboard shortcuts.
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    if ($('#sat-formulas-modal').hasClass('show')) {
                        self.closeFormulasModal();
                    }
                    if (!$('#sat-annotate-panel').hasClass('d-none')) {
                        self.closeAnnotatePanel();
                    }
                    $('#sat-options-panel, #sat-directions-panel, #sat-matrix-drawer').addClass('d-none');
                    if (!self.isSubmitting && !$('#sat-review-overlay').hasClass('d-none')) {
                        $('#sat-review-overlay').addClass('d-none');
                    }
                    return;
                }

                if (!self.isBreakActive && !self.isSubmitting && $('#sat-review-overlay').hasClass('d-none')) {
                    var tagName = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
                    var isTyping = (tagName === 'input' || tagName === 'textarea');
                    if (e.altKey && e.key === 'ArrowRight') {
                        e.preventDefault();
                        self.handleNext();
                    } else if (e.altKey && e.key === 'ArrowLeft') {
                        e.preventDefault();
                        self.handlePrev();
                    } else if (e.altKey && (e.key === 'f' || e.key === 'F') && !isTyping) {
                        e.preventDefault();
                        self.toggleFlag();
                    }
                }
            });

            // Question matrix drawer triggers.
            $('#sat-btn-drawer-trigger').on('click', function() {
                $('#sat-matrix-drawer').toggleClass('d-none');
            });
            $('#sat-btn-drawer-close').on('click', function() {
                $('#sat-matrix-drawer').addClass('d-none');
            });

            // Navigation buttons.
            $('#sat-btn-next').on('click', $.proxy(this.handleNext, this));
            $('#sat-btn-prev').on('click', $.proxy(this.handlePrev, this));
            $('#sat-btn-submit-module, #sat-btn-top-submit, #sat-btn-drawer-submit').on('click', $.proxy(this.showModuleReview, this));

            // Flag toggle.
            $('#sat-btn-flag').on('click', $.proxy(this.toggleFlag, this));

            // Timer hide/show toggle.
            $('#sat-timer-toggle').on('click', function() {
                var display = $('#sat-timer-display');
                if (display.hasClass('d-none')) {
                    display.removeClass('d-none');
                    $(this).text('Hide');
                } else {
                    display.addClass('d-none');
                    $(this).text('Show');
                }
            });

            // SPR live input listener.
            $('#sat-spr-input').on('input', $.proxy(this.handleSprInput, this));

            // Load attempt state via Web Service.
            this.loadState();
        },

        loadState: function() {
            var self = this;
            var promises = Ajax.call([{
                methodname: 'mod_sat_get_attempt_state',
                args: {attemptid: self.config.attemptid}
            }]);

            promises[0].done(function(data) {
                if (data.state !== 'inprogress') {
                    window.location.href = M.cfg.wwwroot + '/mod/sat/view.php?id=' + self.config.cmid;
                    return;
                }
                self.attemptData = data;
                self.isSubmitting = false;
                $('#sat-transition-status').addClass('d-none').text('');
                $('#sat-btn-review-back, #sat-btn-review-confirm').prop('disabled', false);

                if (data.onbreak) {
                    self.showBreakScreen(data.breakremaining || data.timeremaining || 0);
                    return;
                }

                if (self.breakInterval) {
                    clearInterval(self.breakInterval);
                    self.breakInterval = null;
                }
                self.isBreakActive = false;
                $('#sat-break-screen').addClass('d-none').removeClass('d-flex');
                $('#sat-review-overlay').addClass('d-none');
                $('#sat-question-pane, .sat-bottom-bar').removeClass('d-none');
                $('#sat-btn-annotate, #sat-btn-directions, #sat-btn-top-submit').removeClass('d-none');
                self.closeAnnotatePanel();

                self.remainingSeconds = data.timeremaining;
                if (self.remainingSeconds >= 300) {
                    $('#sat-timer-display').removeClass('d-none text-danger border-danger').addClass('text-warning');
                    $('#sat-timer-toggle').removeClass('d-none').text('Hide');
                }
                self.responses = {};
                self.eliminations = {};
                self.flagged = {};
                self.annotations = {};

                if (data.slot_states && Array.isArray(data.slot_states)) {
                    data.slot_states.forEach(function(st) {
                        if (st.response) {
                            self.responses[st.slot] = st.response;
                        }
                        if (st.flagged) {
                            self.flagged[st.slot] = true;
                        }
                        if (st.eliminated) {
                            try {
                                self.eliminations[st.slot] = JSON.parse(st.eliminated);
                            } catch (e) {
                                self.eliminations[st.slot] = [];
                            }
                        }
                        if (st.annotations) {
                            try {
                                var parsedAnn = JSON.parse(st.annotations);
                                self.annotations[st.slot] = Array.isArray(parsedAnn) ? parsedAnn : [];
                            } catch (e) {
                                self.annotations[st.slot] = [];
                            }
                        }
                    });
                }

                // Update headers, section toolbar gating, and directions.
                var sectionLabel = (data.section === 'rw') ? 'Reading and Writing' : 'Math';
                var stageLabel = (data.stage === 'm1') ? 'Module 1 of 2' : 'Module 2 of 2';

                $('#sat-stage-badge').text(stageLabel);
                $('#sat-section-title').text(sectionLabel);
                self.applySectionToolbar(data.section);
                self.updateSectionDirections(data.section);

                // Start timer.
                self.startTimer();

                // Render first question.
                self.currentIndex = 0;
                self.renderQuestion();
                self.renderMatrix();
            }).fail(Notification.exception);
        },

        applySectionToolbar: function(section) {
            var isMath = (section === 'math');
            var calcAllowed = !this.config
                || this.config.enableGraphingCalc !== false
                || this.config.enableScientificCalc !== false;

            if (!isMath) {
                $('#sat-btn-calculator').addClass('d-none');
                $('#sat-calculator-panel').addClass('d-none');
                $('#sat-btn-formulas').addClass('d-none');
                this.closeFormulasModal();
            } else {
                $('#sat-btn-formulas').removeClass('d-none');
                if (calcAllowed) {
                    $('#sat-btn-calculator').removeClass('d-none');
                } else {
                    $('#sat-btn-calculator').addClass('d-none');
                    $('#sat-calculator-panel').addClass('d-none');
                }
            }
        },

        toggleDirectionsPanel: function() {
            $('#sat-options-panel').addClass('d-none');
            $('#sat-directions-panel').toggleClass('d-none');
        },

        updateSectionDirections: function(section) {
            var body = $('#sat-directions-body');
            if (!body.length) {
                return;
            }
            var html = '';
            if (section === 'math') {
                html = '<h6 class="fw-bold mb-2">Math Section Directions</h6>'
                    + '<p class="mb-2">Questions in this section cover algebra, advanced math, '
                    + 'problem-solving, data analysis, geometry, and trigonometry.</p>'
                    + '<ul class="mb-2 ps-3">'
                    + '<li>Use the <strong>Calculator</strong> and <strong>Formulas</strong> sheet anytime '
                    + 'during the Math modules.</li>'
                    + '<li>For multiple-choice items, select the single best option.</li>'
                    + '<li>For Student-Produced Response (SPR) items, enter a decimal, fraction, or integer '
                    + '(no mixed numbers, commas, spaces, or units).</li>'
                    + '<li>Figures are drawn to scale unless noted otherwise.</li>'
                    + '</ul>'
                    + '<p class="mb-0 text-muted">Use <strong>Mark for Review</strong> or the question matrix '
                    + 'to revisit items before submitting.</p>';
            } else {
                html = '<h6 class="fw-bold mb-2">Reading and Writing Section Directions</h6>'
                    + '<p class="mb-2">Each question accompanies one or more passages testing comprehension, '
                    + 'rhetorical synthesis, expression of ideas, and grammar conventions.</p>'
                    + '<ul class="mb-2 ps-3">'
                    + '<li>Choose the single best answer from the four provided choices.</li>'
                    + '<li>Use <strong>Annotate</strong> to highlight text or save scratchpad notes, and click '
                    + '<strong>ABC</strong> to cross out eliminated options.</li>'
                    + '<li>Calculators and math reference sheets are unavailable in this section.</li>'
                    + '</ul>'
                    + '<p class="mb-0 text-muted">Navigate freely within this module until the timer ends '
                    + 'or you submit.</p>';
            }
            body.html(html);
        },

        formatClock: function(totalSeconds) {
            var safe = Math.max(0, parseInt(totalSeconds, 10) || 0);
            var minutes = Math.floor(safe / 60);
            var seconds = safe % 60;
            var padM = (minutes < 10) ? '0' + minutes : String(minutes);
            var padS = (seconds < 10) ? '0' + seconds : String(seconds);
            return padM + ':' + padS;
        },

        showBreakScreen: function(breakRemaining) {
            var self = this;
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            if (this.breakInterval) {
                clearInterval(this.breakInterval);
                this.breakInterval = null;
            }

            this.isBreakActive = true;
            this.remainingSeconds = Math.max(0, parseInt(breakRemaining, 10) || 0);

            this.closeFormulasModal();
            this.closeAnnotatePanel();
            $('#sat-calculator-panel, #sat-directions-panel, #sat-options-panel, #sat-matrix-drawer')
                .addClass('d-none');
            $('#sat-stimulus-pane, #sat-question-pane, #sat-review-overlay, .sat-bottom-bar').addClass('d-none');
            $('#sat-btn-calculator, #sat-btn-formulas, #sat-btn-annotate, #sat-btn-directions, #sat-btn-top-submit')
                .addClass('d-none');

            $('#sat-stage-badge').text('Section Break');
            $('#sat-section-title').text('Up Next: Math Section');
            $('#sat-timer-toggle').addClass('d-none');

            var formatted = this.formatClock(this.remainingSeconds);
            $('#sat-break-timer').text(formatted);
            $('#sat-timer-display').removeClass('d-none text-danger border-danger')
                .addClass('text-warning').text(formatted);
            $('#sat-btn-end-break').prop('disabled', false);
            $('#sat-break-screen').removeClass('d-none').addClass('d-flex');

            if (this.remainingSeconds <= 0) {
                this.endBreak();
                return;
            }

            this.breakInterval = setInterval(function() {
                self.remainingSeconds--;
                var clock = self.formatClock(self.remainingSeconds);
                $('#sat-break-timer').text(clock);
                $('#sat-timer-display').text(clock);
                if (self.remainingSeconds <= 0) {
                    clearInterval(self.breakInterval);
                    self.breakInterval = null;
                    self.endBreak();
                }
            }, 1000);
        },

        endBreak: function() {
            var self = this;
            if (this.breakInterval) {
                clearInterval(this.breakInterval);
                this.breakInterval = null;
            }
            $('#sat-btn-end-break').prop('disabled', true);

            var promises = Ajax.call([{
                methodname: 'mod_sat_end_break',
                args: {
                    attemptid: self.config.attemptid,
                    modattemptid: (self.attemptData && self.attemptData.modattemptid)
                        ? self.attemptData.modattemptid : 0
                }
            }]);

            promises[0].done(function() {
                self.isBreakActive = false;
                $('#sat-break-screen').addClass('d-none').removeClass('d-flex');
                $('#sat-btn-end-break').prop('disabled', false);
                self.loadState();
            }).fail(function(err) {
                $('#sat-btn-end-break').prop('disabled', false);
                Notification.exception(err);
            });
        },

        updateTimerDisplay: function() {
            var display = $('#sat-timer-display');
            display.text(this.formatClock(this.remainingSeconds));
            if (this.remainingSeconds < 300) {
                display.removeClass('d-none text-warning').addClass('text-danger border-danger');
                $('#sat-timer-toggle').addClass('d-none');
            }
        },

        startTimer: function() {
            var self = this;
            if (self.timerInterval) {
                clearInterval(self.timerInterval);
            }
            self.updateTimerDisplay();

            self.timerInterval = setInterval(function() {
                if (self.remainingSeconds <= 0) {
                    clearInterval(self.timerInterval);
                    self.submitModule(true);
                    return;
                }

                self.remainingSeconds--;
                self.updateTimerDisplay();
            }, 1000);
        },

        renderQuestion: function() {
            var slots = this.attemptData.slots;
            if (!slots || slots.length === 0) {
                $('#sat-prompt-content').html('<div class="alert alert-warning">No questions found in this module.</div>');
                return;
            }

            var current = slots[this.currentIndex];
            var qnum = this.currentIndex + 1;
            var total = slots.length;

            $('#sat-question-number-badge').text('Question ' + qnum);
            $('#sat-footer-counter').text('Question ' + qnum + ' of ' + total);

            // Previous / Next button states.
            $('#sat-btn-prev').prop('disabled', this.currentIndex === 0);
            if (this.currentIndex === total - 1) {
                $('#sat-btn-next').text('Review & Submit');
            } else {
                $('#sat-btn-next').text('Next');
            }

            // Update Flag state.
            if (this.flagged[current.slot]) {
                $('#sat-flag-icon').removeClass('fa-bookmark-o').addClass('fa-bookmark text-warning');
            } else {
                $('#sat-flag-icon').removeClass('fa-bookmark text-warning').addClass('fa-bookmark-o');
            }

            // Render stimulus & prompt (including SPR directions guide in left pane).
            var hasStimulus = Boolean(current.stimulus && current.stimulus.trim() !== '');
            var sprGuideContainer = $('#sat-spr-guide-container');

            if (current.is_spr) {
                $('#sat-stimulus-pane').removeClass('d-none').addClass('col-6');
                $('#sat-question-pane').removeClass('col-12 mx-auto').addClass('col-6');
                if (hasStimulus) {
                    $('#sat-pane-header-title').text('Stimulus & Response Directions');
                    $('#sat-stimulus-content').removeClass('d-none').html(current.stimulus);
                    sprGuideContainer.html(
                        '<details class="sat-spr-guide-details mt-3 border rounded p-2 bg-light">'
                        + '<summary class="fw-semibold small text-primary" style="cursor: pointer;">'
                        + 'Student-Produced Response Directions</summary>'
                        + '<div class="mt-2">' + this.getSprDirectionsHtml() + '</div></details>'
                    ).removeClass('d-none');
                } else {
                    $('#sat-pane-header-title').text('Student-Produced Response Directions');
                    $('#sat-stimulus-content').addClass('d-none').empty();
                    sprGuideContainer.html(
                        '<div class="sat-spr-guide-card">' + this.getSprDirectionsHtml() + '</div>'
                    ).removeClass('d-none');
                }
            } else {
                sprGuideContainer.addClass('d-none').empty();
                $('#sat-pane-header-title').text('Stimulus / Passage');
                $('#sat-stimulus-content').removeClass('d-none');
                if (hasStimulus) {
                    $('#sat-stimulus-pane').removeClass('d-none').addClass('col-6');
                    $('#sat-stimulus-content').html(current.stimulus);
                    $('#sat-question-pane').removeClass('col-12 mx-auto').addClass('col-6');
                } else {
                    // Single pane (centered layout).
                    $('#sat-stimulus-pane').addClass('d-none');
                    $('#sat-stimulus-content').empty();
                    $('#sat-question-pane').removeClass('col-6').addClass('col-12 mx-auto');
                }
            }

            $('#sat-prompt-content').html(current.prompt);
            this.applyHighlights(current.slot);
            this.renderAnnotationsList(current.slot);

            // Toggle between Multiple Choice and Student-Produced Response.
            if (current.is_spr) {
                $('#sat-mcq-area').addClass('d-none');
                $('#sat-spr-area').removeClass('d-none');
                var existingSpr = this.responses[current.slot] || '';
                $('#sat-spr-input').val(existingSpr);
                this.validateAndPreviewSpr(existingSpr);
            } else {
                $('#sat-spr-area').addClass('d-none');
                $('#sat-mcq-area').removeClass('d-none');
                this.renderMcqOptions(current);
            }

            this.updateMatrixHighlight();
        },

        getSprDirectionsHtml: function() {
            return '<ul class="small lh-base ps-3 mb-3">'
                + '<li>If a question has more than one correct answer, enter only one.</li>'
                + '<li>Enter up to <strong>5 characters</strong> for positive answers and up to '
                + '<strong>6 characters</strong> (including the negative sign) for negative answers.</li>'
                + '<li>Accepted characters are digits (<code>0&ndash;9</code>), decimal point (<code>.</code>), '
                + 'fraction slash (<code>/</code>), and a leading minus sign (<code>-</code>).</li>'
                + '<li>Do <strong>not</strong> enter mixed numbers (write <code>7/2</code> or <code>3.5</code> '
                + 'instead of <code>3 1/2</code>), commas, spaces, percent signs (<code>%</code>), or currency symbols.</li>'
                + '<li>If a decimal does not fit in the allowed width, round or truncate it to fill all available '
                + 'positions (for example, <code>2/3</code> as <code>.6666</code>, <code>.6667</code>, '
                + '<code>0.666</code>, or <code>0.667</code>).</li>'
                + '</ul>'
                + '<table class="table table-sm table-bordered small mb-0 bg-white">'
                + '<thead class="table-light"><tr><th>Value</th><th>Accepted Entry</th>'
                + '<th>Not Accepted</th></tr></thead>'
                + '<tbody>'
                + '<tr><td>3.5</td><td><code>3.5</code>, <code>7/2</code></td><td><code>3 1/2</code></td></tr>'
                + '<tr><td>2/3</td><td><code>2/3</code>, <code>.6666</code>, <code>0.667</code></td>'
                + '<td><code>0.66</code></td></tr>'
                + '<tr><td>-1/4</td><td><code>-1/4</code>, <code>-0.25</code>, <code>-.25</code></td>'
                + '<td><code>-25%</code></td></tr>'
                + '</tbody></table>';
        },

        renderMcqOptions: function(slotData) {
            var area = $('#sat-mcq-area');
            area.empty();

            var optionList = (slotData.options && slotData.options.length > 0)
                ? slotData.options
                : [
                    {key: 'A', text: 'Option choice A'},
                    {key: 'B', text: 'Option choice B'},
                    {key: 'C', text: 'Option choice C'},
                    {key: 'D', text: 'Option choice D'}
                ];
            var self = this;
            var currentSlot = slotData.slot;
            var selectedChoice = this.responses[currentSlot] || null;
            var eliminatedList = this.eliminations[currentSlot] || [];

            optionList.forEach(function(opt) {
                var letter = opt.key;
                var card = $('<div class="sat-option-card d-flex align-items-center justify-content-between"></div>');
                if (selectedChoice === letter) {
                    card.addClass('selected');
                }
                if (eliminatedList.indexOf(letter) !== -1) {
                    card.addClass('eliminated');
                }

                var leftSide = $('<div class="d-flex align-items-center gap-3"></div>');
                leftSide.append('<span class="badge bg-secondary rounded-circle p-2">' + letter + '</span>');
                var textSpan = $('<span class="sat-option-text"></span>');
                textSpan.html(opt.text);
                leftSide.append(textSpan);
                card.append(leftSide);

                // Option Eliminator (ABC strikethrough button).
                var strikeBtn = $('<button class="sat-btn-strike" title="Eliminate answer">ABC</button>');
                if (eliminatedList.indexOf(letter) !== -1) {
                    strikeBtn.addClass('active');
                }

                strikeBtn.on('click', function(e) {
                    e.stopPropagation();
                    self.toggleElimination(currentSlot, letter);
                });

                card.append(strikeBtn);

                // Option selection click.
                card.on('click', function() {
                    self.selectOption(currentSlot, letter);
                });

                area.append(card);
            });
        },

        persistSlotState: function(slot) {
            var promises = Ajax.call([{
                methodname: 'mod_sat_save_slot_state',
                args: {
                    attemptid: this.config.attemptid,
                    slot: slot,
                    eliminated: JSON.stringify(this.eliminations[slot] || []),
                    annotations: JSON.stringify(this.annotations[slot] || []),
                    response: this.responses[slot] || '',
                    flagged: this.flagged[slot] ? 1 : 0
                }
            }]);
            return promises[0];
        },

        selectOption: function(slot, letter) {
            this.responses[slot] = letter;
            this.renderQuestion();
            this.renderMatrix();
            this.persistSlotState(slot);
        },

        toggleElimination: function(slot, letter) {
            if (!this.eliminations[slot]) {
                this.eliminations[slot] = [];
            }
            var idx = this.eliminations[slot].indexOf(letter);
            if (idx === -1) {
                this.eliminations[slot].push(letter);
            } else {
                this.eliminations[slot].splice(idx, 1);
            }
            this.renderQuestion();
            this.persistSlotState(slot);
        },

        toggleFlag: function() {
            var currentSlot = this.attemptData.slots[this.currentIndex].slot;
            this.flagged[currentSlot] = !this.flagged[currentSlot];
            this.renderQuestion();
            this.renderMatrix();
            this.persistSlotState(currentSlot);
        },

        handleSprInput: function() {
            var self = this;
            var input = $('#sat-spr-input');
            var val = input.val();
            var currentSlot = this.attemptData.slots[this.currentIndex].slot;
            this.responses[currentSlot] = val;
            this.validateAndPreviewSpr(val);
            this.renderMatrix();

            if (this.sprTimer) {
                clearTimeout(this.sprTimer);
            }
            this.pendingSprSlot = currentSlot;
            this.sprTimer = setTimeout(function() {
                self.pendingSprSlot = null;
                self.persistSlotState(currentSlot);
            }, 300);
        },

        validateSprFormat: function(raw) {
            var trimmed = String(raw || '').trim();
            if (trimmed === '') {
                return {valid: true, empty: true, error: '', value: null};
            }

            if (/\s/.test(trimmed)) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Mixed numbers and spaces are not allowed—use an improper fraction or decimal',
                    value: null
                };
            }

            if (!/^-?[0-9./]+$/.test(trimmed)) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Invalid character: enter digits, ., /, or a leading -',
                    value: null
                };
            }

            var isNegative = trimmed.indexOf('-') === 0;
            var maxLen = isNegative ? 6 : 5;
            if (trimmed.length > maxLen) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Exceeds max ' + maxLen + ' characters',
                    value: null
                };
            }

            var dots = (trimmed.match(/\./g) || []).length;
            var slashes = (trimmed.match(/\//g) || []).length;
            if (dots > 1 || slashes > 1) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Only one decimal point or one fraction slash is allowed',
                    value: null
                };
            }
            if (dots > 0 && slashes > 0) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Cannot combine decimal point and fraction slash',
                    value: null
                };
            }

            if (slashes === 1) {
                if (trimmed[0] === '/' || trimmed.slice(-1) === '/' || trimmed.indexOf('-/') !== -1) {
                    return {
                        valid: false,
                        empty: false,
                        error: 'Enter digits on both sides of /',
                        value: null
                    };
                }
                var parts = trimmed.split('/');
                var num = Number(parts[0]);
                var den = Number(parts[1]);
                if (den === 0) {
                    return {
                        valid: false,
                        empty: false,
                        error: 'Cannot divide by zero',
                        value: null
                    };
                }
                return {valid: true, empty: false, error: '', value: num / den};
            }

            if (trimmed === '-' || trimmed === '.' || trimmed === '-.') {
                return {
                    valid: false,
                    empty: false,
                    error: 'Enter at least one digit',
                    value: null
                };
            }

            var parsed = Number(trimmed);
            if (isNaN(parsed) || !isFinite(parsed)) {
                return {
                    valid: false,
                    empty: false,
                    error: 'Enter a valid number',
                    value: null
                };
            }

            return {valid: true, empty: false, error: '', value: parsed};
        },

        validateAndPreviewSpr: function(val) {
            var input = $('#sat-spr-input');
            var msgEl = $('#sat-spr-validation-msg');
            var preview = $('#sat-spr-fraction-preview');
            var check = this.validateSprFormat(val);

            input.removeClass('is-invalid is-valid');
            msgEl.removeClass('text-danger text-success').addClass('d-none').text('');
            preview.text('');

            if (check.empty) {
                return;
            }

            if (!check.valid) {
                input.addClass('is-invalid');
                msgEl.addClass('text-danger').removeClass('d-none').text(check.error);
                return;
            }

            input.addClass('is-valid');
            if (String(val).indexOf('/') !== -1 && check.value !== null && isFinite(check.value)) {
                var rounded = Number(check.value.toFixed(4));
                var prefix = (Math.abs(check.value - rounded) < 1e-9) ? '= ' : '≈ ';
                preview.text(String(val).trim() + ' ' + prefix + rounded);
            }
        },

        renderMatrix: function() {
            var grid = $('#sat-matrix-grid');
            grid.empty();

            var self = this;
            var slots = this.attemptData.slots || [];

            slots.forEach(function(s, idx) {
                var btn = $('<button class="sat-matrix-btn">' + (idx + 1) + '</button>');

                if (idx === self.currentIndex) {
                    btn.addClass('current');
                }
                if (self.responses[s.slot] && self.responses[s.slot].trim() !== '') {
                    btn.addClass('answered');
                }
                if (self.flagged[s.slot]) {
                    btn.addClass('flagged');
                }

                btn.on('click', function() {
                    self.currentIndex = idx;
                    self.renderQuestion();
                    $('#sat-matrix-drawer').addClass('d-none');
                });

                grid.append(btn);
            });
        },

        updateMatrixHighlight: function() {
            var btns = $('#sat-matrix-grid .sat-matrix-btn');
            btns.removeClass('current');
            btns.eq(this.currentIndex).addClass('current');
        },

        handleNext: function() {
            var total = this.attemptData.slots.length;
            if (this.currentIndex < total - 1) {
                this.currentIndex++;
                this.renderQuestion();
            } else {
                // End of module review.
                this.showModuleReview();
            }
        },

        handlePrev: function() {
            if (this.currentIndex > 0) {
                this.currentIndex--;
                this.renderQuestion();
            }
        },

        showModuleReview: function() {
            var slots = (this.attemptData && this.attemptData.slots) ? this.attemptData.slots : [];
            var total = slots.length;
            var answeredCount = 0;
            var flaggedCount = 0;
            var self = this;

            $('#sat-matrix-drawer, #sat-directions-panel, #sat-options-panel').addClass('d-none');

            var grid = $('#sat-review-grid');
            grid.empty();

            slots.forEach(function(s, idx) {
                var isAnswered = Boolean(self.responses[s.slot] && self.responses[s.slot].trim() !== '');
                var isFlagged = Boolean(self.flagged[s.slot]);
                if (isAnswered) {
                    answeredCount++;
                }
                if (isFlagged) {
                    flaggedCount++;
                }

                var tile = $('<button type="button" class="sat-review-tile"></button>');
                if (isAnswered) {
                    tile.addClass('answered');
                }
                if (isFlagged) {
                    tile.addClass('flagged');
                }
                if (idx === self.currentIndex) {
                    tile.addClass('current');
                }
                tile.append($('<span class="sat-review-num"></span>').text(idx + 1));
                if (isFlagged) {
                    tile.append('<i class="fa fa-bookmark sat-review-flag-icon" aria-hidden="true"></i>');
                }
                tile.attr('aria-label', 'Question ' + (idx + 1)
                    + (isAnswered ? ', answered' : ', unanswered')
                    + (isFlagged ? ', marked for review' : ''));
                tile.on('click', function() {
                    if (self.isSubmitting) {
                        return;
                    }
                    self.currentIndex = idx;
                    $('#sat-review-overlay').addClass('d-none');
                    self.renderQuestion();
                    self.renderMatrix();
                });
                grid.append(tile);
            });

            var unanswered = total - answeredCount;
            var sectionLabel = (this.attemptData && this.attemptData.section === 'rw')
                ? 'Reading and Writing' : 'Math';
            var stageLabel = (this.attemptData && this.attemptData.stage === 'm1')
                ? 'Module 1 of 2' : 'Module 2 of 2';

            $('#sat-review-subtitle').text(sectionLabel + ' • ' + stageLabel);
            $('#sat-review-answered-count').text(answeredCount);
            $('#sat-review-unanswered-count').text(unanswered);
            $('#sat-review-flagged-count').text(flaggedCount);

            var warnEl = $('#sat-review-unanswered-warning');
            if (unanswered > 0) {
                warnEl.text('You have ' + unanswered + ' unanswered question(s). '
                    + 'Select any question number below to return and answer it before submitting.')
                    .removeClass('d-none');
            } else {
                warnEl.addClass('d-none').text('');
            }

            $('#sat-transition-status').addClass('d-none').text('');
            $('#sat-btn-review-back, #sat-btn-review-confirm').prop('disabled', false);
            $('#sat-review-overlay').removeClass('d-none');
        },

        submitModule: function(isAutoExpired) {
            var self = this;
            if (self.isSubmitting) {
                return;
            }
            self.isSubmitting = true;

            if (self.timerInterval) {
                clearInterval(self.timerInterval);
                self.timerInterval = null;
            }

            $('#sat-matrix-drawer, #sat-calculator-panel, #sat-directions-panel, #sat-options-panel')
                .addClass('d-none');
            this.closeFormulasModal();
            this.closeAnnotatePanel();

            $('#sat-review-overlay').removeClass('d-none');
            $('#sat-btn-review-back, #sat-btn-review-confirm').prop('disabled', true);
            $('#sat-transition-status')
                .text(isAutoExpired
                    ? 'Time expired — Submitting module responses...'
                    : 'Submitting module responses...')
                .removeClass('d-none');

            var doSubmit = function() {
                var promises = Ajax.call([{
                    methodname: 'mod_sat_submit_module',
                    args: {
                        attemptid: self.config.attemptid,
                        modattemptid: self.attemptData.modattemptid
                    }
                }]);

                promises[0].done(function(res) {
                    if (res.nextaction === 'finish') {
                        $('#sat-transition-status').text(
                            'Exam Complete — Opening your diagnostic score report...'
                        );
                        window.location.href = M.cfg.wwwroot
                            + '/mod/sat/diagnostic.php?attemptid=' + self.config.attemptid;
                    } else if (res.nextaction === 'pause') {
                        $('#sat-transition-status').text(
                            'Module Submitted — Returning to exam overview...'
                        );
                        window.location.href = M.cfg.wwwroot + '/mod/sat/view.php?id=' + self.config.cmid;
                    } else if (res.nextaction === 'break') {
                        $('#sat-transition-status').text(
                            'Reading and Writing Complete — Starting scheduled section break...'
                        );
                        self.isSubmitting = false;
                        self.loadState();
                    } else {
                        $('#sat-transition-status').text('Module Submitted — Preparing next stage...');
                        self.isSubmitting = false;
                        self.loadState();
                    }
                }).fail(function(err) {
                    self.isSubmitting = false;
                    $('#sat-btn-review-back, #sat-btn-review-confirm').prop('disabled', false);
                    $('#sat-transition-status').addClass('d-none').text('');
                    Notification.exception(err);
                });
            };

            if (self.sprTimer && self.pendingSprSlot) {
                clearTimeout(self.sprTimer);
                self.sprTimer = null;
                var slotToFlush = self.pendingSprSlot;
                self.pendingSprSlot = null;
                self.persistSlotState(slotToFlush).always(doSubmit);
            } else {
                doSubmit();
            }
        },

        openFormulasModal: function() {
            var modalEl = document.getElementById('sat-formulas-modal');
            if (!modalEl) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
            } else {
                $('#sat-formulas-modal')
                    .addClass('show d-block')
                    .attr('aria-modal', 'true')
                    .removeAttr('aria-hidden');
                $('body').addClass('modal-open');
            }
        },

        closeFormulasModal: function() {
            var modalEl = document.getElementById('sat-formulas-modal');
            if (!modalEl) {
                return;
            }
            if (window.bootstrap && window.bootstrap.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }
            $('#sat-formulas-modal')
                .removeClass('show d-block')
                .attr('aria-hidden', 'true')
                .removeAttr('aria-modal');
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open').css('overflow', '');
        },

        toggleAnnotatePanel: function() {
            var panel = $('#sat-annotate-panel');
            $('#sat-options-panel, #sat-directions-panel').addClass('d-none');
            if (panel.hasClass('d-none')) {
                panel.removeClass('d-none');
                this.annotateMode = true;
                var current = (this.attemptData && this.attemptData.slots)
                    ? this.attemptData.slots[this.currentIndex]
                    : null;
                if (current) {
                    this.renderAnnotationsList(current.slot);
                }
            } else {
                panel.addClass('d-none');
                this.annotateMode = false;
            }
            this.updateAnnotateButtonState();
        },

        closeAnnotatePanel: function() {
            $('#sat-annotate-panel').addClass('d-none');
            this.annotateMode = false;
            this.updateAnnotateButtonState();
        },

        updateAnnotateButtonState: function() {
            var btn = $('#sat-btn-annotate');
            if (this.annotateMode) {
                btn.removeClass('btn-outline-success').addClass('btn-success text-white');
            } else {
                btn.removeClass('btn-success text-white').addClass('btn-outline-success');
            }
        },

        captureCurrentSelection: function(silentIfEmpty) {
            var current = (this.attemptData && this.attemptData.slots)
                ? this.attemptData.slots[this.currentIndex]
                : null;
            if (!current) {
                return;
            }
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
                if (!silentIfEmpty) {
                    Notification.alert('Annotate', 'Please select text in the passage or question first.');
                }
                return;
            }
            var range = sel.getRangeAt(0);
            var selectedText = sel.toString().trim();
            if (!selectedText) {
                return;
            }
            var stimulusEl = document.getElementById('sat-stimulus-content');
            var promptEl = document.getElementById('sat-prompt-content');
            var targetName = null;
            var rootEl = null;
            if (stimulusEl && stimulusEl.contains(range.startContainer) && stimulusEl.contains(range.endContainer)) {
                targetName = 'stimulus';
                rootEl = stimulusEl;
            } else if (promptEl && promptEl.contains(range.startContainer) && promptEl.contains(range.endContainer)) {
                targetName = 'prompt';
                rootEl = promptEl;
            } else {
                if (!silentIfEmpty) {
                    Notification.alert('Annotate', 'Please select text inside the reading passage or question prompt.');
                }
                return;
            }

            var start = this.getPointOffset(rootEl, range.startContainer, range.startOffset);
            var end = this.getPointOffset(rootEl, range.endContainer, range.endOffset);
            if (end <= start) {
                return;
            }

            var slot = current.slot;
            if (!this.annotations[slot]) {
                this.annotations[slot] = [];
            }
            var exactSlice = (rootEl.textContent || '').slice(start, end);
            this.annotations[slot].push({
                id: 'hl_' + Date.now() + '_' + Math.floor(Math.random() * 10000),
                type: 'highlight',
                target: targetName,
                start: start,
                end: end,
                text: exactSlice || selectedText,
                color: this.highlightColor || 'yellow'
            });

            if (sel.removeAllRanges) {
                sel.removeAllRanges();
            }
            this.applyHighlights(slot);
            this.renderAnnotationsList(slot);
            this.persistSlotState(slot);
        },

        getPointOffset: function(rootEl, targetContainer, targetOffset) {
            var walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, null);
            var total = 0;
            var node;
            if (targetContainer.nodeType === 3) {
                while ((node = walker.nextNode()) !== null) {
                    if (node === targetContainer) {
                        return total + targetOffset;
                    }
                    total += node.nodeValue.length;
                }
                return total;
            }
            var pointRange = document.createRange();
            pointRange.setStart(targetContainer, targetOffset);
            pointRange.collapse(true);
            while ((node = walker.nextNode()) !== null) {
                var nodeRange = document.createRange();
                nodeRange.selectNodeContents(node);
                if (pointRange.compareBoundaryPoints(Range.START_TO_START, nodeRange) <= 0) {
                    return total;
                }
                total += node.nodeValue.length;
            }
            return total;
        },

        clearMarkElements: function(rootEl) {
            if (!rootEl) {
                return;
            }
            var marks = rootEl.querySelectorAll('mark.sat-hl');
            for (var i = 0; i < marks.length; i++) {
                var mark = marks[i];
                var parent = mark.parentNode;
                while (mark.firstChild) {
                    parent.insertBefore(mark.firstChild, mark);
                }
                parent.removeChild(mark);
            }
            rootEl.normalize();
        },

        wrapTextInterval: function(rootEl, start, end, id, color) {
            var walker = document.createTreeWalker(rootEl, NodeFilter.SHOW_TEXT, null);
            var segments = [];
            var offset = 0;
            var node;
            while ((node = walker.nextNode()) !== null) {
                var len = node.nodeValue.length;
                var nodeEnd = offset + len;
                if (nodeEnd > start && offset < end) {
                    segments.push({
                        node: node,
                        startOffset: Math.max(0, start - offset),
                        endOffset: Math.min(len, end - offset)
                    });
                }
                offset = nodeEnd;
                if (offset >= end) {
                    break;
                }
            }
            var safeColor = (color === 'blue' || color === 'pink') ? color : 'yellow';
            segments.forEach(function(seg) {
                var targetNode = seg.node;
                if (!targetNode.parentNode) {
                    return;
                }
                if (seg.endOffset < targetNode.nodeValue.length) {
                    targetNode.splitText(seg.endOffset);
                }
                if (seg.startOffset > 0) {
                    targetNode = targetNode.splitText(seg.startOffset);
                }
                var mark = document.createElement('mark');
                mark.className = 'sat-hl sat-hl-' + safeColor;
                mark.setAttribute('data-hl-id', id);
                mark.setAttribute('title', 'Click while Annotate is active to remove highlight');
                targetNode.parentNode.insertBefore(mark, targetNode);
                mark.appendChild(targetNode);
            });
        },

        applyHighlights: function(slot) {
            var stimulusEl = document.getElementById('sat-stimulus-content');
            var promptEl = document.getElementById('sat-prompt-content');
            this.clearMarkElements(stimulusEl);
            this.clearMarkElements(promptEl);

            var list = this.annotations[slot] || [];
            var highlights = list.filter(function(item) {
                return item && item.type === 'highlight';
            });
            highlights.sort(function(a, b) {
                return (b.start || 0) - (a.start || 0);
            });

            var self = this;
            highlights.forEach(function(hl) {
                var rootEl = (hl.target === 'stimulus') ? stimulusEl : promptEl;
                if (!rootEl) {
                    return;
                }
                var fullText = rootEl.textContent || '';
                var start = Number(hl.start);
                var end = Number(hl.end);
                if (isNaN(start) || isNaN(end) || end <= start || fullText.slice(start, end) !== hl.text) {
                    if (hl.text) {
                        var foundIdx = fullText.indexOf(hl.text);
                        if (foundIdx === -1) {
                            return;
                        }
                        start = foundIdx;
                        end = foundIdx + hl.text.length;
                    } else {
                        return;
                    }
                }
                self.wrapTextInterval(rootEl, start, end, hl.id, hl.color);
            });
        },

        saveCurrentNote: function() {
            var current = (this.attemptData && this.attemptData.slots)
                ? this.attemptData.slots[this.currentIndex]
                : null;
            if (!current) {
                return;
            }
            var noteInput = $('#sat-note-input');
            var noteText = (noteInput.val() || '').trim();
            if (!noteText) {
                return;
            }
            var slot = current.slot;
            if (!this.annotations[slot]) {
                this.annotations[slot] = [];
            }
            this.annotations[slot].push({
                id: 'note_' + Date.now() + '_' + Math.floor(Math.random() * 10000),
                type: 'note',
                text: noteText
            });
            noteInput.val('');
            this.renderAnnotationsList(slot);
            this.persistSlotState(slot);
        },

        removeAnnotation: function(annId) {
            var current = (this.attemptData && this.attemptData.slots)
                ? this.attemptData.slots[this.currentIndex]
                : null;
            if (!current) {
                return;
            }
            var slot = current.slot;
            var list = this.annotations[slot] || [];
            this.annotations[slot] = list.filter(function(item) {
                return item.id !== annId;
            });
            this.applyHighlights(slot);
            this.renderAnnotationsList(slot);
            this.persistSlotState(slot);
        },

        clearCurrentSlotAnnotations: function() {
            var current = (this.attemptData && this.attemptData.slots)
                ? this.attemptData.slots[this.currentIndex]
                : null;
            if (!current) {
                return;
            }
            var slot = current.slot;
            if (!this.annotations[slot] || this.annotations[slot].length === 0) {
                return;
            }
            this.annotations[slot] = [];
            this.applyHighlights(slot);
            this.renderAnnotationsList(slot);
            this.persistSlotState(slot);
        },

        renderAnnotationsList: function(slot) {
            var container = $('#sat-annotations-list');
            var badge = $('#sat-annotate-count');
            var list = this.annotations[slot] || [];

            if (list.length > 0) {
                badge.text(list.length).removeClass('d-none');
            } else {
                badge.addClass('d-none');
            }

            if (!container.length) {
                return;
            }
            container.empty();
            if (list.length === 0) {
                container.append('<div class="text-muted small fst-italic">No highlights or notes on this question yet.</div>');
                return;
            }

            var self = this;
            list.forEach(function(item) {
                var row = $('<div class="sat-annotation-item d-flex justify-content-between align-items-start gap-2"></div>');
                var left = $('<div class="flex-grow-1 text-break"></div>');
                var kindLabel = (item.type === 'note') ? 'Note' : 'Highlight (' + (item.color || 'yellow') + ')';
                var badgeClass = (item.type === 'note') ? 'badge bg-info text-dark me-1' : 'badge bg-warning text-dark me-1';
                left.append($('<span class="' + badgeClass + '"></span>').text(kindLabel));
                var snippet = (item.text || '').length > 90 ? item.text.slice(0, 90) + '...' : (item.text || '');
                left.append($('<span></span>').text(snippet));

                var delBtn = $('<button type="button" class="btn btn-xs btn-outline-danger py-0 px-1" title="Remove"><i class="fa fa-times"></i></button>');
                delBtn.on('click', function() {
                    self.removeAnnotation(item.id);
                });

                row.append(left).append(delBtn);
                container.append(row);
            });
        },

        setupPanelDrag: function(panelSelector, handleSelector) {
            var panel = $(panelSelector);
            var handle = $(handleSelector);
            if (!panel.length || !handle.length) {
                return;
            }
            var isDragging = false;
            var startPos = {left: 0, top: 0, mouseX: 0, mouseY: 0};
            handle.on('mousedown', function(e) {
                if ($(e.target).closest('button').length) {
                    return;
                }
                isDragging = true;
                var pos = panel.position();
                startPos.left = pos.left;
                startPos.top = pos.top;
                startPos.mouseX = e.clientX;
                startPos.mouseY = e.clientY;
            });
            $(document).on('mousemove', function(e) {
                if (isDragging) {
                    panel.css({
                        left: Math.max(0, startPos.left + (e.clientX - startPos.mouseX)) + 'px',
                        top: Math.max(0, startPos.top + (e.clientY - startPos.mouseY)) + 'px',
                        right: 'auto'
                    });
                }
            });
            $(document).on('mouseup', function() {
                isDragging = false;
            });
        },

        applyFontSize: function(size) {
            var app = $('#sat-simulator-app');
            app.removeClass('sat-font-lg sat-font-xl');
            $('.sat-font-btn').removeClass('active');
            $('.sat-font-btn[data-size="' + size + '"]').addClass('active');
            if (size === 'lg') {
                app.addClass('sat-font-lg');
            } else if (size === 'xl') {
                app.addClass('sat-font-xl');
            }
            try {
                window.localStorage.setItem('mod_sat_font_size', size);
            } catch (e) {
                // Ignore storage restrictions.
            }
        },

        applyHighContrast: function(enabled) {
            var app = $('#sat-simulator-app');
            if (enabled) {
                app.addClass('sat-high-contrast');
            } else {
                app.removeClass('sat-high-contrast');
            }
            $('#sat-toggle-contrast').prop('checked', !!enabled);
            try {
                window.localStorage.setItem('mod_sat_high_contrast', enabled ? '1' : '0');
            } catch (e) {
                // Ignore storage restrictions.
            }
        },

        restoreAccessibilityPrefs: function() {
            try {
                var size = window.localStorage.getItem('mod_sat_font_size') || 'normal';
                var contrast = window.localStorage.getItem('mod_sat_high_contrast') === '1';
                this.applyFontSize(size);
                this.applyHighContrast(contrast);
            } catch (e) {
                // Ignore storage restrictions.
            }
        }
    };

    return Runner;
});
