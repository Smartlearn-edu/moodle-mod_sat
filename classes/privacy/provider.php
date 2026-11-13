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

namespace mod_sat\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context_module;
use question_engine;

/**
 * Privacy API provider implementation for mod_sat.
 *
 * @package    mod_sat
 * @copyright  2026 Mohammad Nabil <mohammad@smartlearn.education>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Returns metadata about this plugin's data storage.
     *
     * @param collection $collection The collection of metadata items
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'sat_attempts',
            [
                'satid' => 'privacy:metadata:sat_attempts:satid',
                'userid' => 'privacy:metadata:sat_attempts:userid',
                'attempt' => 'privacy:metadata:sat_attempts:attempt',
                'state' => 'privacy:metadata:sat_attempts:state',
                'raw_rw' => 'privacy:metadata:sat_attempts:raw_rw',
                'raw_math' => 'privacy:metadata:sat_attempts:raw_math',
                'scaled_rw' => 'privacy:metadata:sat_attempts:scaled_rw',
                'scaled_math' => 'privacy:metadata:sat_attempts:scaled_math',
                'scaled_total' => 'privacy:metadata:sat_attempts:scaled_total',
                'timestart' => 'privacy:metadata:sat_attempts:timestart',
                'timefinish' => 'privacy:metadata:sat_attempts:timefinish',
            ],
            'privacy:metadata:sat_attempts'
        );

        $collection->add_database_table(
            'sat_slot_state',
            [
                'attemptid' => 'privacy:metadata:sat_slot_state:attemptid',
                'slot' => 'privacy:metadata:sat_slot_state:slot',
                'eliminated' => 'privacy:metadata:sat_slot_state:eliminated',
                'annotations' => 'privacy:metadata:sat_slot_state:annotations',
                'response' => 'privacy:metadata:sat_slot_state:response',
                'flagged' => 'privacy:metadata:sat_slot_state:flagged',
            ],
            'privacy:metadata:sat_slot_state'
        );

        $collection->add_database_table(
            'sat_grades',
            [
                'satid' => 'privacy:metadata:sat_grades:satid',
                'userid' => 'privacy:metadata:sat_grades:userid',
                'grade' => 'privacy:metadata:sat_grades:grade',
                'scaled_rw' => 'privacy:metadata:sat_grades:scaled_rw',
                'scaled_math' => 'privacy:metadata:sat_grades:scaled_math',
            ],
            'privacy:metadata:sat_grades'
        );

        $collection->add_database_table(
            'sat_overrides',
            [
                'satid' => 'privacy:metadata:sat_overrides:satid',
                'userid' => 'privacy:metadata:sat_overrides:userid',
                'timemultiplier' => 'privacy:metadata:sat_overrides:timemultiplier',
                'attempts' => 'privacy:metadata:sat_overrides:attempts',
            ],
            'privacy:metadata:sat_overrides'
        );

        $collection->add_subsystem_link('core_question', [], 'privacy:metadata:core_question');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT c.id
                  FROM {context} c
                  JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'sat'
                  JOIN {sat} s ON s.id = cm.instance
             LEFT JOIN {sat_attempts} a ON a.satid = s.id AND a.userid = :userid1
             LEFT JOIN {sat_grades} g ON g.satid = s.id AND g.userid = :userid2
             LEFT JOIN {sat_overrides} o ON o.satid = s.id AND o.userid = :userid3
                 WHERE a.id IS NOT NULL OR g.id IS NOT NULL OR o.id IS NOT NULL";

        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add to
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_module) {
            return;
        }

        $sql = "SELECT a.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'sat'
                  JOIN {sat} s ON s.id = cm.instance
                  JOIN {sat_attempts} a ON a.satid = s.id
                 WHERE cm.id = :cmid";

        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Export all user data for the specified approved_contextlist.
     *
     * @param approved_contextlist $contextlist The list of approved contexts
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('sat', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $sat = $DB->get_record('sat', ['id' => $cm->instance]);
            if (!$sat) {
                continue;
            }

            // Export attempts.
            $attempts = $DB->get_records('sat_attempts', [
                'satid' => $sat->id,
                'userid' => $user->id,
            ]);

            foreach ($attempts as $attempt) {
                $attemptdata = (object) [
                    'attempt' => $attempt->attempt,
                    'state' => $attempt->state,
                    'scaled_rw' => $attempt->scaled_rw,
                    'scaled_math' => $attempt->scaled_math,
                    'scaled_total' => $attempt->scaled_total,
                    'timestart' => userdate($attempt->timestart),
                    'timefinish' => $attempt->timefinish ? userdate($attempt->timefinish) : '',
                ];

                writer::with_context($context)->export_data(
                    [get_string('attempt', 'mod_sat') . ' ' . $attempt->attempt],
                    $attemptdata
                );
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('sat', $context->instanceid);
        if (!$cm) {
            return;
        }

        $attempts = $DB->get_records('sat_attempts', ['satid' => $cm->instance], '', 'id, uniqueid');
        foreach ($attempts as $attempt) {
            $DB->delete_records('sat_slot_state', ['attemptid' => $attempt->id]);
            $DB->delete_records('sat_module_attempts', ['attemptid' => $attempt->id]);

            if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
                question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
            }
        }

        $DB->delete_records('sat_attempts', ['satid' => $cm->instance]);
        $DB->delete_records('sat_grades', ['satid' => $cm->instance]);
        $DB->delete_records('sat_overrides', ['satid' => $cm->instance]);
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $user = $contextlist->get_user();

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }

            $cm = get_coursemodule_from_id('sat', $context->instanceid);
            if (!$cm) {
                continue;
            }

            $attempts = $DB->get_records('sat_attempts', [
                'satid' => $cm->instance,
                'userid' => $user->id,
            ], '', 'id, uniqueid');

            foreach ($attempts as $attempt) {
                $DB->delete_records('sat_slot_state', ['attemptid' => $attempt->id]);
                $DB->delete_records('sat_module_attempts', ['attemptid' => $attempt->id]);

                if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
                    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
                }
            }

            $DB->delete_records('sat_attempts', ['satid' => $cm->instance, 'userid' => $user->id]);
            $DB->delete_records('sat_grades', ['satid' => $cm->instance, 'userid' => $user->id]);
            $DB->delete_records('sat_overrides', ['satid' => $cm->instance, 'userid' => $user->id]);
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $cm = get_coursemodule_from_id('sat', $context->instanceid);
        if (!$cm) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge(['satid' => $cm->instance], $inparams);

        $attempts = $DB->get_records_select('sat_attempts', "satid = :satid AND userid $insql", $params, '', 'id, uniqueid');
        foreach ($attempts as $attempt) {
            $DB->delete_records('sat_slot_state', ['attemptid' => $attempt->id]);
            $DB->delete_records('sat_module_attempts', ['attemptid' => $attempt->id]);

            if (!empty($attempt->uniqueid) && class_exists('question_engine')) {
                question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
            }
        }

        $DB->delete_records_select('sat_attempts', "satid = :satid AND userid $insql", $params);
        $DB->delete_records_select('sat_grades', "satid = :satid AND userid $insql", $params);
        $DB->delete_records_select('sat_overrides', "satid = :satid AND userid $insql", $params);
    }
}
