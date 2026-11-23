<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Privacy provider.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_muappointments\local\calendar_service;

/**
 * Export and delete signup, cancellation, attendance, and reminder data.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * @param collection $items
     * @return collection
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table('muappointments_signup', [
            'userid' => 'privacy:metadata:signup:userid',
            'status' => 'privacy:metadata:signup:status',
            'cancelnote' => 'privacy:metadata:signup:cancelnote',
            'bookedby' => 'privacy:metadata:signup:bookedby',
            'remindersent' => 'privacy:metadata:signup:remindersent',
            'timecreated' => 'privacy:metadata:signup:timecreated',
            'timemodified' => 'privacy:metadata:signup:timemodified',
        ], 'privacy:metadata:signup');
        $items->add_database_table('muappointments_status', [
            'status' => 'privacy:metadata:status:status',
            'note' => 'privacy:metadata:status:note',
            'userid' => 'privacy:metadata:status:userid',
            'timecreated' => 'privacy:metadata:status:timecreated',
        ], 'privacy:metadata:status');
        return $items;
    }

    /**
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {muappointments} a ON a.id = cm.instance
                  JOIN {muappointments_appointment} ap ON ap.muappointmentsid = a.id
                  JOIN {muappointments_signup} s ON s.appointmentid = ap.id
                 WHERE s.userid = :userid OR s.bookedby = :bookedby";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_MODULE,
            'modname' => 'muappointments',
            'userid' => $userid,
            'bookedby' => $userid,
        ]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :level
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {muappointments} a ON a.id = cm.instance
                  JOIN {muappointments_appointment} ap ON ap.muappointmentsid = a.id
                  JOIN {muappointments_signup} s ON s.appointmentid = ap.id
                  JOIN {muappointments_status} h ON h.signupid = s.id
                 WHERE h.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'level' => CONTEXT_MODULE,
            'modname' => 'muappointments',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT s.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {muappointments} a ON a.id = cm.instance
                  JOIN {muappointments_appointment} ap ON ap.muappointmentsid = a.id
                  JOIN {muappointments_signup} s ON s.appointmentid = ap.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'muappointments', 'cmid' => $context->instanceid]);
        $sql = "SELECT s.bookedby AS userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {muappointments} a ON a.id = cm.instance
                  JOIN {muappointments_appointment} ap ON ap.muappointmentsid = a.id
                  JOIN {muappointments_signup} s ON s.appointmentid = ap.id
                 WHERE cm.id = :cmid AND s.bookedby <> 0";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'muappointments', 'cmid' => $context->instanceid]);
        $sql = "SELECT h.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {muappointments} a ON a.id = cm.instance
                  JOIN {muappointments_appointment} ap ON ap.muappointmentsid = a.id
                  JOIN {muappointments_signup} s ON s.appointmentid = ap.id
                  JOIN {muappointments_status} h ON h.signupid = s.id
                 WHERE cm.id = :cmid AND h.userid <> 0";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'muappointments', 'cmid' => $context->instanceid]);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('muappointments', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $sql = "SELECT s.*, ap.description
                      FROM {muappointments_signup} s
                      JOIN {muappointments_appointment} ap ON ap.id = s.appointmentid
                     WHERE ap.muappointmentsid = :instance AND s.userid = :userid
                  ORDER BY s.timecreated ASC";
            $signups = $DB->get_records_sql($sql, ['instance' => $cm->instance, 'userid' => $user->id]);
            $export = [];
            foreach ($signups as $signup) {
                $history = [];
                $rows = $DB->get_records('muappointments_status', ['signupid' => $signup->id], 'timecreated ASC');
                foreach ($rows as $row) {
                    $history[] = [
                        'status' => $row->status,
                        'note' => $row->note,
                        'timecreated' => transform::datetime($row->timecreated),
                        'userid' => $row->userid,
                    ];
                }
                $export[] = [
                    'status' => $signup->status,
                    'cancelnote' => $signup->cancelnote,
                    'bookedby' => $signup->bookedby,
                    'remindersent' => $signup->remindersent ? transform::datetime($signup->remindersent) : null,
                    'timecreated' => transform::datetime($signup->timecreated),
                    'timemodified' => transform::datetime($signup->timemodified),
                    'history' => $history,
                ];
            }
            $contextdata = helper::get_context_data($context, $user);
            $contextdata = (object) array_merge((array) $contextdata, ['signups' => $export]);
            writer::with_context($context)->export_data([], $contextdata);
            helper::export_context_files($context, $user);
        }
    }

    /**
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('muappointments', $context->instanceid);
        if (!$cm) {
            return;
        }
        $activity = \mod_muappointments\local\activity::get((int) $cm->instance);
        \mod_muappointments\local\activity::delete_signups($activity);
    }

    /**
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_module) {
                self::delete_user_in_context((int) $contextlist->get_user()->id, $context);
            }
        }
    }

    /**
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_in_context((int) $userid, $context);
        }
    }

    /**
     * @param int $userid
     * @param \context_module $context
     */
    private static function delete_user_in_context(int $userid, \context_module $context): void {
        global $DB;
        $cm = get_coursemodule_from_id('muappointments', $context->instanceid);
        if (!$cm) {
            return;
        }
        $appointments = $DB->get_records('muappointments_appointment', ['muappointmentsid' => $cm->instance], '', 'id');
        if (!$appointments) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($appointments), SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $signups = $DB->get_records_select('muappointments_signup', "appointmentid $insql AND userid = :userid", $params);
        if ($signups) {
            [$sinsql, $sparams] = $DB->get_in_or_equal(array_keys($signups), SQL_PARAMS_NAMED);
            $maps = $DB->get_records_select('muappointments_userevent', "signupid $sinsql", $sparams);
            foreach ($maps as $map) {
                calendar_service::delete_event((int) $map->eventid);
            }
            $DB->delete_records_select('muappointments_userevent', "signupid $sinsql", $sparams);
            $DB->delete_records_select('muappointments_status', "signupid $sinsql", $sparams);
            $DB->delete_records_select('muappointments_signup', "id $sinsql", $sparams);
        }
        [$insql, $apparams] = $DB->get_in_or_equal(array_keys($appointments), SQL_PARAMS_NAMED);
        $bookedbyparams = $apparams;
        $bookedbyparams['userid'] = $userid;
        $DB->set_field_select(
            'muappointments_signup',
            'bookedby',
            0,
            "appointmentid $insql AND bookedby = :userid",
            $bookedbyparams
        );
        $signupids = $DB->get_fieldset_select('muappointments_signup', 'id', "appointmentid $insql", $apparams);
        if ($signupids) {
            [$hsql, $hparams] = $DB->get_in_or_equal($signupids, SQL_PARAMS_NAMED);
            $hparams['userid'] = $userid;
            $DB->set_field_select('muappointments_status', 'userid', 0, "signupid $hsql AND userid = :userid", $hparams);
        }
    }
}
