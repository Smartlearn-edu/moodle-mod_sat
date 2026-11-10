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

namespace mod_sat;

use advanced_testcase;
use core_privacy\local\metadata\collection;
use mod_sat\privacy\provider;

/**
 * Unit tests for mod_sat privacy provider.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\privacy\provider
 */
final class privacy_provider_test extends advanced_testcase {
    /**
     * Test get_metadata returns expected collection.
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_sat');
        $result = provider::get_metadata($collection);

        $this->assertInstanceOf(collection::class, $result);
        $items = $result->get_collection();
        $this->assertNotEmpty($items);

        $tablenames = [];
        foreach ($items as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\database_table) {
                $tablenames[] = $item->get_name();
            }
        }

        $this->assertContains('sat_attempts', $tablenames);
        $this->assertContains('sat_slot_state', $tablenames);
        $this->assertContains('sat_grades', $tablenames);
        $this->assertContains('sat_overrides', $tablenames);
    }
}
