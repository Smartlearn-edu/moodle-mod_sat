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
        angleMode: 'RAD', // 'RAD' or 'DEG'
        graphScale: 20, // Pixels per unit.

        init: function(config) {
            var self = this;
            var enableGraphing = !config || config.enableGraphingCalc !== false;
            var enableScientific = !config || config.enableScientificCalc !== false;

            if (!enableGraphing && !enableScientific) {
                $('#sat-btn-calculator').addClass('d-none');
                $('#sat-calculator-panel').addClass('d-none');
                return;
            }

            if (!enableGraphing) {
                $('#sat-calc-mode-toggle').addClass('d-none');
            } else if (!enableScientific) {
                self.mode = 'graphing';
                $('#sat-calc-mode-toggle').addClass('d-none');
                $('#sat-calc-scientific').addClass('d-none');
                $('#sat-calc-graphing').removeClass('d-none');
                self.renderGraph();
            }

            // Calculator toggle button.
            $('#sat-btn-calculator').on('click', function(e) {
                e.preventDefault();
                $('#sat-calculator-panel').toggleClass('d-none');
                if (self.mode === 'graphing' && !$('#sat-calculator-panel').hasClass('d-none')) {
                    self.renderGraph();
                }
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

            // Keyboard input support in scientific mode.
            $('#sat-calc-screen').on('focus', function() {
                var current = $(this).val();
                if (current === '0' || current === 'Error') {
                    this.select();
                }
            }).on('input', function() {
                var raw = $(this).val();
                if (/^0[0-9]/.test(raw)) {
                    raw = raw.slice(1);
                    $(this).val(raw);
                }
                self.currentExpression = (raw === '') ? '0' : raw;
            }).on('keydown', function(e) {
                if (e.key === 'Enter' || e.key === '=') {
                    e.preventDefault();
                    self.currentExpression = $(this).val() || '0';
                    self.handleButton('=');
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    self.handleButton('C');
                }
            });

            // Angle mode toggle (RAD vs DEG).
            $('#sat-calc-angle-mode').on('click', function() {
                if (self.angleMode === 'RAD') {
                    self.angleMode = 'DEG';
                    $(this).text('DEG').removeClass('btn-outline-warning').addClass('btn-warning text-dark');
                } else {
                    self.angleMode = 'RAD';
                    $(this).text('RAD').removeClass('btn-warning text-dark').addClass('btn-outline-warning');
                }
                if (self.mode === 'graphing') {
                    self.renderGraph();
                }
            });

            // Graph plotting and zoom controls.
            $('#sat-graph-plot-btn').on('click', function() {
                self.renderGraph();
            });
            $('#sat-graph-function, #sat-graph-function-2').on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.renderGraph();
                }
            });
            $('#sat-graph-zoom-in').on('click', function() {
                self.graphScale = Math.min(100, self.graphScale * 1.25);
                self.renderGraph();
            });
            $('#sat-graph-zoom-out').on('click', function() {
                self.graphScale = Math.max(5, self.graphScale / 1.25);
                self.renderGraph();
            });
            $('#sat-graph-zoom-reset').on('click', function() {
                self.graphScale = 20;
                self.renderGraph();
            });
            $('#sat-graph-canvas').on('mousemove', function(e) {
                var rect = this.getBoundingClientRect();
                var scaleX = this.width / (rect.width || this.width);
                var scaleY = this.height / (rect.height || this.height);
                var px = (e.clientX - rect.left) * scaleX;
                var py = (e.clientY - rect.top) * scaleY;
                var scale = self.graphScale || 20;
                var mathX = (px - (this.width / 2)) / scale;
                var mathY = ((this.height / 2) - py) / scale;
                $('#sat-graph-coords').text('x: ' + mathX.toFixed(2) + ', y: ' + mathY.toFixed(2));
            }).on('mouseleave', function() {
                $('#sat-graph-coords').text('x: --, y: --');
            });

            // Draggable panel setup.
            self.setupDraggable();
        },

        normalizeExpression: function(raw) {
            var expr = String(raw)
                .replace(/(^|[^0-9.])0+(?=\d)/g, '$1')
                .replace(/(\d)(x|sin|cos|tan|sqrt|log|ln|pi|\()/gi, '$1*$2')
                .replace(/(\))(\d|x|sin|cos|tan|sqrt|log|ln|pi|\()/gi, '$1*$2')
                .replace(/sin/gi, 'Math.sin')
                .replace(/cos/gi, 'Math.cos')
                .replace(/tan/gi, 'Math.tan')
                .replace(/sqrt/gi, 'Math.sqrt')
                .replace(/log/gi, 'Math.log10')
                .replace(/ln/gi, 'Math.log')
                .replace(/pi/gi, 'Math.PI')
                .replace(/\be\b/g, 'Math.E')
                .replace(/\^/g, '**');
            if (this.angleMode === 'DEG') {
                expr = this.applyAngleModeToTrig(expr);
            }
            return expr;
        },

        applyAngleModeToTrig: function(expr) {
            var result = '';
            var i = 0;
            var targets = ['Math.sin(', 'Math.cos(', 'Math.tan('];
            while (i < expr.length) {
                var matched = null;
                for (var t = 0; t < targets.length; t++) {
                    if (expr.indexOf(targets[t], i) === i) {
                        matched = targets[t];
                        break;
                    }
                }
                if (matched) {
                    var argStart = i + matched.length;
                    var depth = 1;
                    var j = argStart;
                    while (j < expr.length && depth > 0) {
                        if (expr[j] === '(') {
                            depth++;
                        } else if (expr[j] === ')') {
                            depth--;
                        }
                        j++;
                    }
                    if (depth === 0) {
                        var inner = expr.slice(argStart, j - 1);
                        var transformedInner = this.applyAngleModeToTrig(inner);
                        result += matched + '((Math.PI/180)*(' + transformedInner + ')))';
                        i = j;
                        continue;
                    }
                }
                result += expr[i];
                i++;
            }
            return result;
        },

        isSafeExpression: function(expr) {
            if (typeof expr !== 'string' || expr.trim() === '') {
                return false;
            }
            var stripped = expr.replace(/Math\.(?:sin|cos|tan|sqrt|log10|log|PI|E|abs|pow)\b/g, '');
            return /^[0-9+\-*/().\s,]+$/.test(stripped);
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
                    // Safe basic arithmetic evaluation with implicit multiplication support.
                    var expr = this.normalizeExpression(this.currentExpression);
                    if (!this.isSafeExpression(expr)) {
                        throw new Error('Unsafe expression');
                    }

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
            var scale = this.graphScale || 20;

            // Draw grid lines aligned to origin.
            ctx.strokeStyle = '#e2e8f0';
            ctx.lineWidth = 1;

            var gx;
            for (gx = originX; gx < width; gx += scale) {
                ctx.beginPath();
                ctx.moveTo(gx, 0);
                ctx.lineTo(gx, height);
                ctx.stroke();
            }
            for (gx = originX - scale; gx >= 0; gx -= scale) {
                ctx.beginPath();
                ctx.moveTo(gx, 0);
                ctx.lineTo(gx, height);
                ctx.stroke();
            }
            var gy;
            for (gy = originY; gy < height; gy += scale) {
                ctx.beginPath();
                ctx.moveTo(0, gy);
                ctx.lineTo(width, gy);
                ctx.stroke();
            }
            for (gy = originY - scale; gy >= 0; gy -= scale) {
                ctx.beginPath();
                ctx.moveTo(0, gy);
                ctx.lineTo(width, gy);
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

            // Plot y1 (primary blue curve).
            var fn1 = $('#sat-graph-function').val() || 'x^2 - 4';
            this.plotFunctionOnCanvas(ctx, fn1, '#2563eb', width, height, originX, originY, scale);

            // Plot optional y2 (secondary emerald curve).
            var fn2 = ($('#sat-graph-function-2').val() || '').trim();
            if (fn2 !== '') {
                this.plotFunctionOnCanvas(ctx, fn2, '#059669', width, height, originX, originY, scale);
            }
        },

        plotFunctionOnCanvas: function(ctx, rawFn, strokeColor, width, height, originX, originY, scale) {
            var jsFn = this.normalizeExpression(rawFn);
            if (!this.isSafeExpression(jsFn.replace(/\bx\b/g, '(1)'))) {
                return;
            }

            ctx.strokeStyle = strokeColor;
            ctx.lineWidth = 2.5;
            ctx.beginPath();

            var firstPoint = true;
            var prevPy = null;
            for (var px = 0; px <= width; px += 2) {
                var mathX = (px - originX) / scale;
                try {
                    var expr = jsFn.replace(/\bx\b/g, '(' + mathX + ')');
                    /* eslint-disable no-eval */
                    var mathY = eval(expr);
                    /* eslint-enable no-eval */

                    if (typeof mathY === 'number' && !isNaN(mathY) && isFinite(mathY)) {
                        var py = originY - (mathY * scale);
                        if (firstPoint || (prevPy !== null && Math.abs(py - prevPy) > height * 1.5)) {
                            ctx.moveTo(px, py);
                            firstPoint = false;
                        } else {
                            ctx.lineTo(px, py);
                        }
                        prevPy = py;
                    } else {
                        firstPoint = true;
                        prevPy = null;
                    }
                } catch (e) {
                    firstPoint = true;
                    prevPy = null;
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
