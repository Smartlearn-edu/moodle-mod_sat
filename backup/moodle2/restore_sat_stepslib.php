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
 * Define all the restore steps that will be used by the restore_sat_activity_task.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one sat activity.
 */
class restore_sat_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the structure of the restore.
     *
     * @return array
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('sat', '/activity/sat');
        $paths[] = new restore_path_element('sat_module', '/activity/sat/modules/module');
        $paths[] = new restore_path_element('sat_slot', '/activity/sat/slots/slot');
        $paths[] = new restore_path_element('sat_override', '/activity/sat/overrides/override');

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Process an sat restore element.
     *
     * @param array $data
     */
    protected function process_sat($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);

        $newitemid = $DB->insert_record('sat', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Process an sat_module restore element.
     *
     * @param array $data
     */
    protected function process_sat_module($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->satid = $this->get_new_parentid('sat');

        $newitemid = $DB->insert_record('sat_modules', $data);
        $this->set_mapping('sat_module', $oldid, $newitemid);
    }

    /**
     * Process an sat_slot restore element.
     *
     * @param array $data
     */
    protected function process_sat_slot($data) {
        global $DB;

        $data = (object) $data;
        $data->satid = $this->get_new_parentid('sat');

        $DB->insert_record('sat_slots', $data);
    }

    /**
     * Process an sat_override restore element.
     *
     * @param array $data
     */
    protected function process_sat_override($data) {
        global $DB;

        $data = (object) $data;
        $data->satid = $this->get_new_parentid('sat');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->groupid = $this->get_mappingid('group', $data->groupid);

        $DB->insert_record('sat_overrides', $data);
    }

    /**
     * Post-execution actions.
     */
    protected function after_execute() {
        $this->add_related_files('mod_sat', 'intro', null);
    }
}
