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
 * Multi-Stage Adaptive Testing (MST) routing engine for mod_sat.
 *
 * Evaluates student performance on Module 1 and dynamically selects
 * the appropriate Module 2 (Easy vs Hard) based on configured cutoff thresholds.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mst_router {
    /** Route identifier for the easier Module 2. */
    public const ROUTE_EASY = 'easy';

    /** Route identifier for the harder Module 2. */
    public const ROUTE_HARD = 'hard';

    /** Route identifier for non-adaptive fixed modules. */
    public const ROUTE_FIXED = 'fixed';

    /**
     * Evaluates Module 1 score percentage against the cutoff threshold.
     *
     * @param float $rawscore Scored points earned in Module 1
     * @param float $maxscore Total possible scored points in Module 1
     * @param int $threshold Percentage required for the harder module (e.g. 60)
     * @return string ROUTE_HARD or ROUTE_EASY
     */
    public static function determine_route(float $rawscore, float $maxscore, int $threshold): string {
        if ($maxscore <= 0.0) {
            return self::ROUTE_EASY;
        }

        $percentage = ($rawscore / $maxscore) * 100.0;

        if ($percentage >= (float)$threshold) {
            return self::ROUTE_HARD;
        }

        return self::ROUTE_EASY;
    }

    /**
     * Resolves the target Module 2 database record based on the evaluated route.
     *
     * @param int $satid SAT instance ID
     * @param string $section Section ('rw' or 'math')
     * @param string $route Evaluated route ('easy', 'hard', or 'fixed')
     * @return stdClass|null The target sat_modules record or null if not found
     */
    public static function get_target_module(int $satid, string $section, string $route): ?stdClass {
        global $DB;

        $targetstage = match ($route) {
            self::ROUTE_HARD => 'm2_hard',
            self::ROUTE_EASY => 'm2_easy',
            default => 'm2_fixed',
        };

        $module = $DB->get_record('sat_modules', [
            'satid' => $satid,
            'section' => $section,
            'stage' => $targetstage,
        ]);

        // Fallback: If specific easy/hard module is not found, fallback to any available m2 module.
        if (!$module) {
            $fallbackmodules = $DB->get_records_select(
                'sat_modules',
                'satid = :satid AND section = :section AND stage LIKE :m2prefix',
                ['satid' => $satid, 'section' => $section, 'm2prefix' => 'm2%'],
                'sortorder ASC',
                '*',
                0,
                1
            );
            if (!empty($fallbackmodules)) {
                $module = reset($fallbackmodules);
            }
        }

        return $module ?: null;
    }
}
