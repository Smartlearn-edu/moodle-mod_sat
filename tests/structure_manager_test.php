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
use mod_sat\local\structure_manager;

/**
 * Unit tests for SAT structure manager.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\local\structure_manager
 */
final class structure_manager_test extends advanced_testcase {
    /**
     * Test splitting question HTML text on <hr> tag.
     */
    public function test_split_stimulus(): void {
        // Standard split.
        $html1 = '<p>Passage content here.</p><hr><p>Question prompt?</p>';
        [$stimulus, $prompt] = structure_manager::split_stimulus($html1);
        $this->assertEquals('<p>Passage content here.</p>', $stimulus);
        $this->assertEquals('<p>Question prompt?</p>', $prompt);

        // Self-closing <hr /> split.
        $html2 = '<p>Reading passage.</p><hr /><p>Which choice best describes...?</p>';
        [$stimulus, $prompt] = structure_manager::split_stimulus($html2);
        $this->assertEquals('<p>Reading passage.</p>', $stimulus);
        $this->assertEquals('<p>Which choice best describes...?</p>', $prompt);

        // No split tag: returns full text as prompt.
        $html3 = '<p>If 2x + 1 = 5, what is x?</p>';
        [$stimulus, $prompt] = structure_manager::split_stimulus($html3);
        $this->assertEmpty($stimulus);
        $this->assertEquals($html3, $prompt);
    }
}
