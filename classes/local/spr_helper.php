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

/**
 * Official SAT Student-Produced Response (SPR / Grid-In) rule validator and answer generator.
 *
 * Implements the official Digital SAT free-response mathematical equivalence,
 * character width limits, truncation, and rounding specifications.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spr_helper {
    /** Maximum character length for positive responses. */
    public const MAX_LEN_POSITIVE = 5;

    /** Maximum character length for negative responses (including minus). */
    public const MAX_LEN_NEGATIVE = 6;

    /**
     * Validates whether a raw student input string conforms to SAT SPR format constraints.
     *
     * Permitted: digits (0-9), decimal point (.), fraction slash (/), and leading negative sign (-).
     * No spaces, mixed numbers, commas, or percent signs allowed.
     *
     * @param string $input Raw student input
     * @return array [bool $valid, string $error]
     */
    public static function validate_format(string $input): array {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return [false, 'empty_input'];
        }

        $isnegative = str_starts_with($trimmed, '-');
        $maxlen = $isnegative ? self::MAX_LEN_NEGATIVE : self::MAX_LEN_POSITIVE;

        if (strlen($trimmed) > $maxlen) {
            return [false, 'max_length_exceeded'];
        }

        // Only allowed characters: digits, ., /, - (only at start).
        if (!preg_match('/^-?[0-9.\/]+$/', $trimmed)) {
            return [false, 'invalid_characters'];
        }

        // Check for multiple dots or slashes.
        $dots = substr_count($trimmed, '.');
        $slashes = substr_count($trimmed, '/');

        if ($dots > 1 || $slashes > 1) {
            return [false, 'multiple_separators'];
        }

        // Cannot have both slash and decimal point.
        if ($dots > 0 && $slashes > 0) {
            return [false, 'mixed_decimal_and_fraction'];
        }

        // Slash cannot be at the start or end, or preceded immediately by minus.
        if ($slashes === 1) {
            if ($trimmed[0] === '/' || str_ends_with($trimmed, '/') || str_contains($trimmed, '-/')) {
                return [false, 'invalid_fraction_syntax'];
            }
            $parts = explode('/', $trimmed);
            if (isset($parts[1]) && (int)$parts[1] === 0) {
                return [false, 'division_by_zero'];
            }
        }

        return [true, ''];
    }

    /**
     * Evaluates a valid numeric or fraction string into a PHP float value.
     *
     * @param string $input Valid SPR string
     * @return float|null Evaluated numeric float or null on invalid syntax
     */
    public static function parse_to_float(string $input): ?float {
        $trimmed = trim($input);

        if (str_contains($trimmed, '/')) {
            $parts = explode('/', $trimmed);
            if (count($parts) !== 2) {
                return null;
            }
            $num = (float)$parts[0];
            $den = (float)$parts[1];
            if ($den == 0.0) {
                return null;
            }
            return $num / $den;
        }

        if (is_numeric($trimmed)) {
            return (float)$trimmed;
        }

        return null;
    }

    /**
     * Generates all official SAT-accepted equivalent representations for a given canonical answer.
     *
     * For example, for '2/3', generates: '2/3', '.6666', '.6667', '0.666', '0.667'.
     * For '0.75', generates: '0.75', '.75', '3/4'.
     *
     * @param string $canonical Primary answer string (e.g. '2/3', '0.75', '-1.5')
     * @return array List of all accepted equivalent strings
     */
    public static function generate_accepted_forms(string $canonical): array {
        $canonical = trim($canonical);
        $accepted = [$canonical];

        $val = self::parse_to_float($canonical);
        if ($val === null) {
            return $accepted;
        }

        $isneg = ($val < 0);
        $sign = $isneg ? '-' : '';
        $absval = abs($val);

        // 1. If canonical is a fraction, generate truncated and rounded decimal representations.
        if (str_contains($canonical, '/')) {
            // Check if it terminates cleanly.
            $exactdec = (string)$val;
            $maxwidth = $isneg ? self::MAX_LEN_NEGATIVE : self::MAX_LEN_POSITIVE;

            if (strlen($exactdec) <= $maxwidth) {
                $accepted[] = $exactdec;
                if (str_starts_with($exactdec, '0.')) {
                    $accepted[] = substr($exactdec, 1);
                } else if (str_starts_with($exactdec, '-0.')) {
                    $accepted[] = '-' . substr($exactdec, 3);
                }
            }

            // Decimal representations filling allowed character width.
            // Positives: 5 chars max (e.g. '.6666', '0.666', '0.667').
            // Negatives: 6 chars max (e.g. '-.6666', '-0.666', '-0.667').
            for ($decimals = 3; $decimals <= 4; $decimals++) {
                // Truncated representation.
                $factor = pow(10, $decimals);
                $truncated = floor($absval * $factor) / $factor;
                $tstr = sprintf("%." . $decimals . "f", $truncated);

                // Rounded representation.
                $rounded = round($absval, $decimals);
                $rstr = sprintf("%." . $decimals . "f", $rounded);

                foreach ([$tstr, $rstr] as $s) {
                    $fullwithzero = $sign . $s;
                    if (strlen($fullwithzero) <= $maxwidth) {
                        $accepted[] = $fullwithzero;
                    }
                    if (str_starts_with($s, '0.')) {
                        $withoutzero = $sign . substr($s, 1);
                        if (strlen($withoutzero) <= $maxwidth) {
                            $accepted[] = $withoutzero;
                        }
                    }
                }
            }
        } else {
            // Canonical is decimal or integer.
            // Generate variant with/without leading zero (e.g. 0.75 <=> .75).
            if (str_starts_with($canonical, '0.')) {
                $accepted[] = substr($canonical, 1);
            } else if (str_starts_with($canonical, '.彭') || str_starts_with($canonical, '.')) {
                $accepted[] = '0' . $canonical;
            } else if (str_starts_with($canonical, '-0.')) {
                $accepted[] = '-' . substr($canonical, 2);
            }

            // If common simple fraction exists (e.g. 0.5 -> 1/2, 0.75 -> 3/4, 0.25 -> 1/4), add it.
            $commonfractions = [
                '0.5' => '1/2', '.5' => '1/2', '-0.5' => '-1/2',
                '0.25' => '1/4', '.25' => '1/4',
                '0.75' => '3/4', '.75' => '3/4',
                '0.2' => '1/5', '.2' => '1/5',
                '0.4' => '2/5', '.4' => '2/5',
                '0.6' => '3/5', '.6' => '3/5',
                '0.8' => '4/5', '.8' => '4/5',
                '0.125' => '1/8', '.125' => '1/8',
                '0.375' => '3/8', '.375' => '3/8',
                '0.625' => '5/8', '.625' => '5/8',
                '0.875' => '7/8', '.875' => '7/8',
            ];

            if (isset($commonfractions[$canonical])) {
                $accepted[] = $commonfractions[$canonical];
            }
        }

        return array_values(array_unique($accepted));
    }

    /**
     * Checks if a student's response matches any accepted answer under SAT rules.
     *
     * @param string $studentresponse Student's input
     * @param array $acceptedanswers List of correct/accepted answer strings
     * @return bool True if response is correct
     */
    public static function is_correct(string $studentresponse, array $acceptedanswers): bool {
        [$valid] = self::validate_format($studentresponse);
        if (!$valid) {
            return false;
        }

        $studentval = self::parse_to_float($studentresponse);
        if ($studentval === null) {
            return false;
        }

        // Build list of all valid accepted forms.
        $allaccepted = [];
        foreach ($acceptedanswers as $ans) {
            foreach (self::generate_accepted_forms($ans) as $form) {
                $allaccepted[] = $form;
            }
        }

        // Direct exact match against expanded accepted strings.
        if (in_array(trim($studentresponse), $allaccepted, true)) {
            return true;
        }

        // Mathematical equivalence fallback within 4-decimal truncation/rounding tolerance.
        foreach ($acceptedanswers as $ans) {
            $ansval = self::parse_to_float($ans);
            if ($ansval !== null && abs($studentval - $ansval) < 0.0005) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a student's response is mathematically equivalent to a single canonical answer.
     *
     * @param string $studentresponse Student's input
     * @param string $canonical Single canonical answer string from question_answers
     * @return bool True if response is valid and equivalent under SAT SPR rules
     */
    public static function is_equivalent(string $studentresponse, string $canonical): bool {
        $clean = trim(strip_tags($canonical));
        return self::is_correct($studentresponse, [$clean]);
    }
}
