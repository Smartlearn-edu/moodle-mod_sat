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
        remainingSeconds: 0,
        responses: {},
        eliminations: {},
        flagged: {},

        init: function(config) {
            this.config = config;

            // Initialize built-in calculator.
            Calculator.init();

            // Formula modal trigger.
            $('#sat-btn-formulas').on('click', function(e) {
                e.preventDefault();
                var modalEl = document.getElementById('sat-formulas-modal');
                if (modalEl && window.bootstrap) {
                    var modal = new window.bootstrap.Modal(modalEl);
                    modal.show();
                } else {
                    $('#sat-formulas-modal').toggleClass('show d-block');
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
                self.attemptData = data;
                self.remainingSeconds = data.timeremaining;

                // Update headers.
                var sectionLabel = (data.section === 'rw') ? 'Reading and Writing' : 'Math';
                var stageLabel = (data.stage === 'm1') ? 'Module 1 of 2' : 'Module 2 of 2';

                $('#sat-stage-badge').text(stageLabel);
                $('#sat-section-title').text(sectionLabel);

                // Start timer.
                self.startTimer();

                // Render first question.
                self.currentIndex = 0;
                self.renderQuestion();
                self.renderMatrix();
            }).fail(Notification.exception);
        },

        startTimer: function() {
            var self = this;
            if (self.timerInterval) {
                clearInterval(self.timerInterval);
            }

            self.timerInterval = setInterval(function() {
                if (self.remainingSeconds <= 0) {
                    clearInterval(self.timerInterval);
                    self.submitModule();
                    return;
                }

                self.remainingSeconds--;
                var minutes = Math.floor(self.remainingSeconds / 60);
                var seconds = self.remainingSeconds % 60;
                var padM = (minutes < 10) ? '0' + minutes : minutes;
                var padS = (seconds < 10) ? '0' + seconds : seconds;

                var display = $('#sat-timer-display');
                display.text(padM + ':' + padS);

                // Turn red and force visible when < 5 minutes (300 seconds).
                if (self.remainingSeconds < 300) {
                    display.removeClass('d-none text-warning').addClass('text-danger border-danger');
                    $('#sat-timer-toggle').addClass('d-none');
                }
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

            // Render stimulus & prompt.
            if (current.stimulus && current.stimulus.trim() !== '') {
                $('#sat-stimulus-pane').removeClass('d-none').addClass('col-6');
                $('#sat-stimulus-content').html(current.stimulus);
                $('#sat-question-pane').removeClass('col-12').addClass('col-6');
            } else {
                // Single pane (Math centered layout).
                $('#sat-stimulus-pane').addClass('d-none');
                $('#sat-question-pane').removeClass('col-6').addClass('col-12 mx-auto');
            }

            $('#sat-prompt-content').html(current.prompt);

            // Toggle between Multiple Choice and Student-Produced Response.
            if (current.is_spr) {
                $('#sat-mcq-area').addClass('d-none');
                $('#sat-spr-area').removeClass('d-none');
                var existingSpr = this.responses[current.slot] || '';
                $('#sat-spr-input').val(existingSpr);
                this.updateFractionPreview(existingSpr);
            } else {
                $('#sat-spr-area').addClass('d-none');
                $('#sat-mcq-area').removeClass('d-none');
                this.renderMcqOptions(current);
            }

            this.updateMatrixHighlight();
        },

        renderMcqOptions: function(slotData) {
            var area = $('#sat-mcq-area');
            area.empty();

            var letters = ['A', 'B', 'C', 'D'];
            var self = this;
            var currentSlot = slotData.slot;
            var selectedChoice = this.responses[currentSlot] || null;
            var eliminatedList = this.eliminations[currentSlot] || [];

            letters.forEach(function(letter, idx) {
                var card = $('<div class="sat-option-card d-flex align-items-center justify-content-between"></div>');
                if (selectedChoice === letter) {
                    card.addClass('selected');
                }
                if (eliminatedList.indexOf(letter) !== -1) {
                    card.addClass('eliminated');
                }

                var leftSide = $('<div class="d-flex align-items-center gap-3"></div>');
                leftSide.append('<span class="badge bg-secondary rounded-circle p-2">' + letter + '</span>');
                leftSide.append('<span class="sat-option-text">Option choice ' + letter + '</span>');
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

        selectOption: function(slot, letter) {
            this.responses[slot] = letter;
            this.renderQuestion();
            this.renderMatrix();
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

            // Autosave slot state to backend.
            Ajax.call([{
                methodname: 'mod_sat_save_slot_state',
                args: {
                    attemptid: this.config.attemptid,
                    slot: slot,
                    eliminated: JSON.stringify(this.eliminations[slot]),
                    annotations: '[]'
                }
            }]);
        },

        toggleFlag: function() {
            var currentSlot = this.attemptData.slots[this.currentIndex].slot;
            this.flagged[currentSlot] = !this.flagged[currentSlot];
            this.renderQuestion();
            this.renderMatrix();
        },

        handleSprInput: function() {
            var input = $('#sat-spr-input');
            var val = input.val();
            var currentSlot = this.attemptData.slots[this.currentIndex].slot;
            this.responses[currentSlot] = val;
            this.updateFractionPreview(val);
            this.renderMatrix();
        },

        updateFractionPreview: function(val) {
            var preview = $('#sat-spr-fraction-preview');
            if (val.indexOf('/') !== -1) {
                var parts = val.split('/');
                if (parts.length === 2 && parts[1] !== '') {
                    preview.text('Parsed Fraction: ' + parts[0] + ' ÷ ' + parts[1]);
                } else {
                    preview.text('');
                }
            } else {
                preview.text('');
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
            var confirmSubmit = confirm('You have reached the end of this module. Would you like to review your questions or submit the module now? Click OK to Submit.');
            if (confirmSubmit) {
                this.submitModule();
            }
        },

        submitModule: function() {
            var self = this;
            if (self.timerInterval) {
                clearInterval(self.timerInterval);
            }

            var promises = Ajax.call([{
                methodname: 'mod_sat_submit_module',
                args: {
                    attemptid: self.config.attemptid,
                    modattemptid: self.attemptData.modattemptid
                }
            }]);

            promises[0].done(function(res) {
                if (res.nextaction === 'finish') {
                    alert('Congratulations! You have completed the SAT exam simulation. Redirecting to your results...');
                    window.location.href = M.cfg.wwwroot + '/mod/sat/view.php?id=' + self.config.cmid;
                } else if (res.nextaction === 'pause') {
                    alert('Module submitted! Since this exam is in Paced Homework mode, your route has been locked in. You can resume Module 2 now or return later.');
                    window.location.href = M.cfg.wwwroot + '/mod/sat/view.php?id=' + self.config.cmid;
                } else {
                    alert('Module 1 submitted! Proceeding to Module 2...');
                    window.location.reload();
                }
            }).fail(Notification.exception);
        }
    };

    return Runner;
});
