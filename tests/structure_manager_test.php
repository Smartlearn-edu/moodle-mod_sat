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
use stdClass;

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

    /**
     * Test formatting hierarchical question categories and resolving descendant IDs.
     */
    public function test_format_category_tree_and_descendants(): void {
        $mkcat = static function (int $id, string $name, int $parent, int $sortorder, int $qcount): stdClass {
            return (object)[
                'id' => $id,
                'name' => $name,
                'parent' => $parent,
                'contextid' => 100,
                'sortorder' => $sortorder,
                'questioncount' => $qcount,
            ];
        };

        $records = [
            1 => $mkcat(1, 'top', 0, 0, 0),
            2 => $mkcat(2, 'Reading & Writing', 1, 1, 2),
            3 => $mkcat(3, 'Module 1', 2, 1, 5),
            4 => $mkcat(4, '1', 3, 1, 22),
            5 => $mkcat(5, 'Module 2', 2, 2, 10),
        ];

        $tree = structure_manager::format_category_tree($records);

        // Top category must be excluded and nodes ordered pre-order.
        $this->assertArrayNotHasKey(1, $tree);
        $this->assertSame([2, 3, 4, 5], array_keys($tree));

        // Verify depths, indentation, full breadcrumb path, and rolled-up counts.
        $this->assertSame(0, $tree[2]->depth);
        $this->assertSame(1, $tree[3]->depth);
        $this->assertSame(2, $tree[4]->depth);
        $this->assertSame(1, $tree[5]->depth);

        $this->assertSame('Reading & Writing', $tree[2]->indentedname);
        $this->assertSame('— Module 1', $tree[3]->indentedname);
        $this->assertSame('— — 1', $tree[4]->indentedname);
        $this->assertSame('Reading & Writing / Module 1 / 1', $tree[4]->pathname);

        $this->assertSame(22, $tree[4]->totalquestioncount);
        $this->assertSame(27, $tree[3]->totalquestioncount);
        $this->assertSame(39, $tree[2]->totalquestioncount);
        $this->assertSame('— Module 1 (5 / 27 total)', $tree[3]->label);
        $this->assertSame('— — 1 (22)', $tree[4]->label);

        // Verify descendant resolution.
        $this->assertSame([3, 4], structure_manager::get_descendant_category_ids($tree, 3));
        $this->assertSame([2, 3, 5, 4], structure_manager::get_descendant_category_ids($tree, 2));
        $this->assertSame([], structure_manager::get_descendant_category_ids($tree, 999));
    }
}
