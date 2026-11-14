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
 * Interactive Moodle Quiz-style question bank and category picker for mod_sat.
 *
 * @module     mod_sat/question_picker
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'core/notification'], function($, Notification) {
    'use strict';

    var QuestionPicker = {
        init: function() {
            var self = this;
            var filterForm = $('#sat-qbank-filter-form');

            // Auto-submit when switching Question Bank (reset category filter to All).
            $('#sat-select-bank').on('change', function() {
                $('#sat-select-category').val('0');
                filterForm.trigger('submit');
            });

            // Auto-submit when switching Category, Subcategory toggle, or QType filter.
            $('#sat-select-category, #sat-check-recurse, #sat-select-qtype').on('change', function() {
                filterForm.trigger('submit');
            });

            // Master Select All checkbox in table header.
            $('#sat-check-all').on('change', function() {
                var checked = $(this).is(':checked');
                $('.sat-qentry-checkbox').prop('checked', checked);
                self.updateSelectedCount();
            });

            // Individual checkboxes update master checkbox and live counter.
            $(document).on('change', '.sat-qentry-checkbox', function() {
                self.updateSelectedCount();
            });

            // Select all questions not yet in the current module.
            $('#sat-btn-select-unused').on('click', function(e) {
                e.preventDefault();
                $('.sat-qentry-checkbox').each(function() {
                    var inModule = String($(this).data('in-module')) === '1';
                    $(this).prop('checked', !inModule);
                });
                self.updateSelectedCount();
            });

            // Deselect all questions.
            $('#sat-btn-deselect-all').on('click', function(e) {
                e.preventDefault();
                $('.sat-qentry-checkbox').prop('checked', false);
                $('#sat-check-all').prop('checked', false);
                self.updateSelectedCount();
            });

            // Confirm deletion of an existing slot using Moodle core Notification modal.
            $(document).on('click', '.sat-delete-slot', function(e) {
                e.preventDefault();
                var targetUrl = $(this).attr('href');
                var title = $(this).data('confirm-title') || 'Delete';
                var message = $(this).data('confirm-message') || '';
                Notification.deleteCancel(title, message, title, function() {
                    window.location.href = targetUrl;
                }, function() {
                    // Cancelled by user.
                });
            });

            self.updateSelectedCount();
        },

        updateSelectedCount: function() {
            var checkboxes = $('.sat-qentry-checkbox');
            var checkedCount = checkboxes.filter(':checked').length;
            $('#sat-selected-count').text(checkedCount);

            var allChecked = checkboxes.length > 0 && checkedCount === checkboxes.length;
            $('#sat-check-all').prop('checked', allChecked);
        }
    };

    return QuestionPicker;
});
