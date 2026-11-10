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
use mod_sat\local\diagnostic_manager;

/**
 * Unit tests for SAT diagnostic analysis and cohort analytics engine.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\local\diagnostic_manager
 */
final class diagnostic_manager_test extends advanced_testcase {
    /**
     * Test domain label resolution for official SAT domains and fallbacks.
     */
    public function test_get_domain_label(): void {
        $craft = diagnostic_manager::DOMAIN_CRAFT_STRUCTURE;
        $this->assertEquals('Craft and Structure', diagnostic_manager::get_domain_label($craft));

        $info = diagnostic_manager::DOMAIN_INFO_IDEAS;
        $this->assertEquals('Information and Ideas', diagnostic_manager::get_domain_label($info));

        $standardeng = diagnostic_manager::DOMAIN_STANDARD_ENGLISH;
        $this->assertEquals('Standard English Conventions', diagnostic_manager::get_domain_label($standardeng));

        $expression = diagnostic_manager::DOMAIN_EXPRESSION_IDEAS;
        $this->assertEquals('Expression of Ideas', diagnostic_manager::get_domain_label($expression));

        $algebra = diagnostic_manager::DOMAIN_ALGEBRA;
        $this->assertEquals('Algebra', diagnostic_manager::get_domain_label($algebra));

        $advancedmath = diagnostic_manager::DOMAIN_ADVANCED_MATH;
        $this->assertEquals('Advanced Math', diagnostic_manager::get_domain_label($advancedmath));

        $problemsolving = diagnostic_manager::DOMAIN_PROBLEM_SOLVING;
        $this->assertEquals('Problem-Solving and Data Analysis', diagnostic_manager::get_domain_label($problemsolving));

        $geometry = diagnostic_manager::DOMAIN_GEOMETRY_TRIG;
        $this->assertEquals('Geometry and Trigonometry', diagnostic_manager::get_domain_label($geometry));

        // Test fallback for custom or general domain.
        $this->assertEquals('General', diagnostic_manager::get_domain_label('sat_domain:general'));
    }

    /**
     * Test cohort analytics calculations when no attempts exist.
     */
    public function test_cohort_analytics_empty(): void {
        $this->resetAfterTest(true);

        $metrics = diagnostic_manager::get_cohort_analytics(99999);
        $this->assertEquals(0, $metrics['totalattempts']);
        $this->assertEquals(0, $metrics['averagetotal']);
        $this->assertEquals(0, $metrics['rwhardrate']);
        $this->assertEquals(0, $metrics['mathhardrate']);
    }

    /**
     * Test cohort score distribution buckets when no attempts exist.
     */
    public function test_cohort_score_distribution_empty(): void {
        $this->resetAfterTest(true);

        $dist = diagnostic_manager::get_cohort_score_distribution(99999);
        $this->assertArrayHasKey('1400-1600', $dist);
        $this->assertArrayHasKey('1200-1390', $dist);
        $this->assertArrayHasKey('1000-1190', $dist);
        $this->assertArrayHasKey('800-990', $dist);
        $this->assertArrayHasKey('400-790', $dist);
        $this->assertEquals(0, $dist['1400-1600']['count']);
    }
}
