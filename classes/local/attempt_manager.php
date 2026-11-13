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

use context_module;
use question_bank;
use question_engine;
use stdClass;

/**
 * Manages the full lifecycle of student attempts in mod_sat.
 *
 * Utilizes Moodle core question_usage_by_activity for answers,
 * enforcing server-authoritative deadlines and adaptive routing.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_manager {
    /** Attempt state: currently in progress. */
    public const STATE_INPROGRESS = 'inprogress';

    /** Attempt state: paused between modules in paced mode. */
    public const STATE_PAUSED = 'paused';

    /** Attempt state: completed and final grade recorded. */
    public const STATE_FINISHED = 'finished';

    /**
     * Resolves accommodation time multiplier (e.g. 1.5x, 2.0x) for a user.
     *
     * @param int $satid SAT instance ID
     * @param int $userid User ID
     * @return float Time multiplier (default 1.0)
     */
    public static function get_time_multiplier(int $satid, int $userid): float {
        global $DB;

        // Check user-specific override.
        $override = $DB->get_record('sat_overrides', ['satid' => $satid, 'userid' => $userid]);
        if ($override && !empty($override->timemultiplier)) {
            return (float)$override->timemultiplier;
        }

        return 1.0;
    }

    /**
     * Starts a new exam attempt for a student.
     *
     * @param stdClass $sat Activity record
     * @param int $userid Student user ID
     * @param stdClass $cm Course module record
     * @param bool $preview Whether this is a teacher preview
     * @return array [stdClass $attempt, stdClass $moduleattempt]
     */
    public static function start_new_attempt(stdClass $sat, int $userid, stdClass $cm, bool $preview = false): array {
        global $DB;

        $context = context_module::instance($cm->id);
        $multiplier = self::get_time_multiplier($sat->id, $userid);

        // Determine next attempt number.
        $maxattempt = $DB->get_field('sat_attempts', 'MAX(attempt)', [
            'satid' => $sat->id,
            'userid' => $userid,
        ]);
        $attemptnumber = $maxattempt ? ((int)$maxattempt + 1) : 1;

        // Create Moodle Question Usage.
        $quba = question_engine::make_questions_usage_by_activity('mod_sat', $context);
        $quba->set_preferred_behaviour('deferredfeedback');

        // Resolve first module (RW M1 or Math M1).
        $firstsection = ($sat->exam_scope === 'math_only') ? 'math' : 'rw';
        $module = $DB->get_record('sat_modules', [
            'satid' => $sat->id,
            'section' => $firstsection,
            'stage' => 'm1',
        ]);

        if (!$module) {
            // Fallback to first available module.
            $allmodules = structure_manager::get_modules($sat->id);
            $module = reset($allmodules);
        }

        // Add slots to question usage.
        $slots = structure_manager::get_slots($module->id);
        $firstslotnumber = 1;
        $lastslotnumber = 1;
        $isfirst = true;

        if (!empty($slots)) {
            foreach ($slots as $s) {
                if (!empty($s->questionid)) {
                    $question = question_bank::load_question($s->questionid);
                    $qubaslot = $quba->add_question($question, (float)$s->maxmark);
                    $quba->start_question($qubaslot);
                    if ($isfirst) {
                        $firstslotnumber = $qubaslot;
                        $isfirst = false;
                    }
                    $lastslotnumber = $qubaslot;
                }
            }
        }

        question_engine::save_questions_usage_by_activity($quba);

        // Create overall attempt record.
        $now = time();
        $attempt = new stdClass();
        $attempt->satid = $sat->id;
        $attempt->userid = $userid;
        $attempt->attempt = $attemptnumber;
        $attempt->uniqueid = $quba->get_id();
        $attempt->state = self::STATE_INPROGRESS;
        $attempt->currentstage = $module->section . '_' . $module->stage;
        $attempt->route_rw = 'none';
        $attempt->route_math = 'none';
        $attempt->preview = $preview ? 1 : 0;
        $attempt->timestart = $now;
        $attempt->timefinish = 0;
        $attempt->timemodified = $now;

        $attempt->id = $DB->insert_record('sat_attempts', $attempt);

        // Create module attempt record with server-authoritative deadline.
        $timelimit = (int) round($module->timelimit * $multiplier);
        $modattempt = new stdClass();
        $modattempt->attemptid = $attempt->id;
        $modattempt->moduleid = $module->id;
        $modattempt->state = self::STATE_INPROGRESS;
        $modattempt->firstslot = $firstslotnumber;
        $modattempt->lastslot = $lastslotnumber;
        $modattempt->timestart = $now;
        $modattempt->timedeadline = $now + $timelimit;
        $modattempt->timefinish = 0;
        $modattempt->rawscore = 0.0;
        $modattempt->maxscore = (float)count($slots);

        $modattempt->id = $DB->insert_record('sat_module_attempts', $modattempt);

        return [$attempt, $modattempt];
    }

    /**
     * Retrieves the active module attempt, or resumes a paused module attempt in Paced Mode.
     *
     * @param stdClass $sat SAT instance
     * @param stdClass $attempt Active attempt record (passed by reference if updated)
     * @return stdClass|null Active or resumed module attempt
     */
    public static function get_or_resume_active_module(stdClass $sat, stdClass $attempt): ?stdClass {
        global $DB;

        $modattempt = $DB->get_record_select(
            'sat_module_attempts',
            'attemptid = :attemptid AND state = :inprogress',
            ['attemptid' => $attempt->id, 'inprogress' => self::STATE_INPROGRESS],
            '*',
            IGNORE_MULTIPLE
        );

        if ($modattempt) {
            return $modattempt;
        }

        // Check for a paused module waiting to be resumed in Paced Mode.
        $pausedmod = $DB->get_record_select(
            'sat_module_attempts',
            'attemptid = :attemptid AND state = :paused',
            ['attemptid' => $attempt->id, 'paused' => self::STATE_PAUSED],
            '*',
            IGNORE_MULTIPLE
        );

        if ($pausedmod) {
            $module = $DB->get_record('sat_modules', ['id' => $pausedmod->moduleid]);
            $override = $DB->get_record('sat_overrides', ['satid' => $sat->id, 'userid' => $attempt->userid]);
            $multiplier = ($override && $override->timemultiplier > 0) ? (float)$override->timemultiplier : 1.0;
            $baselimit = $module ? (int)$module->timelimit : 1920;
            $timelimit = (int) round($baselimit * $multiplier);

            $now = time();
            $pausedmod->state = self::STATE_INPROGRESS;
            $pausedmod->timestart = $now;
            $pausedmod->timedeadline = $now + $timelimit;
            $DB->update_record('sat_module_attempts', $pausedmod);

            if ($attempt->state === self::STATE_PAUSED) {
                $attempt->state = self::STATE_INPROGRESS;
                $attempt->timemodified = $now;
                $DB->update_record('sat_attempts', $attempt);
            }

            return $pausedmod;
        }

        // Fallback to latest module attempt.
        $records = $DB->get_records('sat_module_attempts', ['attemptid' => $attempt->id], 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Grades a student response for a slot against Moodle question_answers and SAT SPR rules.
     *
     * @param stdClass $slot Slot object with questionid, qtype, is_spr, and maxmark
     * @param string $studentresponse Student response string
     * @return float Earned mark
     */
    public static function grade_slot_response(stdClass $slot, string $studentresponse): float {
        global $DB;

        $trimmed = trim($studentresponse);
        if ($trimmed === '' || empty($slot->questionid)) {
            return 0.0;
        }

        $answers = array_values($DB->get_records(
            'question_answers',
            ['question' => $slot->questionid],
            'id ASC'
        ));

        if (empty($answers)) {
            return 0.0;
        }

        $maxmark = (float)($slot->maxmark ?? 1.0);
        $qtype = $slot->qtype ?? '';
        $isspr = !empty($slot->is_spr) || in_array($qtype, ['shortanswer', 'numerical'], true);

        if ($isspr) {
            foreach ($answers as $ans) {
                if ((float)$ans->fraction > 0 && spr_helper::is_equivalent($trimmed, (string)$ans->answer)) {
                    return $maxmark * (float)$ans->fraction;
                }
            }
            return 0.0;
        }

        // Multiple choice: map letter keys A..F to ordered answers.
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        $upper = strtoupper($trimmed);
        $letteridx = array_search($upper, $letters, true);

        if ($letteridx !== false && isset($answers[$letteridx])) {
            $chosen = $answers[$letteridx];
            return ((float)$chosen->fraction > 0) ? ($maxmark * (float)$chosen->fraction) : 0.0;
        }

        // Fallback match by answer ID or stripped answer text.
        foreach ($answers as $ans) {
            if ((string)$ans->id === $trimmed || trim(strip_tags((string)$ans->answer)) === $trimmed) {
                return ((float)$ans->fraction > 0) ? ($maxmark * (float)$ans->fraction) : 0.0;
            }
        }

        return 0.0;
    }

    /**
     * Submits an active module attempt and handles adaptive routing or transitions.
     *
     * @param int $attemptid Overall attempt ID
     * @param int $modattemptid Module attempt ID
     * @return array [stdClass $attempt, string $nextaction]
     */
    public static function submit_module(int $attemptid, int $modattemptid): array {
        global $DB;

        $attempt = $DB->get_record('sat_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        $modattempt = $DB->get_record('sat_module_attempts', ['id' => $modattemptid], '*', MUST_EXIST);
        $module = $DB->get_record('sat_modules', ['id' => $modattempt->moduleid], '*', MUST_EXIST);
        $sat = $DB->get_record('sat', ['id' => $attempt->satid], '*', MUST_EXIST);

        $now = time();
        $modattempt->state = 'finished';
        $modattempt->timefinish = $now;

        // Process question usage scoring for slots in this module.
        $quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid);
        $quba->finish_all_questions($now);
        question_engine::save_questions_usage_by_activity($quba);

        // Load persisted student responses keyed by global usage slot.
        $slotstates = $DB->get_records('sat_slot_state', ['attemptid' => $attempt->id], '', 'slot, id, response');

        // Calculate raw points for this module.
        $slots = structure_manager::get_slots($module->id);
        $rawearned = 0.0;
        $maxearned = 0.0;

        $usageslot = ((int)$modattempt->firstslot > 0) ? (int)$modattempt->firstslot : 1;
        foreach ($slots as $s) {
            $state = $slotstates[$usageslot] ?? null;
            $studentresponse = $state ? (string)($state->response ?? '') : '';

            if ($s->is_scored) {
                $maxearned += (float)$s->maxmark;
                $rawearned += self::grade_slot_response($s, $studentresponse);
            }
            if (!empty($s->questionid)) {
                $usageslot++;
            }
        }

        $modattempt->rawscore = $rawearned;
        $modattempt->maxscore = $maxearned;
        $DB->update_record('sat_module_attempts', $modattempt);

        // Handle Adaptive Routing if this was Module 1.
        if ($module->stage === 'm1') {
            $threshold = ($module->section === 'rw') ? $sat->threshold_rw : $sat->threshold_math;
            $route = ($sat->routing_mode === 'fixed')
                ? mst_router::ROUTE_FIXED
                : mst_router::determine_route($rawearned, $maxearned, $threshold);

            if ($module->section === 'rw') {
                $attempt->route_rw = $route;
                $attempt->raw_rw = (int)round($rawearned);
            } else {
                $attempt->route_math = $route;
                $attempt->raw_math = (int)round($rawearned);
            }
        }

        // Decide next transition based on exam scope and pacing.
        $nextaction = 'continue';

        if ($sat->pacing_mode === 'paced') {
            $attempt->state = self::STATE_PAUSED;
            $nextaction = 'pause';
        }

        // Check if final module of exam.
        $isfinal = self::is_final_module($sat, $module);
        if ($isfinal) {
            $attempt->state = self::STATE_FINISHED;
            $attempt->timefinish = $now;
            $nextaction = 'finish';
            self::finalize_attempt_scoring($sat, $attempt);
        } else {
            // Provision next module attempt.
            $nextsection = $module->section;
            if ($module->stage === 'm1') {
                $route = ($module->section === 'rw') ? $attempt->route_rw : $attempt->route_math;
                $nextstage = 'm2_' . $route;
            } else {
                // Was Module 2 of RW in a full exam -> transition to Math Module 1.
                $nextsection = 'math';
                $nextstage = 'm1';
            }

            $nextmodule = $DB->get_record('sat_modules', [
                'satid' => $sat->id,
                'section' => $nextsection,
                'stage' => $nextstage,
            ]);

            if ($nextmodule) {
                // Add next module's questions into question usage.
                $nextslots = structure_manager::get_slots($nextmodule->id);
                $firstslotnum = 1;
                $lastslotnum = 1;

                if (!empty($nextslots)) {
                    $isfirstslot = true;
                    foreach ($nextslots as $ns) {
                        if (!empty($ns->questionid)) {
                            $loadedq = question_bank::load_question($ns->questionid);
                            $qslot = $quba->add_question($loadedq, (float)$ns->maxmark);
                            $quba->start_question($qslot);
                            if ($isfirstslot) {
                                $firstslotnum = $qslot;
                                $isfirstslot = false;
                            }
                            $lastslotnum = $qslot;
                        }
                    }
                    question_engine::save_questions_usage_by_activity($quba);
                }

                // Calculate accommodation multiplier for next module.
                $override = $DB->get_record('sat_overrides', ['satid' => $sat->id, 'userid' => $attempt->userid]);
                $multiplier = $override ? (float)$override->timemultiplier : 1.0;
                $timelimit = (int) round($nextmodule->timelimit * $multiplier);

                $nextmodattempt = new stdClass();
                $nextmodattempt->attemptid = $attempt->id;
                $nextmodattempt->moduleid = $nextmodule->id;
                $nextmodattempt->state = ($sat->pacing_mode === 'paced') ? 'paused' : 'inprogress';
                $nextmodattempt->firstslot = $firstslotnum;
                $nextmodattempt->lastslot = $lastslotnum;
                $nextmodattempt->timestart = $now;
                $nextmodattempt->timedeadline = $now + $timelimit;
                $nextmodattempt->timefinish = 0;
                $nextmodattempt->rawscore = 0.0;
                $nextmodattempt->maxscore = 0.0;
                $DB->insert_record('sat_module_attempts', $nextmodattempt);

                $attempt->currentstage = $nextsection . '_' . $nextstage;
            }
        }

        $attempt->timemodified = $now;
        $DB->update_record('sat_attempts', $attempt);

        return [$attempt, $nextaction];
    }

    /**
     * Determines whether a module is the final module in the configured exam scope.
     *
     * @param stdClass $sat SAT instance
     * @param stdClass $module Current module
     * @return bool True if this was the last module
     */
    protected static function is_final_module(stdClass $sat, stdClass $module): bool {
        if ($sat->exam_scope === 'single_module') {
            return true;
        }
        if ($sat->exam_scope === 'rw_only' && str_starts_with($module->stage, 'm2')) {
            return true;
        }
        if ($sat->exam_scope === 'math_only' && str_starts_with($module->stage, 'm2')) {
            return true;
        }
        if ($sat->exam_scope === 'full' && $module->section === 'math' && str_starts_with($module->stage, 'm2')) {
            return true;
        }
        return false;
    }

    /**
     * Computes final scaled scores and updates the Moodle Gradebook.
     *
     * @param stdClass $sat SAT activity record
     * @param stdClass $attempt Completed attempt record
     * @return void
     */
    protected static function finalize_attempt_scoring(stdClass $sat, stdClass $attempt): void {
        global $DB;

        $modattempts = $DB->get_records('sat_module_attempts', ['attemptid' => $attempt->id]);
        $totalrwearned = 0.0;
        $totalrwmax = 0.0;
        $totalmathearned = 0.0;
        $totalmathmax = 0.0;

        foreach ($modattempts as $ma) {
            $m = $DB->get_record('sat_modules', ['id' => $ma->moduleid]);
            if (!$m) {
                continue;
            }
            if ($m->section === 'rw') {
                $totalrwearned += (float)$ma->rawscore;
                $totalrwmax += (float)$ma->maxscore;
            } else {
                $totalmathearned += (float)$ma->rawscore;
                $totalmathmax += (float)$ma->maxscore;
            }
        }

        $scaledrw = null;
        if ($totalrwmax > 0.0) {
            $pctrw = ($totalrwearned / $totalrwmax) * 100.0;
            $scaledrw = scorer::calculate_section_scaled_score($sat->id, 'rw', $attempt->route_rw, $pctrw);
            $attempt->raw_rw = (int)round($totalrwearned);
            $attempt->scaled_rw = $scaledrw;
        }

        $scaledmath = null;
        if ($totalmathmax > 0.0) {
            $pctmath = ($totalmathearned / $totalmathmax) * 100.0;
            $scaledmath = scorer::calculate_section_scaled_score($sat->id, 'math', $attempt->route_math, $pctmath);
            $attempt->raw_math = (int)round($totalmathearned);
            $attempt->scaled_math = $scaledmath;
        }

        $totalcomposite = scorer::calculate_composite_score($scaledrw, $scaledmath, $sat->exam_scope);
        $attempt->scaled_total = $totalcomposite;

        // Push to Moodle Gradebook.
        scorer::update_final_grade($sat, $attempt->userid, $totalcomposite, $scaledrw, $scaledmath);
    }
}
