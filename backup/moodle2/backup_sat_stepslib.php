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
 * Define all the backup steps that will be used by the backup_sat_activity_task.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete sat structure for backup, with file and id annotations.
 */
class backup_sat_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the structure of the backup.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        // Define each element separated.
        $sat = new backup_nested_element('sat', ['id'], [
            'name', 'intro', 'introformat', 'exam_scope', 'pacing_mode',
            'routing_mode', 'timeopen', 'timeclose', 'graceperiod',
            'breakduration', 'attempts', 'grademethod', 'threshold_rw',
            'threshold_math', 'reviewoptions', 'grade', 'timecreated', 'timemodified',
        ]);

        $modules = new backup_nested_element('modules');
        $module = new backup_nested_element('module', ['id'], [
            'section', 'stage', 'timelimit', 'questioncount', 'sortorder',
        ]);

        $slots = new backup_nested_element('slots');
        $slot = new backup_nested_element('slot', ['id'], [
            'slot', 'maxmark', 'is_scored', 'is_spr', 'slottype',
        ]);

        $overrides = new backup_nested_element('overrides');
        $override = new backup_nested_element('override', ['id'], [
            'groupid', 'userid', 'timeopen', 'timeclose', 'attempts', 'timemultiplier',
        ]);

        // Build the tree.
        $sat->add_child($modules);
        $modules->add_child($module);

        $sat->add_child($slots);
        $slots->add_child($slot);

        $sat->add_child($overrides);
        $overrides->add_child($override);

        // Define sources.
        $sat->set_source_table('sat', ['id' => backup::VAR_ACTIVITYID]);
        $module->set_source_table('sat_modules', ['satid' => backup::VAR_PARENTID]);
        $slot->set_source_table('sat_slots', ['satid' => backup::VAR_PARENTID]);
        $override->set_source_table('sat_overrides', ['satid' => backup::VAR_PARENTID]);

        // Annotations.
        $override->annotate_ids('user', 'userid');
        $override->annotate_ids('group', 'groupid');

        return $this->prepare_activity_structure($sat);
    }
}
