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

namespace mod_sat\local;

use stdClass;

/**
 * Manages the module and slot structure of an SAT exam instance.
 *
 * Integrates with Moodle 5.2 question_references and question_set_references.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure_manager {
    /**
     * Retrieves all modules for a given sat activity instance.
     *
     * @param int $satid The sat activity instance ID
     * @return array List of module records ordered by sortorder
     */
    public static function get_modules(int $satid): array {
        global $DB;
        return $DB->get_records('sat_modules', ['satid' => $satid], 'sortorder ASC');
    }

    /**
     * Retrieves all question slots for a given module, with question details.
     *
     * @param int $moduleid The sat_modules ID
     * @return array List of slot objects with attached question details
     */
    public static function get_slots(int $moduleid): array {
        global $DB;

        $sql = "SELECT s.*,
                       qr.questionbankentryid,
                       q.id AS questionid,
                       q.name AS questionname,
                       q.qtype,
                       q.questiontext,
                       q.questiontextformat
                  FROM {sat_slots} s
             LEFT JOIN {question_references} qr
                    ON qr.itemid = s.id
                   AND qr.component = 'mod_sat'
                   AND qr.questionarea = 'slot'
             LEFT JOIN {question_bank_entries} qbe
                    ON qbe.id = qr.questionbankentryid
             LEFT JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = 'ready'
             LEFT JOIN {question} q
                    ON q.id = qv.questionid
                 WHERE s.moduleid = :moduleid
              ORDER BY s.slot ASC";

        return $DB->get_records_sql($sql, ['moduleid' => $moduleid]);
    }

    /**
     * Adds a fixed question slot to an SAT module.
     *
     * @param int $satid SAT activity ID
     * @param int $moduleid Module ID
     * @param int $contextid Context ID of the course module
     * @param int $qbankentryid Question bank entry ID
     * @param float $maxmark Maximum grade mark for this slot (default 1.0)
     * @param bool $isscored Whether this question counts toward the score (1) or is unscored pretest (0)
     * @param bool $isspr Whether this question is a math student-produced response
     * @return int New slot ID
     */
    public static function add_fixed_slot(
        int $satid,
        int $moduleid,
        int $contextid,
        int $qbankentryid,
        float $maxmark = 1.0,
        bool $isscored = true,
        bool $isspr = false
    ): int {
        global $DB;

        // Determine next slot number.
        $maxslot = $DB->get_field('sat_slots', 'MAX(slot)', ['moduleid' => $moduleid]);
        $nextslot = $maxslot ? ((int)$maxslot + 1) : 1;

        $slot = new stdClass();
        $slot->satid = $satid;
        $slot->moduleid = $moduleid;
        $slot->slot = $nextslot;
        $slot->maxmark = $maxmark;
        $slot->is_scored = $isscored ? 1 : 0;
        $slot->is_spr = $isspr ? 1 : 0;
        $slot->slottype = 'fixed';

        $slotid = $DB->insert_record('sat_slots', $slot);

        // Register in Moodle 5.2 question_references.
        $ref = new stdClass();
        $ref->usingcontextid = $contextid;
        $ref->component = 'mod_sat';
        $ref->questionarea = 'slot';
        $ref->itemid = $slotid;
        $ref->questionbankentryid = $qbankentryid;
        $ref->version = null; // Always reference the latest ready version.

        $DB->insert_record('question_references', $ref);

        return $slotid;
    }

    /**
     * Deletes a question slot and its associated reference, renumbering remaining slots.
     *
     * @param int $slotid Slot ID to delete
     * @return bool True on success
     */
    public static function delete_slot(int $slotid): bool {
        global $DB;

        $slot = $DB->get_record('sat_slots', ['id' => $slotid]);
        if (!$slot) {
            return false;
        }

        // Delete question references.
        $DB->delete_records('question_references', [
            'component' => 'mod_sat',
            'questionarea' => 'slot',
            'itemid' => $slotid,
        ]);
        $DB->delete_records('question_set_references', [
            'usingcontextid' => null,
            'component' => 'mod_sat',
            'questionarea' => 'slot',
            'itemid' => $slotid,
        ]);

        $DB->delete_records('sat_slots', ['id' => $slotid]);

        // Renumber remaining slots in the module.
        $remaining = $DB->get_records('sat_slots', ['moduleid' => $slot->moduleid], 'slot ASC');
        $num = 1;
        foreach ($remaining as $r) {
            if ($r->slot !== $num) {
                $r->slot = $num;
                $DB->update_record('sat_slots', $r);
            }
            $num++;
        }

        return true;
    }

    /**
     * Splits question text on the first <hr> delimiter into left stimulus and right question prompt.
     *
     * @param string $questiontext Raw question HTML
     * @return array [string $stimulus, string $prompt]
     */
    public static function split_stimulus(string $questiontext): array {
        // Standard split on <hr> or <hr/> or <hr class="...">.
        $parts = preg_split('/<hr\s*\/?>/i', $questiontext, 2);

        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }

        // If no explicit delimiter, entire text is the stimulus/prompt.
        return ['', trim($questiontext)];
    }
}
