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
 * English language strings for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action'] = 'Action';
$string['addquestionfrombank'] = 'Add question slot';
$string['addslot'] = 'Add slot';
$string['attempt'] = 'Attempt';
$string['attemptfirst'] = 'First attempt';
$string['attemptlast'] = 'Last attempt';
$string['attemptsallowed'] = 'Attempts allowed';
$string['breakduration'] = 'Break duration';
$string['breakduration_help'] = 'Duration of the scheduled pause between Reading & Writing and Math in full exam mode (default 10 minutes).';
$string['confirmdeleteslot'] = 'Are you sure you want to remove this question slot?';
$string['continueattempt'] = 'Continue exam';
$string['default_pacing'] = 'Default pacing mode';
$string['default_pacing_desc'] = 'Default session pacing setting for new SAT exam activities.';
$string['default_scope'] = 'Default exam scope';
$string['default_scope_desc'] = 'Default section scope for new SAT exam activities.';
$string['default_threshold_math'] = 'Default Math routing threshold';
$string['default_threshold_math_desc'] = 'Default percentage score in Math Module 1 required to route to Hard Module 2.';
$string['default_threshold_rw'] = 'Default RW routing threshold';
$string['default_threshold_rw_desc'] = 'Default percentage score in Reading & Writing Module 1 required to route to Hard Module 2.';
$string['default'] = 'Default';
$string['disclaimer'] = 'SAT® is a registered trademark of the College Board, which is not affiliated with and does not endorse this plugin.';
$string['enable_graphing_calc'] = 'Enable graphing calculator';
$string['enable_graphing_calc_desc'] = 'Enables the built-in self-hosted graphing calculator in the exam interface.';
$string['enable_scientific_calc'] = 'Enable scientific calculator';
$string['enable_scientific_calc_desc'] = 'Enables the built-in scientific calculator mode in the exam interface.';
$string['examconfig'] = 'Exam configuration';
$string['examdetails'] = 'Exam details';
$string['examnotopen'] = 'This exam is currently closed.';
$string['exam_scope'] = 'Exam scope';
$string['exam_scope_help'] = 'Choose whether this activity is a full two-section SAT exam, or a targeted subject exam for Math or Reading and Writing.';
$string['grade'] = 'Grading';
$string['gradeaverage'] = 'Average grade';
$string['gradehighest'] = 'Highest grade';
$string['grademethod'] = 'Grading method';
$string['graceperiod'] = 'Grace period';
$string['graceperiod_help'] = 'Additional time allowed after the module timer expires for network synchronization before autosubmission.';
$string['managequestions'] = 'Manage questions';
$string['maxgrade'] = 'Maximum grade';
$string['maxgrade_help'] = 'Maximum scaled score for this activity (typically 1600 for full exam, 800 for single section).';
$string['missingquestion'] = 'Missing question';
$string['modulename'] = 'SAT Exam Simulator';
$string['modulenameplural'] = 'SAT Exam Simulators';
$string['modulename_help'] = 'The SAT Exam Simulator activity allows teachers to create computer-based SAT-style practice exams with multi-stage adaptive testing, time controls, and integrated math and passage tools.';
$string['modulename_summary'] = 'Deliver digital SAT-style practice exams with multi-stage adaptive routing, split-screen reading, and built-in math tools.';
$string['modulename_tip'] = 'Use continuous pacing for full mock test simulations, or paced mode for modular homework assignments.';
$string['nomoreattempts'] = 'No more attempts are allowed.';
$string['noattempts'] = 'No student attempts recorded yet.';
$string['nooverrides'] = 'No accommodations or user overrides defined for this exam.';
$string['nosatexams'] = 'No SAT exams found in this course.';
$string['noslotsinmodule'] = 'No question slots in this module yet. Add slots using the form below.';
$string['overrides'] = 'Overrides and accommodations';
$string['overrides_help'] = 'Configure accommodations such as extended time (1.5x, 2.0x time multiplier) or additional attempts for individual students or cohorts.';
$string['pacing_continuous'] = 'Continuous sitting (single session)';
$string['pacing_mode'] = 'Session pacing';
$string['pacing_mode_help'] = 'In continuous mode, modules run back-to-back in one sitting. In paced mode, students can finish a module, pause, and return later to complete subsequent modules.';
$string['pacing_paced'] = 'Multi-session / Paced homework';
$string['pluginadministration'] = 'SAT Exam Simulator administration';
$string['pluginname'] = 'SAT Exam Simulator';
$string['previewexam'] = 'Preview exam';
$string['privacy:metadata:core_question'] = 'SAT Exam Simulator links student question usage with Moodle core question subsystem.';
$string['privacy:metadata:sat_attempts'] = 'Details of student SAT exam attempts, stages, and scores.';
$string['privacy:metadata:sat_attempts:attempt'] = 'The attempt sequence number.';
$string['privacy:metadata:sat_attempts:raw_math'] = 'Raw points earned in Math.';
$string['privacy:metadata:sat_attempts:raw_rw'] = 'Raw points earned in Reading and Writing.';
$string['privacy:metadata:sat_attempts:satid'] = 'The ID of the SAT exam activity.';
$string['privacy:metadata:sat_attempts:scaled_math'] = 'Scaled Math score.';
$string['privacy:metadata:sat_attempts:scaled_rw'] = 'Scaled Reading and Writing score.';
$string['privacy:metadata:sat_attempts:scaled_total'] = 'Total composite scaled score.';
$string['privacy:metadata:sat_attempts:state'] = 'State of the attempt.';
$string['privacy:metadata:sat_attempts:timefinish'] = 'Timestamp when attempt was finished.';
$string['privacy:metadata:sat_attempts:timestart'] = 'Timestamp when attempt started.';
$string['privacy:metadata:sat_attempts:userid'] = 'The ID of the student user.';
$string['privacy:metadata:sat_grades'] = 'Final recorded grades and scaled scores for the SAT exam.';
$string['privacy:metadata:sat_grades:grade'] = 'Final grade recorded.';
$string['privacy:metadata:sat_grades:satid'] = 'The ID of the SAT exam activity.';
$string['privacy:metadata:sat_grades:scaled_math'] = 'Scaled Math score.';
$string['privacy:metadata:sat_grades:scaled_rw'] = 'Scaled Reading and Writing score.';
$string['privacy:metadata:sat_grades:userid'] = 'The ID of the student user.';
$string['privacy:metadata:sat_overrides'] = 'User and group accommodations and overrides.';
$string['privacy:metadata:sat_overrides:attempts'] = 'Custom attempts limit.';
$string['privacy:metadata:sat_overrides:satid'] = 'The ID of the SAT exam activity.';
$string['privacy:metadata:sat_overrides:timemultiplier'] = 'Accommodation time multiplier.';
$string['privacy:metadata:sat_overrides:userid'] = 'The ID of the user.';
$string['privacy:metadata:sat_slot_state'] = 'User option eliminations and highlight annotations.';
$string['privacy:metadata:sat_slot_state:annotations'] = 'Highlight text ranges and sticky notes.';
$string['privacy:metadata:sat_slot_state:attemptid'] = 'The attempt ID.';
$string['privacy:metadata:sat_slot_state:eliminated'] = 'Crossed out choice options.';
$string['privacy:metadata:sat_slot_state:slot'] = 'The slot number.';
$string['qbankentryid'] = 'Question bank entry ID';
$string['qtype'] = 'Question type';
$string['questionname'] = 'Question name';
$string['review'] = 'Review';
$string['routing_adaptive'] = 'Adaptive multi-stage testing (MST)';
$string['routing_fixed'] = 'Fixed modules (no adaptation)';
$string['routing_mode'] = 'Routing mode';
$string['routing_mode_help'] = 'In adaptive mode, student performance on Module 1 routes them to an Easy or Hard Module 2. In fixed mode, all students receive the same Module 2.';
$string['sat:addinstance'] = 'Add a new SAT exam simulator activity';
$string['sat:attempt'] = 'Attempt an SAT exam';
$string['sat:deleteattempts'] = 'Delete SAT exam attempts';
$string['sat:grade'] = 'Grade or regrade SAT exam attempts manually';
$string['sat:manage'] = 'Manage SAT exam structure and questions';
$string['sat:manageoverrides'] = 'Manage SAT exam accommodations and user overrides';
$string['sat:preview'] = 'Preview SAT exam as teacher';
$string['sat:reviewmyattempts'] = 'Review own SAT exam attempts';
$string['sat:view'] = 'View SAT exam information';
$string['sat:viewreports'] = 'View SAT exam reports and attempt analytics';
$string['satname'] = 'Exam name';
$string['scaled_math'] = 'Math score (200-800)';
$string['scaled_rw'] = 'Reading & Writing score (200-800)';
$string['scaled_total'] = 'Total score (400-1600)';
$string['scope_full'] = 'Full SAT exam (Reading & Writing + Math)';
$string['scope_math_only'] = 'Math section only';
$string['scope_rw_only'] = 'Reading & Writing section only';
$string['scope_single_module'] = 'Single module practice';
$string['scored'] = 'Scoring status';
$string['slot'] = 'Slot #';
$string['stage_m1'] = 'Module 1 (Routing)';
$string['stage_m2_easy'] = 'Module 2 (Easy)';
$string['stage_m2_hard'] = 'Module 2 (Hard)';
$string['stage_m2_fixed'] = 'Module 2 (Standard)';
$string['startexam'] = 'Start exam now';
$string['state'] = 'Status';
$string['state_abandoned'] = 'Never submitted';
$string['state_finished'] = 'Completed';
$string['state_inprogress'] = 'In progress';
$string['state_paused'] = 'Paused (waiting for next module)';
$string['summaryofattempts'] = 'Summary of your previous attempts';
$string['task_close_overdue_modules'] = 'Automatically close overdue SAT exam modules';
$string['threshold_math'] = 'Math routing threshold (%)';
$string['threshold_math_help'] = 'Percentage of scored marks in Math Module 1 required to route to the Hard Module 2.';
$string['threshold_rw'] = 'Reading & Writing routing threshold (%)';
$string['threshold_rw_help'] = 'Percentage of scored marks in Reading & Writing Module 1 required to route to the Hard Module 2.';
$string['timecompleted'] = 'Date completed';
$string['timemultiplier'] = 'Time multiplier';
$string['timeclose'] = 'Close date';
$string['timeclose_help'] = 'If enabled, students cannot start or resume attempts after this date.';
$string['timeopen'] = 'Open date';
$string['timeopen_help'] = 'If enabled, students cannot start attempts before this date.';
$string['timing'] = 'Timing';
$string['viewreports'] = 'View reports';
