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
 * Defines the activity configuration form for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/lib.php');

/**
 * Module instance configuration form.
 */
class mod_sat_mod_form extends moodleform_mod {
    /**
     * Defines the form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $config = get_config('mod_sat');
        $defaultscope = !empty($config->default_scope) ? $config->default_scope : 'full';
        $defaultpacing = !empty($config->default_pacing) ? $config->default_pacing : 'continuous';
        $defaultrw = (isset($config->default_threshold_rw) && $config->default_threshold_rw !== '')
            ? (int)$config->default_threshold_rw
            : 60;
        $defaultmath = (isset($config->default_threshold_math) && $config->default_threshold_math !== '')
            ? (int)$config->default_threshold_math
            : 60;
        $defaultgrade = ($defaultscope === 'full') ? 1600 : 800;

        // General settings.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('satname', 'mod_sat'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Exam Configuration.
        $mform->addElement('header', 'examconfigheader', get_string('examconfig', 'mod_sat'));

        $scopes = [
            'full' => get_string('scope_full', 'mod_sat'),
            'math_only' => get_string('scope_math_only', 'mod_sat'),
            'rw_only' => get_string('scope_rw_only', 'mod_sat'),
            'single_module' => get_string('scope_single_module', 'mod_sat'),
        ];
        $mform->addElement('select', 'exam_scope', get_string('exam_scope', 'mod_sat'), $scopes);
        $mform->setDefault('exam_scope', $defaultscope);
        $mform->addHelpButton('exam_scope', 'exam_scope', 'mod_sat');

        $pacings = [
            'continuous' => get_string('pacing_continuous', 'mod_sat'),
            'paced' => get_string('pacing_paced', 'mod_sat'),
        ];
        $mform->addElement('select', 'pacing_mode', get_string('pacing_mode', 'mod_sat'), $pacings);
        $mform->setDefault('pacing_mode', $defaultpacing);
        $mform->addHelpButton('pacing_mode', 'pacing_mode', 'mod_sat');

        $routings = [
            'adaptive' => get_string('routing_adaptive', 'mod_sat'),
            'fixed' => get_string('routing_fixed', 'mod_sat'),
        ];
        $mform->addElement('select', 'routing_mode', get_string('routing_mode', 'mod_sat'), $routings);
        $mform->setDefault('routing_mode', 'adaptive');
        $mform->addHelpButton('routing_mode', 'routing_mode', 'mod_sat');

        $mform->addElement('text', 'threshold_rw', get_string('threshold_rw', 'mod_sat'), ['size' => '4']);
        $mform->setType('threshold_rw', PARAM_INT);
        $mform->setDefault('threshold_rw', $defaultrw);
        $mform->addHelpButton('threshold_rw', 'threshold_rw', 'mod_sat');

        $mform->addElement('text', 'threshold_math', get_string('threshold_math', 'mod_sat'), ['size' => '4']);
        $mform->setType('threshold_math', PARAM_INT);
        $mform->setDefault('threshold_math', $defaultmath);
        $mform->addHelpButton('threshold_math', 'threshold_math', 'mod_sat');

        // Timing.
        $mform->addElement('header', 'timingheader', get_string('timing', 'mod_sat'));

        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_sat'), ['optional' => true]);
        $mform->addHelpButton('timeopen', 'timeopen', 'mod_sat');

        $mform->addElement('date_time_selector', 'timeclose', get_string('timeclose', 'mod_sat'), ['optional' => true]);
        $mform->addHelpButton('timeclose', 'timeclose', 'mod_sat');

        $mform->addElement('duration', 'breakduration', get_string('breakduration', 'mod_sat'), ['defaultunit' => 60]);
        $mform->setDefault('breakduration', 600);
        $mform->addHelpButton('breakduration', 'breakduration', 'mod_sat');

        $mform->addElement('duration', 'graceperiod', get_string('graceperiod', 'mod_sat'), ['defaultunit' => 60]);
        $mform->setDefault('graceperiod', 120);
        $mform->addHelpButton('graceperiod', 'graceperiod', 'mod_sat');

        // Attempts & Grading.
        $mform->addElement('header', 'gradeheader', get_string('grade', 'mod_sat'));

        $attemptoptions = [0 => get_string('unlimited')];
        for ($i = 1; $i <= 10; $i++) {
            $attemptoptions[$i] = $i;
        }
        $mform->addElement('select', 'attempts', get_string('attemptsallowed', 'mod_sat'), $attemptoptions);
        $mform->setDefault('attempts', 0);

        $grademethods = [
            SAT_GRADEHIGHEST => get_string('gradehighest', 'mod_sat'),
            SAT_GRADEAVERAGE => get_string('gradeaverage', 'mod_sat'),
            SAT_ATTEMPTFIRST => get_string('attemptfirst', 'mod_sat'),
            SAT_ATTEMPTLAST  => get_string('attemptlast', 'mod_sat'),
        ];
        $mform->addElement('select', 'grademethod', get_string('grademethod', 'mod_sat'), $grademethods);
        $mform->setDefault('grademethod', SAT_GRADEHIGHEST);

        $mform->addElement('text', 'grade', get_string('maxgrade', 'mod_sat'), ['size' => '6']);
        $mform->setType('grade', PARAM_INT);
        $mform->setDefault('grade', $defaultgrade);
        $mform->addHelpButton('grade', 'maxgrade', 'mod_sat');

        $reviewopts = [
            SAT_REVIEW_IMMEDIATE => get_string('review_immediate', 'mod_sat'),
            SAT_REVIEW_AFTERCLOSE => get_string('review_afterclose', 'mod_sat'),
            SAT_REVIEW_SCORESONLY => get_string('review_scoresonly', 'mod_sat'),
        ];
        $mform->addElement('select', 'reviewoptions', get_string('reviewoptions', 'mod_sat'), $reviewopts);
        $mform->setDefault('reviewoptions', SAT_REVIEW_IMMEDIATE);
        $mform->addHelpButton('reviewoptions', 'reviewoptions', 'mod_sat');

        // Standard coursemodule elements.
        $this->standard_coursemodule_elements();

        // Action buttons (Save and return, Save and display, Cancel).
        $this->add_action_buttons();
    }

    /**
     * Validates activity configuration inputs.
     *
     * @param array $data Submitted form data
     * @param array $files Uploaded files
     * @return array Validation errors keyed by field name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (isset($data['threshold_rw']) && ($data['threshold_rw'] < 0 || $data['threshold_rw'] > 100)) {
            $errors['threshold_rw'] = get_string('error_threshold_range', 'mod_sat');
        }
        if (isset($data['threshold_math']) && ($data['threshold_math'] < 0 || $data['threshold_math'] > 100)) {
            $errors['threshold_math'] = get_string('error_threshold_range', 'mod_sat');
        }
        if (!empty($data['timeopen']) && !empty($data['timeclose']) && $data['timeclose'] <= $data['timeopen']) {
            $errors['timeclose'] = get_string('error_timeclose', 'mod_sat');
        }
        if (isset($data['grade']) && (int)$data['grade'] < 0) {
            $errors['grade'] = get_string('err_numeric', 'form');
        }

        return $errors;
    }
}
