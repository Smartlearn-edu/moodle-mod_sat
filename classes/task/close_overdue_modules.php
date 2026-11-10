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

namespace mod_sat\task;

use core\task\scheduled_task;
use mod_sat\local\attempt_manager;

/**
 * Scheduled task to automatically submit overdue SAT exam modules.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class close_overdue_modules extends scheduled_task {
    /**
     * Get human-readable name for the task.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_close_overdue_modules', 'mod_sat');
    }

    /**
     * Executes the task, finding all in-progress module attempts past deadline + grace.
     */
    public function execute(): void {
        global $DB;

        $now = time();
        $sql = "SELECT ma.id AS modattemptid, ma.attemptid
                  FROM {sat_module_attempts} ma
                  JOIN {sat_attempts} a ON a.id = ma.attemptid
                  JOIN {sat} s ON s.id = a.satid
                 WHERE ma.state = 'inprogress'
                   AND a.state = 'inprogress'
                   AND (ma.timedeadline + s.graceperiod) < :now";

        $overdue = $DB->get_records_sql($sql, ['now' => $now]);

        if (empty($overdue)) {
            return;
        }

        mtrace("mod_sat: Processing " . count($overdue) . " overdue exam module attempts...");

        foreach ($overdue as $row) {
            try {
                attempt_manager::submit_module((int)$row->attemptid, (int)$row->modattemptid);
                mtrace("  - Auto-submitted overdue module attempt ID: " . $row->modattemptid);
            } catch (\Throwable $e) {
                mtrace("  - Failed to auto-submit module attempt ID: " . $row->modattemptid . " Error: " . $e->getMessage());
            }
        }
    }
}
