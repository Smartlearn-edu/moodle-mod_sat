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

namespace mod_sat\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;
use mod_sat\local\attempt_manager;
use mod_sat\local\structure_manager;
use moodle_exception;
use question_engine;
use stdClass;

/**
 * External Web Services API implementation for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_api extends external_api {
    /**
     * Parameters for get_attempt_state.
     *
     * @return external_function_parameters
     */
    public static function get_attempt_state_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'SAT attempt ID'),
        ]);
    }

    /**
     * Retrieves attempt state, active questions, and remaining time.
     *
     * @param int $attemptid Attempt ID
     * @return array
     */
    public static function get_attempt_state(int $attemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_attempt_state_parameters(), [
            'attemptid' => $attemptid,
        ]);

        $attempt = $DB->get_record('sat_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $sat = $DB->get_record('sat', ['id' => $attempt->satid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('sat', $sat->id, $sat->course, false, MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        // Security: Ensure user owns this attempt or is a teacher previewing.
        if ($attempt->userid != $USER->id && !has_capability('mod/sat:manage', $context)) {
            throw new moodle_exception('nopermissions', 'error');
        }

        // Fetch active or resume paused module attempt.
        $modattempt = attempt_manager::get_or_resume_active_module($sat, $attempt);
        $module = $DB->get_record('sat_modules', ['id' => $modattempt->moduleid], '*', MUST_EXIST);

        // Server-authoritative remaining time.
        $now = time();
        $timeremaining = max(0, $modattempt->timedeadline - $now);

        // Load questions in this module.
        $slots = structure_manager::get_slots($module->id);
        $formattedslots = [];
        $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
        $usageslot = ((int)$modattempt->firstslot > 0) ? (int)$modattempt->firstslot : 1;

        foreach ($slots as $s) {
            $rawtext = $s->questiontext ?? '';
            [$stimulus, $prompt] = structure_manager::split_stimulus($rawtext);

            $options = [];
            $isspr = (bool)$s->is_spr || in_array($s->qtype ?? '', ['shortanswer', 'numerical'], true);
            if (!$isspr && !empty($s->questionid)) {
                $answers = array_values($DB->get_records(
                    'question_answers',
                    ['question' => $s->questionid],
                    'id ASC'
                ));
                foreach ($answers as $aidx => $ans) {
                    if (!isset($letters[$aidx])) {
                        break;
                    }
                    $options[] = [
                        'key' => $letters[$aidx],
                        'text' => format_text($ans->answer, $ans->answerformat ?? FORMAT_HTML, ['context' => $context]),
                    ];
                }
            }

            $formattedslots[] = [
                'slot' => $usageslot,
                'maxmark' => (float)$s->maxmark,
                'is_scored' => (bool)$s->is_scored,
                'is_spr' => $isspr,
                'stimulus' => format_text($stimulus, FORMAT_HTML, ['context' => $context]),
                'prompt' => format_text($prompt, FORMAT_HTML, ['context' => $context]),
                'options' => $options,
            ];

            if (!empty($s->questionid)) {
                $usageslot++;
            }
        }

        // Load slot states (responses, flags, eliminations, annotations).
        $states = $DB->get_records('sat_slot_state', ['attemptid' => $attempt->id]);
        $formattedstates = [];
        foreach ($states as $st) {
            $formattedstates[] = [
                'slot' => (int)$st->slot,
                'response' => (string)($st->response ?? ''),
                'flagged' => !empty($st->flagged),
                'eliminated' => $st->eliminated ?? '[]',
                'annotations' => $st->annotations ?? '[]',
            ];
        }

        return [
            'attemptid' => (int)$attempt->id,
            'state' => (string)$attempt->state,
            'currentstage' => (string)$attempt->currentstage,
            'modattemptid' => (int)$modattempt->id,
            'section' => (string)$module->section,
            'stage' => (string)$module->stage,
            'timeremaining' => $timeremaining,
            'slots' => $formattedslots,
            'slot_states' => $formattedstates,
        ];
    }

    /**
     * Returns description for get_attempt_state.
     *
     * @return external_single_structure
     */
    public static function get_attempt_state_returns(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt ID'),
            'state' => new external_value(PARAM_ALPHA, 'Attempt state'),
            'currentstage' => new external_value(PARAM_ALPHANUMEXT, 'Current stage'),
            'modattemptid' => new external_value(PARAM_INT, 'Module attempt ID'),
            'section' => new external_value(PARAM_ALPHA, 'Section'),
            'stage' => new external_value(PARAM_ALPHANUMEXT, 'Stage'),
            'timeremaining' => new external_value(PARAM_INT, 'Time remaining in seconds'),
            'slots' => new external_multiple_structure(
                new external_single_structure([
                    'slot' => new external_value(PARAM_INT, 'Slot index'),
                    'maxmark' => new external_value(PARAM_FLOAT, 'Max mark'),
                    'is_scored' => new external_value(PARAM_BOOL, 'Is scored'),
                    'is_spr' => new external_value(PARAM_BOOL, 'Is SPR'),
                    'stimulus' => new external_value(PARAM_RAW, 'Stimulus HTML'),
                    'prompt' => new external_value(PARAM_RAW, 'Prompt HTML'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'key' => new external_value(PARAM_ALPHA, 'Option letter key'),
                            'text' => new external_value(PARAM_RAW, 'Option answer HTML'),
                        ]),
                        'MCQ answer choices',
                        VALUE_OPTIONAL
                    ),
                ])
            ),
            'slot_states' => new external_multiple_structure(
                new external_single_structure([
                    'slot' => new external_value(PARAM_INT, 'Slot index'),
                    'response' => new external_value(PARAM_RAW, 'Student answer response', VALUE_OPTIONAL),
                    'flagged' => new external_value(PARAM_BOOL, 'Marked for review flag', VALUE_OPTIONAL),
                    'eliminated' => new external_value(PARAM_RAW, 'Eliminated choices JSON'),
                    'annotations' => new external_value(PARAM_RAW, 'Annotations JSON'),
                ])
            ),
        ]);
    }

    /**
     * Parameters for save_slot_state.
     *
     * @return external_function_parameters
     */
    public static function save_slot_state_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'SAT attempt ID'),
            'slot' => new external_value(PARAM_INT, 'Slot number'),
            'eliminated' => new external_value(PARAM_RAW, 'Eliminated keys JSON', VALUE_DEFAULT, '[]'),
            'annotations' => new external_value(PARAM_RAW, 'Annotations JSON', VALUE_DEFAULT, '[]'),
            'response' => new external_value(PARAM_RAW, 'Student answer response', VALUE_DEFAULT, ''),
            'flagged' => new external_value(PARAM_INT, 'Marked for review flag (0 or 1)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Saves user's answer response, review flag, strike-throughs, and highlight annotations.
     *
     * @param int $attemptid Attempt ID
     * @param int $slot Slot number
     * @param string $eliminated JSON array of eliminated keys
     * @param string $annotations JSON array of annotations
     * @param string $response Student response
     * @param int $flagged Marked for review flag
     * @return array
     */
    public static function save_slot_state(
        int $attemptid,
        int $slot,
        string $eliminated = '[]',
        string $annotations = '[]',
        string $response = '',
        int $flagged = 0
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::save_slot_state_parameters(), [
            'attemptid' => $attemptid,
            'slot' => $slot,
            'eliminated' => $eliminated,
            'annotations' => $annotations,
            'response' => $response,
            'flagged' => $flagged,
        ]);

        $attempt = $DB->get_record('sat_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $sat = $DB->get_record('sat', ['id' => $attempt->satid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('sat', $sat->id, $sat->course, false, MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        if ($attempt->userid != $USER->id) {
            throw new moodle_exception('nopermissions', 'error');
        }

        $now = time();
        $record = $DB->get_record('sat_slot_state', [
            'attemptid' => $attempt->id,
            'slot' => $params['slot'],
        ]);

        if (!$record) {
            $record = new stdClass();
            $record->attemptid = $attempt->id;
            $record->slot = $params['slot'];
            $record->eliminated = $params['eliminated'];
            $record->annotations = $params['annotations'];
            $record->response = $params['response'];
            $record->flagged = $params['flagged'] ? 1 : 0;
            $record->timemodified = $now;
            $DB->insert_record('sat_slot_state', $record);
        } else {
            $record->eliminated = $params['eliminated'];
            $record->annotations = $params['annotations'];
            $record->response = $params['response'];
            $record->flagged = $params['flagged'] ? 1 : 0;
            $record->timemodified = $now;
            $DB->update_record('sat_slot_state', $record);
        }

        return ['status' => true];
    }

    /**
     * Returns description for save_slot_state.
     *
     * @return external_single_structure
     */
    public static function save_slot_state_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Save status'),
        ]);
    }

    /**
     * Parameters for submit_module.
     *
     * @return external_function_parameters
     */
    public static function submit_module_parameters(): external_function_parameters {
        return new external_function_parameters([
            'attemptid' => new external_value(PARAM_INT, 'SAT attempt ID'),
            'modattemptid' => new external_value(PARAM_INT, 'Module attempt ID'),
        ]);
    }

    /**
     * Submits a module attempt, triggering adaptive routing or conclusion.
     *
     * @param int $attemptid Attempt ID
     * @param int $modattemptid Module attempt ID
     * @return array
     */
    public static function submit_module(int $attemptid, int $modattemptid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::submit_module_parameters(), [
            'attemptid' => $attemptid,
            'modattemptid' => $modattemptid,
        ]);

        $attempt = $DB->get_record('sat_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $sat = $DB->get_record('sat', ['id' => $attempt->satid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('sat', $sat->id, $sat->course, false, MUST_EXIST);

        $context = context_module::instance($cm->id);
        self::validate_context($context);

        if ($attempt->userid != $USER->id) {
            throw new moodle_exception('nopermissions', 'error');
        }

        [$updatedattempt, $nextaction] = attempt_manager::submit_module($params['attemptid'], $params['modattemptid']);

        return [
            'status' => true,
            'nextaction' => $nextaction,
        ];
    }

    /**
     * Returns description for submit_module.
     *
     * @return external_single_structure
     */
    public static function submit_module_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Submission status'),
            'nextaction' => new external_value(PARAM_ALPHA, 'Next action: continue, pause, or finish'),
        ]);
    }
}
