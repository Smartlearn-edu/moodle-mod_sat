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
 * Built-in self-hosted graphing and scientific calculator for mod_sat.
 *
 * @module     mod_sat/calculator
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    'use strict';

    var Calculator = {
        currentExpression: '0',
        mode: 'scientific', // 'scientific' or 'graphing'

        init: function() {
            var self = this;

            // Calculator toggle button.
            $('#sat-btn-calculator').on('click', function(e) {
                e.preventDefault();
                $('#sat-calculator-panel').toggleClass('d-none');
            });

            // Close button.
            $('#sat-calc-close').on('click', function() {
                $('#sat-calculator-panel').addClass('d-none');
            });

            // Mode toggle (Scientific vs Graphing).
            $('#sat-calc-mode-toggle').on('click', function() {
                if (self.mode === 'scientific') {
                    self.mode = 'graphing';
                    $(this).text('Calc');
                    $('#sat-calc-scientific').addClass('d-none');
                    $('#sat-calc-graphing').removeClass('d-none');
                    self.renderGraph();
                } else {
                    self.mode = 'scientific';
                    $(this).text('Graph');
                    $('#sat-calc-graphing').addClass('d-none');
                    $('#sat-calc-scientific').removeClass('d-none');
                }
            });

            // Scientific button clicks.
            $('.calc-btn').on('click', function() {
                var val = $(this).data('val');
                self.handleButton(val);
            });

            // Graph plotting.
            $('#sat-graph-plot-btn').on('click', function() {
                self.renderGraph();
            });

            // Draggable panel setup.
            self.setupDraggable();
        },

        handleButton: function(val) {
            var screen = $('#sat-calc-screen');

            if (val === 'C') {
                this.currentExpression = '0';
            } else if (val === 'DEL') {
                if (this.currentExpression.length > 1) {
                    this.currentExpression = this.currentExpression.slice(0, -1);
                } else {
                    this.currentExpression = '0';
                }
            } else if (val === '=') {
                try {
                    // Safe basic arithmetic evaluation.
                    var expr = this.currentExpression
                        .replace(/sin/g, 'Math.sin')
                        .replace(/cos/g, 'Math.cos')
                        .replace(/tan/g, 'Math.tan')
                        .replace(/sqrt/g, 'Math.sqrt')
                        .replace(/log/g, 'Math.log10')
                        .replace(/ln/g, 'Math.log')
                        .replace(/pi/g, 'Math.PI')
                        .replace(/e/g, 'Math.E')
                        .replace(/\^/g, '**');

                    /* eslint-disable no-eval */
                    var result = eval(expr);
                    /* eslint-enable no-eval */

                    this.currentExpression = (typeof result === 'number' && !isNaN(result))
                        ? String(Number(result.toFixed(6)))
                        : 'Error';
                } catch (e) {
                    this.currentExpression = 'Error';
                }
            } else {
                if (this.currentExpression === '0' || this.currentExpression === 'Error') {
                    this.currentExpression = String(val);
                } else {
                    this.currentExpression += String(val);
                }
            }

            screen.val(this.currentExpression);
        },

        renderGraph: function() {
            var canvas = document.getElementById('sat-graph-canvas');
            if (!canvas) {
                return;
            }
            var ctx = canvas.getContext('2d');
            var width = canvas.width;
            var height = canvas.height;

            // Clear canvas.
            ctx.clearRect(0, 0, width, height);

            var originX = width / 2;
            var originY = height / 2;
            var scale = 20; // 20 pixels per unit.

            // Draw grid lines.
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;

            for (var x = 0; x < width; x += scale) {
                ctx.beginPath();
                ctx.moveTo(x, 0);
                ctx.lineTo(x, height);
                ctx.stroke();
            }
            for (var y = 0; y < height; y += scale) {
                ctx.beginPath();
                ctx.moveTo(0, y);
                ctx.lineTo(width, y);
                ctx.stroke();
            }

            // Draw Cartesian axes.
            ctx.strokeStyle = '#64748b';
            ctx.lineWidth = 2;

            ctx.beginPath();
            ctx.moveTo(0, originY);
            ctx.lineTo(width, originY);
            ctx.stroke();

            ctx.beginPath();
            ctx.moveTo(originX, 0);
            ctx.lineTo(originX, height);
            ctx.stroke();

            // Parse and plot function y = f(x).
            var rawFn = $('#sat-graph-function').val() || 'x^2 - 4';
            var jsFn = rawFn
                .replace(/sin/g, 'Math.sin')
                .replace(/cos/g, 'Math.cos')
                .replace(/tan/g, 'Math.tan')
                .replace(/sqrt/g, 'Math.sqrt')
                .replace(/\^/g, '**');

            ctx.strokeStyle = '#2563eb';
            ctx.lineWidth = 2.5;
            ctx.beginPath();

            var firstPoint = true;
            for (var px = 0; px <= width; px += 2) {
                var mathX = (px - originX) / scale;
                try {
                    var expr = jsFn.replace(/x/g, '(' + mathX + ')');
                    /* eslint-disable no-eval */
                    var mathY = eval(expr);
                    /* eslint-enable no-eval */

                    if (typeof mathY === 'number' && !isNaN(mathY) && isFinite(mathY)) {
                        var py = originY - (mathY * scale);
                        if (firstPoint) {
                            ctx.moveTo(px, py);
                            firstPoint = false;
                        } else {
                            ctx.lineTo(px, py);
                        }
                    } else {
                        firstPoint = true;
                    }
                } catch (e) {
                    firstPoint = true;
                }
            }
            ctx.stroke();
        },

        setupDraggable: function() {
            var panel = $('#sat-calculator-panel');
            var handle = $('#sat-calc-drag-handle');
            var isDragging = false;
            var offset = {x: 0, y: 0};

            handle.on('mousedown', function(e) {
                isDragging = true;
                offset.x = e.clientX - panel.offset().left;
                offset.y = e.clientY - panel.offset().top;
            });

            $(document).on('mousemove', function(e) {
                if (isDragging) {
                    panel.css({
                        left: (e.clientX - offset.x) + 'px',
                        top: (e.clientY - offset.y) + 'px',
                        right: 'auto',
                    });
                }
            });

            $(document).on('mouseup', function() {
                isDragging = false;
            });
        }
    };

    return Calculator;
});
