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
 * Web service function declarations for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_sat_get_attempt_state' => [
        'classname'   => 'mod_sat\external\attempt_api',
        'methodname'  => 'get_attempt_state',
        'description' => 'Retrieve current SAT exam attempt state, active questions, and time remaining.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'mod_sat_save_slot_state' => [
        'classname'   => 'mod_sat\external\attempt_api',
        'methodname'  => 'save_slot_state',
        'description' => 'Save question elimination and highlight annotations.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'mod_sat_submit_module' => [
        'classname'   => 'mod_sat\external\attempt_api',
        'methodname'  => 'submit_module',
        'description' => 'Submit current module, triggering adaptive routing or next module.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
