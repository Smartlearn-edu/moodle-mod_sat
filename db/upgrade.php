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
 * Upgrade steps for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute mod_sat upgrade from an older version.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success.
 */
function xmldb_sat_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026042011) {
        $table = new xmldb_table('sat_slot_state');

        $responsefield = new xmldb_field('response', XMLDB_TYPE_TEXT, null, null, null, null, null, 'annotations');
        if (!$dbman->field_exists($table, $responsefield)) {
            $dbman->add_field($table, $responsefield);
        }

        $flaggedfield = new xmldb_field('flagged', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'response');
        if (!$dbman->field_exists($table, $flaggedfield)) {
            $dbman->add_field($table, $flaggedfield);
        }

        upgrade_mod_savepoint(true, 2026042011, 'sat');
    }

    // Upgrades will automatically update capabilities from db/access.php.
    return true;
}
