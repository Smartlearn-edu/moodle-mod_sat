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
use mod_sat\local\mst_router;

/**
 * Unit tests for SAT Multi-Stage Adaptive Testing (MST) routing engine.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\local\mst_router
 */
final class mst_router_test extends advanced_testcase {
    /**
     * Test route calculation against thresholds.
     */
    public function test_determine_route(): void {
        // Exact threshold (60%).
        $this->assertEquals(mst_router::ROUTE_HARD, mst_router::determine_route(15.0, 25.0, 60));

        // Above threshold (80%).
        $this->assertEquals(mst_router::ROUTE_HARD, mst_router::determine_route(20.0, 25.0, 60));

        // Below threshold (56%).
        $this->assertEquals(mst_router::ROUTE_EASY, mst_router::determine_route(14.0, 25.0, 60));

        // 0% score.
        $this->assertEquals(mst_router::ROUTE_EASY, mst_router::determine_route(0.0, 25.0, 60));

        // 100% score.
        $this->assertEquals(mst_router::ROUTE_HARD, mst_router::determine_route(25.0, 25.0, 60));

        // Empty / 0 max score edge case.
        $this->assertEquals(mst_router::ROUTE_EASY, mst_router::determine_route(0.0, 0.0, 60));
    }
}
