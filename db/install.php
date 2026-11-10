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
 * Post-installation code for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Code run after the mod_sat tables have been created.
 *
 * Populates default 200-800 scaled score lookup curves.
 *
 * @return bool
 */
function xmldb_sat_install() {
    global $DB;

    // Default curve templates: (raw percent => scaled score).
    // Easy route caps at 590, Hard route starts at 460 and reaches 800.
    $easycurve = [
        0 => 200, 5 => 210, 10 => 230, 15 => 250, 20 => 280,
        25 => 310, 30 => 340, 35 => 370, 40 => 400, 45 => 430,
        50 => 460, 55 => 480, 60 => 500, 65 => 520, 70 => 540,
        75 => 550, 80 => 560, 85 => 570, 90 => 580, 95 => 590, 100 => 590,
    ];

    $hardcurve = [
        0 => 460, 5 => 470, 10 => 480, 15 => 500, 20 => 520,
        25 => 540, 30 => 560, 35 => 580, 40 => 600, 45 => 620,
        50 => 640, 55 => 660, 60 => 680, 65 => 700, 70 => 720,
        75 => 740, 80 => 760, 85 => 770, 90 => 780, 95 => 790, 100 => 800,
    ];

    $sections = ['rw', 'math'];
    $records = [];

    foreach ($sections as $section) {
        foreach ($easycurve as $rawpct => $scaled) {
            $record = new stdClass();
            $record->satid = 0; // 0 = site default.
            $record->section = $section;
            $record->route = 'easy';
            $record->rawpercent = (int) $rawpct;
            $record->scaledscore = (int) $scaled;
            $records[] = $record;
        }

        foreach ($hardcurve as $rawpct => $scaled) {
            $record = new stdClass();
            $record->satid = 0;
            $record->section = $section;
            $record->route = 'hard';
            $record->rawpercent = (int) $rawpct;
            $record->scaledscore = (int) $scaled;
            $records[] = $record;
        }
    }

    $DB->insert_records('sat_score_tables', $records);

    return true;
}
