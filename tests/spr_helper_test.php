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
use mod_sat\local\spr_helper;

/**
 * Unit tests for SAT Student-Produced Response (SPR) helper.
 *
 * @package    mod_sat
 * @category   test
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_sat\local\spr_helper
 */
final class spr_helper_test extends advanced_testcase {
    /**
     * Test SPR format validation constraints.
     */
    public function test_validate_format(): void {
        // Valid positive strings (max 5 chars).
        [$valid] = spr_helper::validate_format('2/3');
        $this->assertTrue($valid);

        [$valid] = spr_helper::validate_format('0.75');
        $this->assertTrue($valid);

        [$valid] = spr_helper::validate_format('.75');
        $this->assertTrue($valid);

        [$valid] = spr_helper::validate_format('12345');
        $this->assertTrue($valid);

        // Valid negative strings (max 6 chars).
        [$valid] = spr_helper::validate_format('-1.5');
        $this->assertTrue($valid);

        [$valid] = spr_helper::validate_format('-3/4');
        $this->assertTrue($valid);

        [$valid] = spr_helper::validate_format('-12345');
        $this->assertTrue($valid);

        // Invalid: Empty.
        [$valid, $err] = spr_helper::validate_format('');
        $this->assertFalse($valid);
        $this->assertEquals('empty_input', $err);

        // Invalid: Positive length > 5.
        [$valid, $err] = spr_helper::validate_format('123456');
        $this->assertFalse($valid);
        $this->assertEquals('max_length_exceeded', $err);

        // Invalid: Negative length > 6.
        [$valid, $err] = spr_helper::validate_format('-123456');
        $this->assertFalse($valid);
        $this->assertEquals('max_length_exceeded', $err);

        // Invalid: Mixed number.
        [$valid] = spr_helper::validate_format('1 1/2');
        $this->assertFalse($valid);

        // Invalid: Commas or letters.
        [$valid] = spr_helper::validate_format('1,000');
        $this->assertFalse($valid);
        [$valid] = spr_helper::validate_format('x');
        $this->assertFalse($valid);

        // Invalid: Mixed slash and decimal.
        [$valid, $err] = spr_helper::validate_format('2.5/5');
        $this->assertFalse($valid);
        $this->assertEquals('mixed_decimal_and_fraction', $err);

        // Invalid: Division by zero.
        [$valid, $err] = spr_helper::validate_format('5/0');
        $this->assertFalse($valid);
        $this->assertEquals('division_by_zero', $err);
    }

    /**
     * Test parsing fraction and decimal strings to float values.
     */
    public function test_parse_to_float(): void {
        $this->assertEqualsWithDelta(0.75, spr_helper::parse_to_float('3/4'), 0.0001);
        $this->assertEqualsWithDelta(0.66667, spr_helper::parse_to_float('2/3'), 0.0001);
        $this->assertEqualsWithDelta(-0.5, spr_helper::parse_to_float('-1/2'), 0.0001);
        $this->assertEqualsWithDelta(4.0, spr_helper::parse_to_float('4'), 0.0001);
        $this->assertNull(spr_helper::parse_to_float('invalid'));
    }

    /**
     * Test expanding canonical answers into SAT accepted equivalents.
     */
    public function test_generate_accepted_forms(): void {
        $forms23 = spr_helper::generate_accepted_forms('2/3');
        $this->assertContains('2/3', $forms23);
        $this->assertContains('.6666', $forms23);
        $this->assertContains('.6667', $forms23);
        $this->assertContains('0.666', $forms23);
        $this->assertContains('0.667', $forms23);

        $forms75 = spr_helper::generate_accepted_forms('0.75');
        $this->assertContains('0.75', $forms75);
        $this->assertContains('.75', $forms75);
        $this->assertContains('3/4', $forms75);
    }

    /**
     * Test scoring accuracy under official SAT equivalence rules.
     */
    public function test_is_correct(): void {
        $accepted = ['2/3'];

        // Correct student answers.
        $this->assertTrue(spr_helper::is_correct('2/3', $accepted));
        $this->assertTrue(spr_helper::is_correct('.6666', $accepted));
        $this->assertTrue(spr_helper::is_correct('.6667', $accepted));
        $this->assertTrue(spr_helper::is_correct('0.666', $accepted));
        $this->assertTrue(spr_helper::is_correct('0.667', $accepted));

        // Incorrect answers (truncated too short or wrong math).
        $this->assertFalse(spr_helper::is_correct('0.66', $accepted));
        $this->assertFalse(spr_helper::is_correct('.67', $accepted));
        $this->assertFalse(spr_helper::is_correct('3/4', $accepted));

        // Decimal answer test (0.75).
        $this->assertTrue(spr_helper::is_correct('0.75', ['3/4']));
        $this->assertTrue(spr_helper::is_correct('.75', ['3/4']));
    }
}
