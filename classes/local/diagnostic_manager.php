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

use question_engine;
use stdClass;

/**
 * Diagnostic analysis and cohort reporting engine for mod_sat.
 *
 * Aggregates student attempt performance across the 8 official SAT
 * content domains and 19 sub-skills based on question tags.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_manager {
    /** @var string Craft and Structure domain */
    public const DOMAIN_CRAFT_STRUCTURE = 'sat_domain:craft_structure';

    /** @var string Information and Ideas domain */
    public const DOMAIN_INFO_IDEAS = 'sat_domain:information_ideas';

    /** @var string Standard English Conventions domain */
    public const DOMAIN_STANDARD_ENGLISH = 'sat_domain:standard_english';

    /** @var string Expression of Ideas domain */
    public const DOMAIN_EXPRESSION_IDEAS = 'sat_domain:expression_ideas';

    /** @var string Algebra domain */
    public const DOMAIN_ALGEBRA = 'sat_domain:algebra';

    /** @var string Advanced Math domain */
    public const DOMAIN_ADVANCED_MATH = 'sat_domain:advanced_math';

    /** @var string Problem-Solving and Data Analysis domain */
    public const DOMAIN_PROBLEM_SOLVING = 'sat_domain:problem_solving_data';

    /** @var string Geometry and Trigonometry domain */
    public const DOMAIN_GEOMETRY_TRIG = 'sat_domain:geometry_trig';

    /**
     * Returns human-readable label for a domain key.
     *
     * @param string $domainkey Domain key
     * @return string Human-friendly label
     */
    public static function get_domain_label(string $domainkey): string {
        $labels = [
            self::DOMAIN_CRAFT_STRUCTURE => 'Craft and Structure',
            self::DOMAIN_INFO_IDEAS => 'Information and Ideas',
            self::DOMAIN_STANDARD_ENGLISH => 'Standard English Conventions',
            self::DOMAIN_EXPRESSION_IDEAS => 'Expression of Ideas',
            self::DOMAIN_ALGEBRA => 'Algebra',
            self::DOMAIN_ADVANCED_MATH => 'Advanced Math',
            self::DOMAIN_PROBLEM_SOLVING => 'Problem-Solving and Data Analysis',
            self::DOMAIN_GEOMETRY_TRIG => 'Geometry and Trigonometry',
        ];

        $cleankey = str_replace('sat_domain:', 'domain_', $domainkey);
        if (function_exists('get_string') && get_string_manager()->string_exists($cleankey, 'mod_sat')) {
            return get_string($cleankey, 'mod_sat');
        }

        return $labels[$domainkey] ?? ucfirst(str_replace(['sat_domain:', '_'], ['', ' '], $domainkey));
    }

    /**
     * Analyzes question-level responses for an attempt and computes domain breakdown.
     *
     * @param stdClass $attempt The sat_attempts record
     * @return array List of domain statistics [total, correct, percent, status]
     */
    public static function analyze_attempt_domains(stdClass $attempt): array {
        global $DB;

        $results = [];

        if (empty($attempt->uniqueid) || !class_exists('question_engine')) {
            return $results;
        }

        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $slots = $quba->get_slots();

        foreach ($slots as $slotnum) {
            $question = $quba->get_question($slotnum);
            if (!$question) {
                continue;
            }

            $mark = (float)$quba->get_question_mark($slotnum);
            $maxmark = (float)$quba->get_question_max_mark($slotnum);
            $iscorrect = ($maxmark > 0 && ($mark / $maxmark) >= 0.99);

            // Fetch question tags.
            $tags = $DB->get_records_sql(
                "SELECT t.id, t.rawname
                   FROM {tag} t
                   JOIN {tag_instance} ti ON ti.tagid = t.id
                  WHERE ti.component = 'core_question'
                    AND ti.itemtype = 'question'
                    AND ti.itemid = :qid",
                ['qid' => $question->id]
            );

            $domain = 'sat_domain:general';
            foreach ($tags as $t) {
                if (str_starts_with($t->rawname, 'sat_domain:')) {
                    $domain = $t->rawname;
                    break;
                }
            }

            if (!isset($results[$domain])) {
                $results[$domain] = [
                    'key' => $domain,
                    'label' => self::get_domain_label($domain),
                    'total' => 0,
                    'correct' => 0,
                    'percent' => 0,
                    'status' => 'developing',
                ];
            }

            $results[$domain]['total']++;
            if ($iscorrect) {
                $results[$domain]['correct']++;
            }
        }

        // Calculate percentages and mastery status.
        foreach ($results as &$r) {
            if ($r['total'] > 0) {
                $r['percent'] = (int) round(($r['correct'] / $r['total']) * 100);
            }
            if ($r['percent'] >= 80) {
                $r['status'] = 'mastered';
            } else if ($r['percent'] >= 60) {
                $r['status'] = 'proficient';
            } else {
                $r['status'] = 'developing';
            }
        }

        return $results;
    }

    /**
     * Computes cohort analytics across all completed attempts for an SAT activity.
     *
     * @param int $satid The activity instance ID
     * @return array Cohort metrics
     */
    public static function get_cohort_analytics(int $satid): array {
        global $DB;

        $attempts = $DB->get_records('sat_attempts', [
            'satid' => $satid,
            'state' => 'finished',
        ]);

        $totalattempts = count($attempts);
        if ($totalattempts === 0) {
            return [
                'totalattempts' => 0,
                'averagetotal' => 0,
                'averagerw' => 0,
                'averagemath' => 0,
                'highesttotal' => 0,
                'lowesttotal' => 0,
                'rwhardrate' => 0,
                'mathhardrate' => 0,
            ];
        }

        $sumtotal = 0;
        $sumrw = 0;
        $summath = 0;
        $highest = 0;
        $lowest = 1600;
        $rwhardcount = 0;
        $mathhardcount = 0;

        foreach ($attempts as $a) {
            $total = (int)$a->scaled_total;
            $sumtotal += $total;
            $sumrw += (int)$a->scaled_rw;
            $summath += (int)$a->scaled_math;

            if ($total > $highest) {
                $highest = $total;
            }
            if ($total < $lowest) {
                $lowest = $total;
            }

            if ($a->route_rw === 'hard') {
                $rwhardcount++;
            }
            if ($a->route_math === 'hard') {
                $mathhardcount++;
            }
        }

        return [
            'totalattempts' => $totalattempts,
            'averagetotal' => (int) round($sumtotal / $totalattempts),
            'averagerw' => (int) round($sumrw / $totalattempts),
            'averagemath' => (int) round($summath / $totalattempts),
            'highesttotal' => $highest,
            'lowesttotal' => ($lowest === 1600 && $totalattempts === 0) ? 0 : $lowest,
            'rwhardrate' => (int) round(($rwhardcount / $totalattempts) * 100),
            'mathhardrate' => (int) round(($mathhardcount / $totalattempts) * 100),
        ];
    }

    /**
     * Computes cohort score distribution into score range buckets.
     *
     * @param int $satid The activity instance ID
     * @return array Score buckets with attempt counts and percentages
     */
    public static function get_cohort_score_distribution(int $satid): array {
        global $DB;

        $attempts = $DB->get_records('sat_attempts', [
            'satid' => $satid,
            'state' => 'finished',
        ]);

        $totalattempts = count($attempts);
        $buckets = [
            '1400-1600' => 0,
            '1200-1390' => 0,
            '1000-1190' => 0,
            '800-990' => 0,
            '400-790' => 0,
        ];

        foreach ($attempts as $a) {
            $score = (int)$a->scaled_total;
            if ($score >= 1400) {
                $buckets['1400-1600']++;
            } else if ($score >= 1200) {
                $buckets['1200-1390']++;
            } else if ($score >= 1000) {
                $buckets['1000-1190']++;
            } else if ($score >= 800) {
                $buckets['800-990']++;
            } else {
                $buckets['400-790']++;
            }
        }

        $distribution = [];
        foreach ($buckets as $range => $count) {
            $pct = $totalattempts > 0 ? (int) round(($count / $totalattempts) * 100) : 0;
            $distribution[$range] = [
                'range' => $range,
                'count' => $count,
                'percent' => $pct,
            ];
        }

        return $distribution;
    }

    /**
     * Computes cohort domain accuracy across all finished attempts.
     *
     * @param int $satid The activity instance ID
     * @return array Domain breakdown for the cohort
     */
    public static function get_cohort_domain_breakdown(int $satid): array {
        global $DB;

        $attempts = $DB->get_records('sat_attempts', [
            'satid' => $satid,
            'state' => 'finished',
        ]);

        $domains = [];
        foreach ($attempts as $a) {
            $attdomains = self::analyze_attempt_domains($a);
            foreach ($attdomains as $key => $d) {
                if (!isset($domains[$key])) {
                    $domains[$key] = [
                        'key' => $key,
                        'label' => $d['label'],
                        'total' => 0,
                        'correct' => 0,
                        'percent' => 0,
                    ];
                }
                $domains[$key]['total'] += $d['total'];
                $domains[$key]['correct'] += $d['correct'];
            }
        }

        foreach ($domains as &$d) {
            if ($d['total'] > 0) {
                $d['percent'] = (int) round(($d['correct'] / $d['total']) * 100);
            }
        }

        return $domains;
    }
}
