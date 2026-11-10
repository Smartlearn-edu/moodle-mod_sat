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
 * Defines backup_sat_activity_task class.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/sat/backup/moodle2/backup_sat_stepslib.php');

/**
 * Activity task class for backup.
 */
class backup_sat_activity_task extends backup_activity_task {
    /**
     * Define (add) particular settings this activity can have.
     */
    protected function define_my_settings() {
        // No particular settings.
    }

    /**
     * Define (add) particular steps this activity can have.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_sat_activity_structure_step('sat_structure', 'sat.xml'));
    }

    /**
     * Code the transformations to perform in the activity in
     * order to get transportable (encoded) links.
     *
     * @param string $content
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        // Link to the list of sats.
        $search = "/(" . $base . "\/mod\/sat\/index\.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SATINDEX*$2@$', $content);

        // Link to sat view bymoduleid.
        $search = "/(" . $base . "\/mod\/sat\/view\.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SATVIEWBYID*$2@$', $content);

        return $content;
    }
}
