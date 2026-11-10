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
 * Site administration settings for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Default exam scope.
    $settings->add(new admin_setting_configselect(
        'mod_sat/default_scope',
        get_string('default_scope', 'mod_sat'),
        get_string('default_scope_desc', 'mod_sat'),
        'full',
        [
            'full' => get_string('scope_full', 'mod_sat'),
            'math_only' => get_string('scope_math_only', 'mod_sat'),
            'rw_only' => get_string('scope_rw_only', 'mod_sat'),
            'single_module' => get_string('scope_single_module', 'mod_sat'),
        ]
    ));

    // Default pacing mode.
    $settings->add(new admin_setting_configselect(
        'mod_sat/default_pacing',
        get_string('default_pacing', 'mod_sat'),
        get_string('default_pacing_desc', 'mod_sat'),
        'continuous',
        [
            'continuous' => get_string('pacing_continuous', 'mod_sat'),
            'paced' => get_string('pacing_paced', 'mod_sat'),
        ]
    ));

    // Default RW adaptive threshold.
    $settings->add(new admin_setting_configtext(
        'mod_sat/default_threshold_rw',
        get_string('default_threshold_rw', 'mod_sat'),
        get_string('default_threshold_rw_desc', 'mod_sat'),
        60,
        PARAM_INT
    ));

    // Default Math adaptive threshold.
    $settings->add(new admin_setting_configtext(
        'mod_sat/default_threshold_math',
        get_string('default_threshold_math', 'mod_sat'),
        get_string('default_threshold_math_desc', 'mod_sat'),
        60,
        PARAM_INT
    ));

    // Enable built-in open-source graphing calculator.
    $settings->add(new admin_setting_configcheckbox(
        'mod_sat/enable_graphing_calc',
        get_string('enable_graphing_calc', 'mod_sat'),
        get_string('enable_graphing_calc_desc', 'mod_sat'),
        1
    ));

    // Enable scientific calculator mode.
    $settings->add(new admin_setting_configcheckbox(
        'mod_sat/enable_scientific_calc',
        get_string('enable_scientific_calc', 'mod_sat'),
        get_string('enable_scientific_calc_desc', 'mod_sat'),
        1
    ));
}
