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
use mod_sat\local\scorer;

/**
 * Unit tests for SAT scaled scoring and composite calculation engine.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\local\scorer
 */
final class scorer_test extends advanced_testcase {
    /**
     * Test composite score aggregation across exam scopes.
     */
    public function test_calculate_composite_score(): void {
        // Full exam: sum of both sections (400 - 1600).
        $this->assertEquals(1600, scorer::calculate_composite_score(800, 800, 'full'));
        $this->assertEquals(1200, scorer::calculate_composite_score(600, 600, 'full'));
        $this->assertEquals(400, scorer::calculate_composite_score(200, 200, 'full'));

        // RW only: returns RW scaled score (200 - 800).
        $this->assertEquals(750, scorer::calculate_composite_score(750, null, 'rw_only'));

        // Math only: returns Math scaled score (200 - 800).
        $this->assertEquals(680, scorer::calculate_composite_score(null, 680, 'math_only'));

        // Single module: returns Math/Section score.
        $this->assertEquals(540, scorer::calculate_composite_score(null, 540, 'single_module'));
    }
}
