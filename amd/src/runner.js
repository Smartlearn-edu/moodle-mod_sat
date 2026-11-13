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
        annotations: {},
        annotateMode: false,
        highlightColor: 'yellow',

        init: function(config) {
            var self = this;
            this.config = config;

            // Initialize built-in calculator.
            Calculator.init();

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
                $('#sat-annotate-panel').addClass('d-none');
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

            // Close open modals/drawers on Escape key.
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    if ($('#sat-formulas-modal').hasClass('show')) {
                        self.closeFormulasModal();
                    }
                    if (!$('#sat-annotate-panel').hasClass('d-none')) {
                        self.closeAnnotatePanel();
                    }
                    $('#sat-options-panel').addClass('d-none');
                    $('#sat-matrix-drawer').addClass('d-none');
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
                self.attemptData = data;
                self.remainingSeconds = data.timeremaining;
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
            this.applyHighlights(current.slot);
            this.renderAnnotationsList(current.slot);

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
            this.updateFractionPreview(val);
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
            var total = (this.attemptData && this.attemptData.slots) ? this.attemptData.slots.length : 0;
            var answeredCount = 0;
            var self = this;
            if (this.attemptData && this.attemptData.slots) {
                this.attemptData.slots.forEach(function(s) {
                    if (self.responses[s.slot] && self.responses[s.slot].trim() !== '') {
                        answeredCount++;
                    }
                });
            }
            var unanswered = total - answeredCount;
            var msg = 'Module Summary:\n• Answered: ' + answeredCount + ' of ' + total + ' questions.';
            if (unanswered > 0) {
                msg += '\n• Unanswered: ' + unanswered + ' question(s).';
            }
            msg += '\n\nAre you sure you want to submit this module now?\n(Click OK to Submit, or Cancel to keep working)';

            var confirmSubmit = confirm(msg);
            if (confirmSubmit) {
                this.submitModule();
            }
        },

        submitModule: function() {
            var self = this;
            if (self.timerInterval) {
                clearInterval(self.timerInterval);
            }

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
            $('#sat-options-panel').addClass('d-none');
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
