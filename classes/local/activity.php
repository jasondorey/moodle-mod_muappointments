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
 * Activity lookup, locking, and deletion.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

/**
 * Activity-level operations.
 */
class activity {
    /**
     * @param int $id Activity id.
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('muappointments', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * @param int $activityid
     * @return \stdClass Course module record.
     */
    public static function cm(int $activityid): \stdClass {
        return get_coursemodule_from_instance('muappointments', $activityid, 0, false, MUST_EXIST);
    }

    /**
     * @param int $activityid
     * @return \context_module
     */
    public static function context(int $activityid): \context_module {
        $cm = self::cm($activityid);
        return \context_module::instance($cm->id);
    }

    /**
     * Run $callback inside a per-activity lock and a delegated transaction.
     *
     * The lock is not re-entrant. Callers that already hold it must not call this again.
     *
     * @param int $activityid
     * @param callable $callback
     * @return mixed
     */
    public static function lock(int $activityid, callable $callback): mixed {
        global $DB;
        $factory = \core\lock\lock_config::get_lock_factory('mod_muappointments');
        $lock = $factory->get_lock('activity_' . $activityid, 10);
        if (!$lock) {
            throw new \moodle_exception('couldnotlock', 'mod_muappointments');
        }
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                $result = $callback();
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
                throw $e;
            }
            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Delete every appointment in the activity, including signups and calendar events.
     *
     * @param \stdClass $activity
     * @param bool $notify When false, course reset stays quiet.
     */
    public static function delete_appointments(\stdClass $activity, bool $notify = false): void {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'muappointments_appointment',
            'id',
            'muappointmentsid = ?',
            [$activity->id]
        );
        foreach ($ids as $id) {
            appointment_service::delete((int) $id, $notify);
        }
    }

    /**
     * Delete signups only. Appointments, sessions, and shared calendar events stay.
     *
     * @param \stdClass $activity
     */
    public static function delete_signups(\stdClass $activity): void {
        global $DB;
        $appointments = $DB->get_records('muappointments_appointment', ['muappointmentsid' => $activity->id], '', 'id');
        if (!$appointments) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($appointments), SQL_PARAMS_NAMED);
        $signups = $DB->get_records_select('muappointments_signup', "appointmentid $insql", $params);
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
        $course = get_course($activity->course);
        $cm = self::cm((int) $activity->id);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm)) {
            $completion->reset_all_state($cm);
        }
    }

    /**
     * Delete the activity row and everything under it.
     *
     * @param int $id
     * @return bool
     */
    public static function delete_instance(int $id): bool {
        global $DB;
        $activity = $DB->get_record('muappointments', ['id' => $id]);
        if (!$activity) {
            return false;
        }
        self::delete_appointments($activity, false);
        $DB->delete_records('muappointments_notify', ['muappointmentsid' => $id]);
        $DB->delete_records('muappointments', ['id' => $id]);
        return true;
    }

    /**
     * Columns written to the activity table.
     *
     * @param \stdClass $data
     * @param bool $forupdate
     * @return \stdClass
     */
    public static function record_from_form(\stdClass $data, bool $forupdate): \stdClass {
        $record = new \stdClass();
        if ($forupdate) {
            $record->id = $data->instance ?? $data->id;
        } else if (!empty($data->course)) {
            $record->course = $data->course;
        }
        $record->name = $data->name ?? '';
        $record->intro = $data->intro ?? '';
        $record->introformat = $data->introformat ?? FORMAT_HTML;
        $record->thirdpartyemails = trim($data->thirdpartyemails ?? '');
        $record->thirdpartywaitlist = empty($data->thirdpartywaitlist) ? 0 : 1;
        $record->allowcanceldefault = !isset($data->allowcanceldefault) || !empty($data->allowcanceldefault) ? 1 : 0;
        $display = $data->calendardisplay ?? 'course';
        $record->calendardisplay = in_array($display, schedule::CALENDAR_DISPLAYS, true) ? $display : 'course';
        $record->showoncalendar = !isset($data->showoncalendar) || !empty($data->showoncalendar) ? 1 : 0;
        $record->shortname = trim($data->shortname ?? '');
        $record->reminderperiod = max(0, (int) ($data->reminderperiod ?? 2));
        $record->completionbook = empty($data->completionbook) ? 0 : 1;
        $record->completionattend = empty($data->completionattend) ? 0 : 1;
        $record->timemodified = time();
        if (!$forupdate) {
            $record->timecreated = $record->timemodified;
        }
        return $record;
    }
}
