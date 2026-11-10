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
 * SAT scaled scoring engine and Gradebook synchronizer for mod_sat.
 *
 * Implements the official SAT 200-800 section scaling and 400-1600 total
 * composite score interpolation, factoring in adaptive routing paths.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorer {
    /**
     * Calculates the estimated 200-800 scaled score for a section.
     *
     * @param int $satid The activity instance ID (0 for default)
     * @param string $section Section identifier ('rw' or 'math')
     * @param string $route Route taken ('easy' or 'hard')
     * @param float $rawpercentage Percentage of total possible points earned (0-100)
     * @return int Estimated scaled score (200-800)
     */
    public static function calculate_section_scaled_score(
        int $satid,
        string $section,
        string $route,
        float $rawpercentage
    ): int {
        global $DB;

        $clampedpct = max(0.0, min(100.0, $rawpercentage));
        $normalizedroute = ($route === mst_router::ROUTE_HARD) ? 'hard' : 'easy';

        // Check for instance-specific curve, fallback to site default (satid = 0).
        $records = $DB->get_records('sat_score_tables', [
            'satid' => $satid,
            'section' => $section,
            'route' => $normalizedroute,
        ], 'rawpercent ASC');

        if (empty($records)) {
            $records = $DB->get_records('sat_score_tables', [
                'satid' => 0,
                'section' => $section,
                'route' => $normalizedroute,
            ], 'rawpercent ASC');
        }

        // Hardcoded math fallback if database table not yet populated.
        if (empty($records)) {
            if ($normalizedroute === 'hard') {
                return (int) round(460 + ($clampedpct * 3.4));
            }
            return (int) round(200 + ($clampedpct * 3.9));
        }

        // Linear interpolation between the two nearest raw percentage data points.
        $prev = null;
        $next = null;

        foreach ($records as $r) {
            if ((float)$r->rawpercent <= $clampedpct) {
                $prev = $r;
            }
            if ((float)$r->rawpercent >= $clampedpct && $next === null) {
                $next = $r;
            }
        }

        if ($prev === null && $next !== null) {
            return (int)$next->scaledscore;
        }
        if ($next === null && $prev !== null) {
            return (int)$prev->scaledscore;
        }
        if ($prev !== null && $next !== null) {
            if ($prev->rawpercent == $next->rawpercent) {
                return (int)$prev->scaledscore;
            }
            $factor = ($clampedpct - $prev->rawpercent) / ($next->rawpercent - $prev->rawpercent);
            $scaled = $prev->scaledscore + ($factor * ($next->scaledscore - $prev->scaledscore));
            return (int) max(200, min(800, round($scaled)));
        }

        return 200;
    }

    /**
     * Calculates the composite total scaled score.
     *
     * @param int|null $scaledrw Scaled Reading & Writing score (200-800)
     * @param int|null $scaledmath Scaled Math score (200-800)
     * @param string $scope Exam scope ('full', 'rw_only', 'math_only', 'single_module')
     * @return int Composite score
     */
    public static function calculate_composite_score(?int $scaledrw, ?int $scaledmath, string $scope): int {
        $rw = $scaledrw ?? 200;
        $math = $scaledmath ?? 200;

        if ($scope === 'full') {
            return (int) max(400, min(1600, $rw + $math));
        }

        if ($scope === 'rw_only') {
            return $rw;
        }

        return $math;
    }

    /**
     * Updates student grades in the local table and syncs with Moodle Gradebook.
     *
     * @param stdClass $sat The SAT activity record
     * @param int $userid The student user ID
     * @param int $totalscaled Total composite scaled score
     * @param int|null $scaledrw Scaled RW score
     * @param int|null $scaledmath Scaled Math score
     * @return void
     */
    public static function update_final_grade(
        stdClass $sat,
        int $userid,
        int $totalscaled,
        ?int $scaledrw,
        ?int $scaledmath
    ): void {
        global $DB;
        require_once(__DIR__ . '/../../lib.php');

        $record = $DB->get_record('sat_grades', ['satid' => $sat->id, 'userid' => $userid]);
        if (!$record) {
            $record = new stdClass();
            $record->satid = $sat->id;
            $record->userid = $userid;
            $record->grade = (float)$totalscaled;
            $record->scaled_rw = $scaledrw;
            $record->scaled_math = $scaledmath;
            $record->timemodified = time();
            $DB->insert_record('sat_grades', $record);
        } else {
            // Apply grading method (Highest, Average, First, Last).
            $newgrade = match ((int)$sat->grademethod) {
                SAT_GRADEHIGHEST => max((float)$record->grade, (float)$totalscaled),
                SAT_GRADEAVERAGE => round(((float)$record->grade + (float)$totalscaled) / 2.0, 2),
                SAT_ATTEMPTFIRST => (float)$record->grade,
                default => (float)$totalscaled, // SAT_ATTEMPTLAST.
            };

            $record->grade = $newgrade;
            $record->scaled_rw = $scaledrw;
            $record->scaled_math = $scaledmath;
            $record->timemodified = time();
            $DB->update_record('sat_grades', $record);
        }

        // Sync with core Moodle Gradebook.
        $gradeobject = new stdClass();
        $gradeobject->userid = $userid;
        $gradeobject->rawgrade = (float)$record->grade;

        sat_grade_item_update($sat, $gradeobject);
    }
}
