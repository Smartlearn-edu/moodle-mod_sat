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

use context;
use core_question\local\bank\question_bank_helper;
use stdClass;
use Throwable;

/**
 * Manages the module and slot structure of an SAT exam instance.
 *
 * Integrates with Moodle 5.2 question_references and question_set_references.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure_manager {
    /**
     * Retrieves all modules for a given sat activity instance.
     *
     * @param int $satid The sat activity instance ID
     * @return array List of module records ordered by sortorder
     */
    public static function get_modules(int $satid): array {
        global $DB;
        return $DB->get_records('sat_modules', ['satid' => $satid], 'sortorder ASC');
    }

    /**
     * Retrieves all question slots for a given module, with question details.
     *
     * @param int $moduleid The sat_modules ID
     * @return array List of slot objects with attached question details
     */
    public static function get_slots(int $moduleid): array {
        global $DB;

        $sql = "SELECT s.*,
                       qr.questionbankentryid,
                       qc.contextid AS questioncontextid,
                       q.id AS questionid,
                       q.name AS questionname,
                       q.qtype,
                       q.questiontext,
                       q.questiontextformat
                  FROM {sat_slots} s
             LEFT JOIN {question_references} qr
                    ON qr.itemid = s.id
                   AND qr.component = 'mod_sat'
                   AND qr.questionarea = 'slot'
             LEFT JOIN {question_bank_entries} qbe
                    ON qbe.id = qr.questionbankentryid
             LEFT JOIN {question_categories} qc
                    ON qc.id = qbe.questioncategoryid
             LEFT JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = 'ready'
                   AND qv.version = (
                       SELECT MAX(qv2.version)
                         FROM {question_versions} qv2
                        WHERE qv2.questionbankentryid = qbe.id
                          AND qv2.status = 'ready'
                   )
             LEFT JOIN {question} q
                    ON q.id = qv.questionid
                 WHERE s.moduleid = :moduleid
              ORDER BY s.slot ASC";

        return $DB->get_records_sql($sql, ['moduleid' => $moduleid]);
    }

    /**
     * Adds a fixed question slot to an SAT module.
     *
     * @param int $satid SAT activity ID
     * @param int $moduleid Module ID
     * @param int $contextid Context ID of the course module
     * @param int $qbankentryid Question bank entry ID
     * @param float $maxmark Maximum grade mark for this slot (default 1.0)
     * @param bool $isscored Whether this question counts toward the score (1) or is unscored pretest (0)
     * @param bool $isspr Whether this question is a math student-produced response
     * @return int New slot ID
     */
    public static function add_fixed_slot(
        int $satid,
        int $moduleid,
        int $contextid,
        int $qbankentryid,
        float $maxmark = 1.0,
        bool $isscored = true,
        bool $isspr = false
    ): int {
        global $DB;

        // Determine next slot number.
        $maxslot = $DB->get_field('sat_slots', 'MAX(slot)', ['moduleid' => $moduleid]);
        $nextslot = $maxslot ? ((int)$maxslot + 1) : 1;

        $slot = new stdClass();
        $slot->satid = $satid;
        $slot->moduleid = $moduleid;
        $slot->slot = $nextslot;
        $slot->maxmark = $maxmark;
        $slot->is_scored = $isscored ? 1 : 0;
        $slot->is_spr = $isspr ? 1 : 0;
        $slot->slottype = 'fixed';

        $slotid = $DB->insert_record('sat_slots', $slot);

        // Register in Moodle 5.2 question_references.
        $ref = new stdClass();
        $ref->usingcontextid = $contextid;
        $ref->component = 'mod_sat';
        $ref->questionarea = 'slot';
        $ref->itemid = $slotid;
        $ref->questionbankentryid = $qbankentryid;
        $ref->version = null; // Always reference the latest ready version.

        $DB->insert_record('question_references', $ref);

        return $slotid;
    }

    /**
     * Deletes a question slot and its associated reference, renumbering remaining slots.
     *
     * @param int $slotid Slot ID to delete
     * @return bool True on success
     */
    public static function delete_slot(int $slotid): bool {
        global $DB;

        $slot = $DB->get_record('sat_slots', ['id' => $slotid]);
        if (!$slot) {
            return false;
        }

        // Delete question references.
        $DB->delete_records('question_references', [
            'component' => 'mod_sat',
            'questionarea' => 'slot',
            'itemid' => $slotid,
        ]);
        $DB->delete_records('question_set_references', [
            'usingcontextid' => null,
            'component' => 'mod_sat',
            'questionarea' => 'slot',
            'itemid' => $slotid,
        ]);

        $DB->delete_records('sat_slots', ['id' => $slotid]);

        // Renumber remaining slots in the module.
        $remaining = $DB->get_records('sat_slots', ['moduleid' => $slot->moduleid], 'slot ASC');
        $num = 1;
        foreach ($remaining as $r) {
            if ($r->slot !== $num) {
                $r->slot = $num;
                $DB->update_record('sat_slots', $r);
            }
            $num++;
        }

        return true;
    }

    /**
     * Retrieves all accessible question banks for the course and SAT activity context.
     *
     * @param int $courseid Course ID
     * @param int $cmcontextid SAT activity module context ID
     * @return array List of bank objects keyed by contextid
     */
    public static function get_available_question_banks(int $courseid, int $cmcontextid): array {
        global $DB;

        $banks = [];
        $coursectx = \core\context\course::instance($courseid, IGNORE_MISSING);
        $modctx = context::instance_by_id($cmcontextid, IGNORE_MISSING);

        // 1. Discover Moodle 5.2 shared banks in this course and recently viewed banks.
        if ($coursectx && class_exists(question_bank_helper::class)) {
            try {
                $modulearg = ($modctx instanceof \core\context\module) ? $modctx : null;
                $helperbanks = question_bank_helper::get_banks_for_course($coursectx, $modulearg, true, true);
                foreach ($helperbanks as $fb) {
                    $formatted = $fb->get_formatted();
                    $ctxid = (int)$formatted->contextid;
                    $incourse = ((int)$formatted->cminfo->course === $courseid);
                    $banks[$ctxid] = (object)[
                        'contextid' => $ctxid,
                        'cmid' => (int)$formatted->modid,
                        'name' => $incourse ? (string)$formatted->name : (string)$formatted->coursenamebankname,
                        'modname' => (string)$formatted->cminfo->modname,
                        'questioncount' => 0,
                        'incourse' => $incourse,
                    ];
                }
            } catch (Throwable $e) {
                // Fall back to direct context discovery when fast modinfo/helper is unavailable.
                debugging('Failed to load shared question banks via helper: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // 2. Discover course-local contexts containing non-top question categories.
        $candidatectxids = array_keys($banks);
        if ($modctx) {
            $candidatectxids = array_merge($candidatectxids, $modctx->get_parent_context_ids(true));
        }
        if ($coursectx) {
            $childids = $DB->get_fieldset_select(
                'context',
                'id',
                'contextlevel = :level AND ' . $DB->sql_like('path', ':path'),
                ['level' => CONTEXT_MODULE, 'path' => $DB->sql_like_escape($coursectx->path) . '/%']
            );
            if (!empty($childids)) {
                $candidatectxids = array_merge($candidatectxids, array_map('intval', $childids));
            }
        }

        $candidatectxids = array_values(array_unique(array_filter(array_map('intval', $candidatectxids))));
        if (empty($candidatectxids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($candidatectxids, SQL_PARAMS_NAMED);

        // Identify contexts that actually have selectable (non-top) question categories.
        $catcontextids = $DB->get_fieldset_sql(
            "SELECT DISTINCT qc.contextid
               FROM {question_categories} qc
              WHERE qc.contextid $insql
                AND qc.parent <> 0",
            $params
        );
        $catcontextids = array_map('intval', $catcontextids);

        $allctxids = array_values(array_unique(array_merge(array_keys($banks), $catcontextids)));
        if (empty($allctxids)) {
            return [];
        }

        // Pre-calculate ready question counts per context in a single query.
        [$countinsql, $countparams] = $DB->get_in_or_equal($allctxids, SQL_PARAMS_NAMED);
        $countparams['status'] = 'ready';
        $counts = $DB->get_records_sql_menu(
            "SELECT qc.contextid,
                    COUNT(DISTINCT qbe.id) AS qcount
               FROM {question_categories} qc
               JOIN {question_bank_entries} qbe
                 ON qbe.questioncategoryid = qc.id
               JOIN {question_versions} qv
                 ON qv.questionbankentryid = qbe.id
                AND qv.status = :status
               JOIN {question} q
                 ON q.id = qv.questionid
              WHERE qc.contextid $countinsql
                AND qc.parent <> 0
                AND q.parent = 0
           GROUP BY qc.contextid",
            $countparams
        );

        foreach ($banks as $ctxid => $bank) {
            $bank->questioncount = isset($counts[$ctxid]) ? (int)$counts[$ctxid] : 0;
        }

        // Add fallback course/private activity contexts that contain ready questions.
        $modinfo = null;
        $cms = [];
        try {
            $modinfo = get_fast_modinfo($courseid);
            $cms = $modinfo->get_cms();
        } catch (Throwable $e) {
            $cms = [];
        }

        foreach ($catcontextids as $ctxid) {
            if (isset($banks[$ctxid])) {
                continue;
            }
            $qcount = isset($counts[$ctxid]) ? (int)$counts[$ctxid] : 0;
            $ctx = context::instance_by_id($ctxid, IGNORE_MISSING);
            if (!$ctx) {
                continue;
            }

            $cmid = 0;
            $modname = 'course';
            $name = $ctx->get_context_name(true, true);
            if ($ctx->contextlevel === CONTEXT_MODULE) {
                $cm = $cms[$ctx->instanceid] ?? null;
                if ($cm) {
                    $cmid = (int)$cm->id;
                    $modname = (string)$cm->modname;
                    $name = $cm->get_formatted_name(['escape' => false, 'context' => $modctx ?: $ctx]);
                    if ($modname !== 'qbank') {
                        $modtitle = get_string_manager()->string_exists('modulename', $modname)
                            ? get_string('modulename', $modname)
                            : $modname;
                        $name .= ' (' . $modtitle . ')';
                    }
                } else {
                    $modname = 'module';
                }
            }

            // Skip empty non-qbank contexts (e.g. unused quiz default categories) to keep picker clean.
            if ($modname !== 'qbank' && $qcount === 0) {
                continue;
            }

            $banks[$ctxid] = (object)[
                'contextid' => $ctxid,
                'cmid' => $cmid,
                'name' => $name,
                'modname' => $modname,
                'questioncount' => $qcount,
                'incourse' => true,
            ];
        }

        // Sort: course qbank with questions first, other populated banks next, empty qbanks last.
        uasort($banks, static function ($a, $b) {
            $scorea = self::bank_sort_priority($a);
            $scoreb = self::bank_sort_priority($b);
            if ($scorea !== $scoreb) {
                return $scorea <=> $scoreb;
            }
            return strnatcasecmp($a->name, $b->name);
        });

        return $banks;
    }

    /**
     * Computes a sort priority tier for a question bank object.
     *
     * @param stdClass $bank Bank metadata object
     * @return int Lower values appear earlier in the selector
     */
    private static function bank_sort_priority(stdClass $bank): int {
        $hasquestions = ($bank->questioncount > 0);
        $isqbank = ($bank->modname === 'qbank');
        if ($bank->incourse && $isqbank && $hasquestions) {
            return 1;
        }
        if ($isqbank && $hasquestions) {
            return 2;
        }
        if ($hasquestions) {
            return 3;
        }
        if ($bank->incourse && $isqbank) {
            return 4;
        }
        return 5;
    }

    /**
     * Retrieves hierarchically ordered question categories for a given question bank context.
     *
     * @param int $bankcontextid Question bank context ID
     * @return array Ordered array of category objects keyed by category ID
     */
    public static function get_bank_categories(int $bankcontextid): array {
        global $DB;

        if ($bankcontextid <= 0) {
            return [];
        }

        $sql = "SELECT qc.id,
                       qc.name,
                       qc.parent,
                       qc.contextid,
                       qc.sortorder,
                       COUNT(DISTINCT CASE WHEN q.id IS NOT NULL THEN qbe.id ELSE NULL END) AS questioncount
                  FROM {question_categories} qc
             LEFT JOIN {question_bank_entries} qbe
                    ON qbe.questioncategoryid = qc.id
             LEFT JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = :status
             LEFT JOIN {question} q
                    ON q.id = qv.questionid
                   AND q.parent = 0
                 WHERE qc.contextid = :contextid
              GROUP BY qc.id, qc.name, qc.parent, qc.contextid, qc.sortorder
              ORDER BY qc.parent ASC, qc.sortorder ASC, qc.name ASC";

        $records = $DB->get_records_sql($sql, [
            'status' => 'ready',
            'contextid' => $bankcontextid,
        ]);

        if (empty($records)) {
            return [];
        }

        $ctx = context::instance_by_id($bankcontextid, IGNORE_MISSING);
        return self::format_category_tree($records, $ctx ?: null);
    }

    /**
     * Formats raw category records into an indented hierarchical list with breadcrumb paths and rolled-up counts.
     *
     * @param array $records Raw category objects keyed by id
     * @param context|null $ctx Context for format_string()
     * @return array Ordered array of formatted category objects keyed by id
     */
    public static function format_category_tree(array $records, ?context $ctx = null): array {
        $allcats = [];
        $topids = [];
        foreach ($records as $rec) {
            $cat = (object)[
                'id' => (int)$rec->id,
                'name' => (string)$rec->name,
                'parent' => (int)$rec->parent,
                'contextid' => (int)$rec->contextid,
                'sortorder' => (int)$rec->sortorder,
                'questioncount' => (int)($rec->questioncount ?? 0),
                'totalquestioncount' => (int)($rec->questioncount ?? 0),
            ];
            $allcats[$cat->id] = $cat;
            if ($cat->parent === 0) {
                $topids[$cat->id] = true;
            }
        }

        // Build adjacency map for selectable (non-top) categories.
        $childrenmap = [];
        $rootids = [];
        foreach ($allcats as $cat) {
            if ($cat->parent === 0) {
                continue;
            }
            if (isset($topids[$cat->parent]) || !isset($allcats[$cat->parent])) {
                $rootids[] = $cat->id;
            } else {
                $childrenmap[$cat->parent][] = $cat->id;
            }
        }

        $ordered = [];
        foreach ($rootids as $rootid) {
            $ordered += self::build_category_tree_nodes($allcats, $childrenmap, [], $rootid, 0, $ctx);
        }

        return $ordered;
    }

    /**
     * Recursively traverses category nodes to compute depth, indentation, pathname, and rolled-up counts.
     *
     * @param array $allcats Map of all category objects
     * @param array $childrenmap Parent ID => child IDs list
     * @param array $ancestornames Ordered list of ancestor display names
     * @param int $catid Current category ID
     * @param int $depth Current tree depth (0-based)
     * @param context|null $ctx Optional context for format_string()
     * @return array Ordered map of formatted category objects rooted at $catid
     */
    private static function build_category_tree_nodes(
        array $allcats,
        array $childrenmap,
        array $ancestornames,
        int $catid,
        int $depth,
        ?context $ctx = null
    ): array {
        $cat = clone $allcats[$catid];
        $displayname = $ctx ? format_string($cat->name, true, ['context' => $ctx]) : $cat->name;
        $pathparts = array_merge($ancestornames, [$displayname]);

        $cat->depth = $depth;
        $cat->displayname = $displayname;
        $cat->indentedname = str_repeat('— ', $depth) . $displayname;
        $cat->pathname = implode(' / ', $pathparts);

        $childnodes = [];
        $childids = $childrenmap[$catid] ?? [];
        $childtotalsum = 0;
        foreach ($childids as $childid) {
            $subtree = self::build_category_tree_nodes($allcats, $childrenmap, $pathparts, $childid, $depth + 1, $ctx);
            if (isset($subtree[$childid])) {
                $childtotalsum += $subtree[$childid]->totalquestioncount;
            }
            $childnodes += $subtree;
        }

        $cat->totalquestioncount = $cat->questioncount + $childtotalsum;
        if ($cat->totalquestioncount > $cat->questioncount) {
            $cat->label = $cat->indentedname . ' (' . $cat->questioncount . ' / ' . $cat->totalquestioncount . ' total)';
        } else {
            $cat->label = $cat->indentedname . ' (' . $cat->questioncount . ')';
        }

        return [$cat->id => $cat] + $childnodes;
    }

    /**
     * Collects a category ID and all of its descendant category IDs from an ordered category list.
     *
     * @param array $categories Ordered category objects from get_bank_categories() or format_category_tree()
     * @param int $rootcatid Selected parent category ID
     * @return array List of integer category IDs including $rootcatid and all descendants
     */
    public static function get_descendant_category_ids(array $categories, int $rootcatid): array {
        if (!isset($categories[$rootcatid])) {
            return [];
        }

        $childrenmap = [];
        foreach ($categories as $cat) {
            $childrenmap[(int)$cat->parent][] = (int)$cat->id;
        }

        $ids = [];
        $queue = [$rootcatid];
        $visited = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $ids[] = $current;
            if (!empty($childrenmap[$current])) {
                foreach ($childrenmap[$current] as $childid) {
                    $queue[] = $childid;
                }
            }
        }

        return $ids;
    }

    /**
     * Retrieves ready questions for a selected question bank and optional category, sorted naturally.
     *
     * @param int $bankcontextid Question bank context ID
     * @param int $categoryid Category ID (0 for all categories in the bank)
     * @param bool $includesubcategories Whether to include descendant categories
     * @param string $search Optional search filter matching question name, text, or entry ID
     * @param string $qtype Optional filter ('mcq', 'spr', or specific qtype)
     * @return array List of question objects keyed by qbankentryid
     */
    public static function get_questions_for_category(
        int $bankcontextid,
        int $categoryid = 0,
        bool $includesubcategories = true,
        string $search = '',
        string $qtype = ''
    ): array {
        global $DB;

        $categories = self::get_bank_categories($bankcontextid);
        if (empty($categories)) {
            return [];
        }

        if ($categoryid > 0 && isset($categories[$categoryid])) {
            $catids = $includesubcategories
                ? self::get_descendant_category_ids($categories, $categoryid)
                : [$categoryid];
        } else {
            $catids = array_keys($categories);
        }

        if (empty($catids)) {
            return [];
        }

        [$catinsql, $params] = $DB->get_in_or_equal($catids, SQL_PARAMS_NAMED);

        $sql = "SELECT qbe.id AS qbankentryid,
                       qbe.questioncategoryid AS categoryid,
                       q.id AS questionid,
                       q.name,
                       q.qtype,
                       q.questiontext,
                       qc.name AS categoryname
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc
                    ON qc.id = qbe.questioncategoryid
                  JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = 'ready'
                   AND qv.version = (
                       SELECT MAX(qv2.version)
                         FROM {question_versions} qv2
                        WHERE qv2.questionbankentryid = qbe.id
                          AND qv2.status = 'ready'
                   )
                  JOIN {question} q
                    ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid $catinsql
                   AND q.parent = 0";

        $records = $DB->get_records_sql($sql, $params);
        if (empty($records)) {
            return [];
        }

        $searchnorm = \core_text::strtolower(trim($search));
        $qtypenorm = trim($qtype);
        $filtered = [];

        foreach ($records as $q) {
            $q->categorypath = $categories[(int)$q->categoryid]->pathname ?? $q->categoryname;

            if ($qtypenorm !== '') {
                if ($qtypenorm === 'mcq' && $q->qtype !== 'multichoice') {
                    continue;
                } else if ($qtypenorm === 'spr' && !in_array($q->qtype, ['shortanswer', 'numerical'], true)) {
                    continue;
                } else if (!in_array($qtypenorm, ['mcq', 'spr'], true) && $q->qtype !== $qtypenorm) {
                    continue;
                }
            }

            if ($searchnorm !== '') {
                $haystack = \core_text::strtolower(
                    $q->name . ' ' . strip_tags((string)$q->questiontext) . ' ' . $q->qbankentryid
                );
                if (!str_contains($haystack, $searchnorm)) {
                    continue;
                }
            }

            $filtered[(int)$q->qbankentryid] = $q;
        }

        uasort($filtered, static function ($a, $b) {
            $cmp = strnatcasecmp((string)$a->name, (string)$b->name);
            if ($cmp !== 0) {
                return $cmp;
            }
            return (int)$a->qbankentryid <=> (int)$b->qbankentryid;
        });

        return $filtered;
    }

    /**
     * Returns a map of question bank entries already used across modules of an SAT exam.
     *
     * @param int $satid SAT instance ID
     * @return array Map of qbankentryid => array of usage records (moduleid, section, stage, slot)
     */
    public static function get_exam_used_entries(int $satid): array {
        global $DB;

        $sql = "SELECT qr.id AS refid,
                       qr.questionbankentryid,
                       s.id AS slotid,
                       s.moduleid,
                       s.slot,
                       m.section,
                       m.stage
                  FROM {sat_slots} s
                  JOIN {sat_modules} m
                    ON m.id = s.moduleid
                  JOIN {question_references} qr
                    ON qr.itemid = s.id
                   AND qr.component = 'mod_sat'
                   AND qr.questionarea = 'slot'
                 WHERE s.satid = :satid
              ORDER BY m.sortorder ASC, s.slot ASC";

        $rows = $DB->get_records_sql($sql, ['satid' => $satid]);
        $used = [];
        foreach ($rows as $row) {
            $entryid = (int)$row->questionbankentryid;
            $used[$entryid][] = (object)[
                'moduleid' => (int)$row->moduleid,
                'section' => (string)$row->section,
                'stage' => (string)$row->stage,
                'slot' => (int)$row->slot,
            ];
        }

        return $used;
    }

    /**
     * Resolves the question type (qtype) for a list of question bank entry IDs.
     *
     * @param int[] $qentryids Array of question_bank_entries IDs
     * @return array Map of qbankentryid => qtype string
     */
    public static function get_qtypes_for_entries(array $qentryids): array {
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $qentryids))));
        if (empty($ids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $sql = "SELECT qbe.id AS qbankentryid,
                       q.qtype
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = 'ready'
                   AND qv.version = (
                       SELECT MAX(qv2.version)
                         FROM {question_versions} qv2
                        WHERE qv2.questionbankentryid = qbe.id
                          AND qv2.status = 'ready'
                   )
                  JOIN {question} q
                    ON q.id = qv.questionid
                 WHERE qbe.id $insql";

        return $DB->get_records_sql_menu($sql, $params);
    }

    /**
     * Retrieves available ready questions from accessible question bank contexts.
     *
     * @param int $contextid Module context ID
     * @return array List of question records keyed by qbankentryid
     */
    public static function get_available_bank_questions(int $contextid): array {
        global $DB;

        $ctx = context::instance_by_id($contextid);
        $contextids = $ctx->get_parent_context_ids(true);

        // Include Moodle 5.2 mod_qbank module subcontexts within the same course.
        $coursectx = $ctx->get_course_context(false);
        if ($coursectx) {
            $childids = $DB->get_fieldset_select(
                'context',
                'id',
                'contextlevel = :level AND ' . $DB->sql_like('path', ':path'),
                ['level' => CONTEXT_MODULE, 'path' => $DB->sql_like_escape($coursectx->path) . '/%']
            );
            if (!empty($childids)) {
                $contextids = array_unique(array_merge($contextids, array_map('intval', $childids)));
            }
        }

        if (empty($contextids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);

        $sql = "SELECT qbe.id AS qbankentryid,
                       q.id AS questionid,
                       q.name,
                       q.qtype,
                       qc.name AS categoryname
                  FROM {question_bank_entries} qbe
                  JOIN {question_categories} qc
                    ON qc.id = qbe.questioncategoryid
                  JOIN {question_versions} qv
                    ON qv.questionbankentryid = qbe.id
                   AND qv.status = 'ready'
                   AND qv.version = (
                       SELECT MAX(qv2.version)
                         FROM {question_versions} qv2
                        WHERE qv2.questionbankentryid = qbe.id
                          AND qv2.status = 'ready'
                   )
                  JOIN {question} q
                    ON q.id = qv.questionid
                 WHERE qc.contextid $insql
                   AND q.parent = 0
              ORDER BY qc.name ASC, q.name ASC";

        $records = $DB->get_records_sql($sql, $params);
        uasort($records, static function ($a, $b) {
            $cmp = strnatcasecmp((string)$a->name, (string)$b->name);
            if ($cmp !== 0) {
                return $cmp;
            }
            return (int)$a->qbankentryid <=> (int)$b->qbankentryid;
        });

        return $records;
    }

    /**
     * Moves a slot up or down within its module.
     *
     * @param int $slotid Slot ID
     * @param string $direction 'up' or 'down'
     * @return bool True if swapped
     */
    public static function move_slot(int $slotid, string $direction): bool {
        global $DB;

        $slot = $DB->get_record('sat_slots', ['id' => $slotid]);
        if (!$slot) {
            return false;
        }

        $slots = array_values($DB->get_records('sat_slots', ['moduleid' => $slot->moduleid], 'slot ASC'));
        $targetidx = null;
        foreach ($slots as $idx => $s) {
            if ((int)$s->id === $slotid) {
                $targetidx = $idx;
                break;
            }
        }

        if ($targetidx === null) {
            return false;
        }

        $swapidx = ($direction === 'up') ? ($targetidx - 1) : ($targetidx + 1);
        if (!isset($slots[$swapidx])) {
            return false;
        }

        $current = $slots[$targetidx];
        $neighbor = $slots[$swapidx];
        $tempslot = $current->slot;
        $current->slot = $neighbor->slot;
        $neighbor->slot = $tempslot;

        $DB->update_record('sat_slots', $current);
        $DB->update_record('sat_slots', $neighbor);

        return true;
    }

    /**
     * Toggles whether a slot counts toward the score or is an unscored pretest item.
     *
     * @param int $slotid Slot ID
     * @return bool True on success
     */
    public static function toggle_slot_scored(int $slotid): bool {
        global $DB;

        $slot = $DB->get_record('sat_slots', ['id' => $slotid]);
        if (!$slot) {
            return false;
        }

        $slot->is_scored = $slot->is_scored ? 0 : 1;
        $DB->update_record('sat_slots', $slot);

        return true;
    }

    /**
     * Splits question text on the first <hr> delimiter into left stimulus and right question prompt.
     *
     * @param string $questiontext Raw question HTML
     * @return array [string $stimulus, string $prompt]
     */
    public static function split_stimulus(string $questiontext): array {
        // Standard split on <hr> or <hr/> or <hr class="...">.
        $parts = preg_split('/<hr\s*\/?>/i', $questiontext, 2);

        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }

        // If no explicit delimiter, entire text is the stimulus/prompt.
        return ['', trim($questiontext)];
    }

    /**
     * Rewrites @@PLUGINFILE@@ URLs for question content rendered inside an SAT attempt.
     *
     * @param string $text Raw HTML containing optional @@PLUGINFILE@@ placeholders
     * @param int $questioncontextid Context ID of the question category owning the file
     * @param string $filearea Question file area ('questiontext', 'answer', or 'generalfeedback')
     * @param int $qubaid Question usage ID stored on sat_attempts.uniqueid
     * @param int $usageslot Usage slot number inside the question usage
     * @param int $itemid Question ID or answer ID owning the file area itemid
     * @return string Rewritten HTML with pluginfile.php URLs
     */
    public static function rewrite_question_urls(
        string $text,
        int $questioncontextid,
        string $filearea,
        int $qubaid,
        int $usageslot,
        int $itemid
    ): string {
        if ($text === '' || !str_contains($text, '@@PLUGINFILE@@') || $questioncontextid <= 0 || $qubaid <= 0) {
            return $text;
        }

        return file_rewrite_pluginfile_urls(
            $text,
            'pluginfile.php',
            $questioncontextid,
            'question',
            $filearea,
            "{$qubaid}/{$usageslot}/{$itemid}"
        );
    }
}
