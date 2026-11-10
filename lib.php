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

/**
 * Standard library callbacks for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/** Grading method constants */
define('SAT_GRADEHIGHEST', 1);
define('SAT_GRADEAVERAGE', 2);
define('SAT_ATTEMPTFIRST', 3);
define('SAT_ATTEMPTLAST', 4);

/**
 * Check if the module supports a specific feature.
 *
 * @param string $feature FEATURE_xx constant for that feature
 * @return mixed true if the feature is supported, null if unknown
 */
function sat_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GRADE_OUTCOMES:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_USES_QUESTIONS:
            return true;
        default:
            return null;
    }
}

/**
 * Add a new sat instance.
 *
 * @param stdClass $data submitted by user from mod_form.php
 * @param mod_sat_mod_form|null $mform
 * @return int int id of the new instance
 */
function sat_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    // Default max grade based on scope if not explicitly overridden.
    if (empty($data->grade)) {
        $data->grade = ($data->exam_scope === 'full') ? 1600 : 800;
    }

    $id = $DB->insert_record('sat', $data);
    $data->id = $id;

    // Automatically provision default modules for this exam instance.
    sat_provision_default_modules($data);

    // Register with Gradebook.
    sat_grade_item_update($data);

    return $id;
}

/**
 * Update an existing sat instance.
 *
 * @param stdClass $data submitted by user from mod_form.php
 * @param mod_sat_mod_form|null $mform
 * @return bool true on success
 */
function sat_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();

    $DB->update_record('sat', $data);

    // Update Gradebook settings.
    sat_grade_item_update($data);

    return true;
}

/**
 * Delete a sat instance and all associated records.
 *
 * @param int $id id of the sat record
 * @return bool true on success
 */
function sat_delete_instance($id) {
    global $DB;

    if (!$sat = $DB->get_record('sat', ['id' => $id])) {
        return false;
    }

    // Delete student responses and attempts.
    $attempts = $DB->get_records('sat_attempts', ['satid' => $id], '', 'id, uniqueid');
    foreach ($attempts as $attempt) {
        $DB->delete_records('sat_slot_state', ['attemptid' => $attempt->id]);
        $DB->delete_records('sat_module_attempts', ['attemptid' => $attempt->id]);

        // Delete associated question usage if core question engine loaded.
        if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
            question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
        }
    }

    // Delete question references for slots in this activity.
    $slots = $DB->get_records('sat_slots', ['satid' => $id], '', 'id');
    if (!empty($slots)) {
        [$insql, $params] = $DB->get_in_or_equal(array_keys($slots), SQL_PARAMS_NAMED);
        $params['component'] = 'mod_sat';
        $params['questionarea'] = 'slot';
        $DB->delete_records_select(
            'question_references',
            "component = :component AND questionarea = :questionarea AND itemid $insql",
            $params
        );
    }

    $DB->delete_records('sat_attempts', ['satid' => $id]);
    $DB->delete_records('sat_slots', ['satid' => $id]);
    $DB->delete_records('sat_modules', ['satid' => $id]);
    $DB->delete_records('sat_overrides', ['satid' => $id]);
    $DB->delete_records('sat_grades', ['satid' => $id]);
    $DB->delete_records('sat_score_tables', ['satid' => $id]);
    $DB->delete_records('sat', ['id' => $id]);

    // Delete Gradebook item.
    sat_grade_item_delete($sat);

    return true;
}

/**
 * Automatically creates default modules for an SAT exam instance based on exam scope.
 *
 * @param stdClass $sat
 * @return void
 */
function sat_provision_default_modules($sat) {
    global $DB;

    $modules = [];
    $sortorder = 1;

    if ($sat->exam_scope === 'full' || $sat->exam_scope === 'rw_only') {
        // Reading & Writing Module 1.
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'rw',
            'stage' => 'm1',
            'timelimit' => 1920, // 32 minutes.
            'questioncount' => 27,
            'sortorder' => $sortorder++,
        ];
        // Reading & Writing Module 2 (Easy).
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'rw',
            'stage' => 'm2_easy',
            'timelimit' => 1920,
            'questioncount' => 27,
            'sortorder' => $sortorder++,
        ];
        // Reading & Writing Module 2 (Hard).
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'rw',
            'stage' => 'm2_hard',
            'timelimit' => 1920,
            'questioncount' => 27,
            'sortorder' => $sortorder++,
        ];
    }

    if ($sat->exam_scope === 'full' || $sat->exam_scope === 'math_only') {
        // Math Module 1.
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'math',
            'stage' => 'm1',
            'timelimit' => 2100, // 35 minutes.
            'questioncount' => 22,
            'sortorder' => $sortorder++,
        ];
        // Math Module 2 (Easy).
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'math',
            'stage' => 'm2_easy',
            'timelimit' => 2100,
            'questioncount' => 22,
            'sortorder' => $sortorder++,
        ];
        // Math Module 2 (Hard).
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'math',
            'stage' => 'm2_hard',
            'timelimit' => 2100,
            'questioncount' => 22,
            'sortorder' => $sortorder++,
        ];
    }

    if ($sat->exam_scope === 'single_module') {
        // Single Module drill.
        $modules[] = (object) [
            'satid' => $sat->id,
            'section' => 'math',
            'stage' => 'm1',
            'timelimit' => 2100,
            'questioncount' => 22,
            'sortorder' => $sortorder++,
        ];
    }

    foreach ($modules as $mod) {
        $DB->insert_record('sat_modules', $mod);
    }
}

/**
 * Create or update Gradebook grade item for given sat instance.
 *
 * @param stdClass $sat
 * @param mixed $grades optional grade values
 * @return int GRADE_UPDATE_OK or error code
 */
function sat_grade_item_update($sat, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = [
        'itemname' => $sat->name,
        'idnumber' => $sat->cmidnumber ?? '',
    ];

    if ($sat->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $sat->grade;
        $params['grademin']  = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/sat', $sat->course, 'mod', 'sat', $sat->id, 0, $grades, $params);
}

/**
 * Delete Gradebook grade item for given sat instance.
 *
 * @param stdClass $sat
 * @return int GRADE_UPDATE_OK or error code
 */
function sat_grade_item_delete($sat) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/sat', $sat->course, 'mod', 'sat', $sat->id, 0, null, ['deleted' => 1]);
}

/**
 * Serves question files for questions used in mod_sat.
 *
 * @param stdClass $course course object
 * @param context $context context object
 * @param string $component component name
 * @param string $filearea file area
 * @param int $qubaid question usage id
 * @param int $slot question slot
 * @param array $args extra arguments
 * @param bool $forcedownload whether to force download
 * @param array $options additional options
 * @return bool false if file not found
 */
function mod_sat_question_pluginfile($course, $context, $component, $filearea, $qubaid, $slot, $args, $forcedownload, array $options = []) {
    global $DB;

    // Check that user is logged in.
    require_login($course, false);

    if (!question_engine::is_qubaid_condition($qubaid)) {
        return false;
    }

    return question_send_question_file($context, $component, $filearea, $qubaid, $slot, $args, $forcedownload, $options);
}
