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
 * Appointments, sessions, the timeframe generator, and duplication.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

use mod_muappointments\customfield\appointment_handler;

/**
 * Bookable appointments.
 */
class appointment_service {
    /**
     * @param int $activityid
     * @param int $capacity
     * @param bool $waitlist
     * @param bool $allowcancel
     * @param string $description
     * @param int $format
     * @param array<int, array{timestart: int, timefinish: int}> $sessions
     * @return int Appointment id.
     */
    public static function create(
        int $activityid,
        int $capacity,
        bool $waitlist,
        bool $allowcancel,
        string $description,
        int $format,
        array $sessions
    ): int {
        global $DB;
        $now = time();
        $id = $DB->insert_record('muappointments_appointment', (object) [
            'muappointmentsid' => $activityid,
            'capacity' => max(0, $capacity),
            'waitlist' => $waitlist ? 1 : 0,
            'allowcancel' => $allowcancel ? 1 : 0,
            'description' => $description,
            'descriptionformat' => $format,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        self::replace_sessions($id, $sessions);
        calendar_service::sync_appointment($id);
        self::trigger('appointment_created', $id, $activityid);
        return (int) $id;
    }

    /**
     * Replace the appointment settings and its full session list.
     *
     * Session rows are reused in start-time order so iCalendar UIDs stay stable.
     * Adding the first session promotes wait-listed signups up to capacity.
     *
     * @param int $appointmentid
     * @param int $capacity
     * @param bool $waitlist
     * @param bool $allowcancel
     * @param string $description
     * @param int $format
     * @param array<int, array{timestart: int, timefinish: int}> $sessions
     * @return array{timeschanged: bool, descriptionchanged: bool, promoted: \stdClass[]}
     */
    public static function update(
        int $appointmentid,
        int $capacity,
        bool $waitlist,
        bool $allowcancel,
        string $description,
        int $format,
        array $sessions
    ): array {
        global $DB;
        $existing = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $activityid = (int) $existing->muappointmentsid;
        $promoted = [];
        $timeschanged = false;
        $descriptionchanged = ((string) $existing->description !== $description);
        activity::lock($activityid, function () use (
            $appointmentid,
            $capacity,
            $waitlist,
            $allowcancel,
            $description,
            $format,
            $sessions,
            &$promoted,
            &$timeschanged
        ) {
            global $DB;
            $before = self::sessions($appointmentid);
            $DB->update_record('muappointments_appointment', (object) [
                'id' => $appointmentid,
                'capacity' => max(0, $capacity),
                'waitlist' => $waitlist ? 1 : 0,
                'allowcancel' => $allowcancel ? 1 : 0,
                'description' => $description,
                'descriptionformat' => $format,
                'timemodified' => time(),
            ]);
            $timeschanged = self::replace_sessions($appointmentid, $sessions);
            if ($before === [] && $sessions !== []) {
                $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
                $free = max(0, (int) $appointment->capacity - signup_service::count_booked($appointmentid));
                $promoted = signup_service::promote_unlocked($appointment, $free);
            }
        });
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $activity = activity::get($activityid);
        $currentsessions = self::sessions($appointmentid);
        calendar_service::sync_appointment($appointmentid);
        foreach ($promoted as $signup) {
            notification_service::send('confirmation', $activity, $appointment, $currentsessions, $signup);
            signup_service::update_completion($activityid, (int) $signup->userid);
            signup_service::trigger_signup($signup, $activityid);
        }
        self::trigger('appointment_updated', $appointmentid, $activityid);
        return [
            'timeschanged' => $timeschanged,
            'descriptionchanged' => $descriptionchanged,
            'promoted' => $promoted,
        ];
    }

    /**
     * Send the update template when times, description, or custom fields changed.
     *
     * Promoted signups already received confirmation.
     *
     * @param int $appointmentid
     * @param array{timeschanged: bool, descriptionchanged: bool, promoted: \stdClass[]} $result
     * @param array<string, string> $previousfields
     */
    public static function publish_edits(int $appointmentid, array $result, array $previousfields): void {
        global $DB;
        $current = self::field_values($appointmentid);
        ksort($previousfields);
        $fieldschanged = $previousfields !== $current;
        if (empty($result['timeschanged']) && empty($result['descriptionchanged']) && !$fieldschanged) {
            return;
        }
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $activity = activity::get((int) $appointment->muappointmentsid);
        self::send_updates(
            $activity,
            $appointment,
            self::sessions($appointmentid),
            $result['promoted'] ?? []
        );
    }

    /**
     * @param int $appointmentid
     * @param bool $notify Send cancellation mail.
     */
    public static function delete(int $appointmentid, bool $notify = true): void {
        global $DB;
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid]);
        if (!$appointment) {
            return;
        }
        $activity = activity::get((int) $appointment->muappointmentsid);
        $sessions = self::sessions($appointmentid);
        $signups = $DB->get_records('muappointments_signup', ['appointmentid' => $appointmentid]);
        $payloads = [];
        foreach ($signups as $signup) {
            if (schedule::is_active($signup->status)) {
                $payloads[] = clone $signup;
            }
        }
        activity::lock((int) $activity->id, function () use ($appointmentid, $signups, $sessions) {
            global $DB;
            $signupids = array_keys($signups);
            if ($signupids) {
                [$insql, $params] = $DB->get_in_or_equal($signupids, SQL_PARAMS_NAMED);
                $maps = $DB->get_records_select('muappointments_userevent', "signupid $insql", $params);
                foreach ($maps as $map) {
                    calendar_service::delete_event((int) $map->eventid);
                }
                $DB->delete_records_select('muappointments_userevent', "signupid $insql", $params);
                $DB->delete_records_select('muappointments_status', "signupid $insql", $params);
                $DB->delete_records_select('muappointments_signup', "id $insql", $params);
            }
            foreach ($sessions as $session) {
                calendar_service::delete_event((int) $session->eventid);
            }
            $DB->delete_records('muappointments_session', ['appointmentid' => $appointmentid]);
            $handler = appointment_handler::create();
            $handler->delete_instance($appointmentid);
            $DB->delete_records('muappointments_appointment', ['id' => $appointmentid]);
        });
        if ($notify) {
            foreach ($payloads as $signup) {
                notification_service::send('cancellation', $activity, $appointment, $sessions, $signup);
            }
        }
        foreach ($payloads as $signup) {
            signup_service::update_completion((int) $activity->id, (int) $signup->userid);
        }
        self::trigger('appointment_deleted', $appointmentid, (int) $activity->id);
    }

    /**
     * Copy the appointment. The delta between the old and new first start is applied to every session.
     *
     * @param int $appointmentid
     * @param int $newfirststart
     * @return int New appointment id.
     */
    public static function duplicate(int $appointmentid, int $newfirststart): int {
        global $DB;
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $pairs = [];
        foreach (self::sessions($appointmentid) as $session) {
            $pairs[] = ['timestart' => (int) $session->timestart, 'timefinish' => (int) $session->timefinish];
        }
        $shifted = schedule::shift_sessions($pairs, $newfirststart);
        $newid = self::create(
            (int) $appointment->muappointmentsid,
            (int) $appointment->capacity,
            (bool) $appointment->waitlist,
            (bool) $appointment->allowcancel,
            (string) $appointment->description,
            (int) $appointment->descriptionformat,
            $shifted
        );
        self::copy_fields($appointmentid, $newid);
        return $newid;
    }

    /**
     * One appointment per generated slot, each with a single session.
     *
     * @param int $activityid
     * @param array<int, array{start: int, end: int}> $timeframes
     * @param int $splitseconds
     * @param int $breakseconds
     * @param int $capacity
     * @param bool $waitlist
     * @param bool $allowcancel
     * @param string $description
     * @param int $format
     * @return int[] New appointment ids.
     */
    public static function create_from_timeframes(
        int $activityid,
        array $timeframes,
        int $splitseconds,
        int $breakseconds,
        int $capacity,
        bool $waitlist,
        bool $allowcancel,
        string $description,
        int $format
    ): array {
        $ids = [];
        foreach ($timeframes as $frame) {
            $slots = schedule::split_timeframe(
                (int) $frame['start'],
                (int) $frame['end'],
                $splitseconds,
                $breakseconds
            );
            foreach ($slots as $slot) {
                $ids[] = self::create(
                    $activityid,
                    $capacity,
                    $waitlist,
                    $allowcancel,
                    $description,
                    $format,
                    [$slot]
                );
            }
        }
        return $ids;
    }

    /**
     * @param int $appointmentid
     * @return \stdClass[] Ordered by start.
     */
    public static function sessions(int $appointmentid): array {
        global $DB;
        return $DB->get_records('muappointments_session', ['appointmentid' => $appointmentid], 'timestart ASC, id ASC');
    }

    /**
     * @param int $appointmentid
     * @return array<string, string>
     */
    public static function field_values(int $appointmentid): array {
        $values = [];
        $handler = appointment_handler::create();
        foreach ($handler->get_instance_data($appointmentid, true) as $data) {
            $values[$data->get_field()->get('shortname')] = trim((string) $data->get_value());
        }
        ksort($values);
        return $values;
    }

    /**
     * @param string $eventname Event class short name.
     * @param int $appointmentid
     * @param int $activityid
     */
    private static function trigger(string $eventname, int $appointmentid, int $activityid): void {
        $class = '\\mod_muappointments\\event\\' . $eventname;
        $class::create([
            'objectid' => $appointmentid,
            'context' => activity::context($activityid),
            'other' => ['muappointmentsid' => $activityid],
        ])->trigger();
    }

    /**
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param array $sessions
     * @param \stdClass[] $skip Signups that already received confirmation.
     * @param int[] $skipuserids
     */
    private static function send_updates(
        \stdClass $activity,
        \stdClass $appointment,
        array $sessions,
        array $skip = [],
        array $skipuserids = []
    ): void {
        global $DB;
        $skipids = [];
        foreach ($skip as $signup) {
            $skipids[] = (int) $signup->id;
        }
        $signups = $DB->get_records('muappointments_signup', ['appointmentid' => $appointment->id]);
        foreach ($signups as $signup) {
            if (!schedule::is_active($signup->status) || !in_array($signup->status, [
                schedule::BOOKED,
                schedule::WAITLISTED,
                schedule::NOSHOW,
                schedule::PARTIALLYATTENDED,
                schedule::FULLYATTENDED,
            ], true)) {
                continue;
            }
            if (in_array((int) $signup->id, $skipids, true) || in_array((int) $signup->userid, $skipuserids, true)) {
                continue;
            }
            notification_service::send('update', $activity, $appointment, $sessions, $signup);
        }
    }

    /**
     * @param int $appointmentid
     * @param array<int, array{timestart: int, timefinish: int}> $sessions
     * @return bool True when any start or finish changed.
     */
    private static function replace_sessions(int $appointmentid, array $sessions): bool {
        global $DB;
        $normalised = [];
        foreach ($sessions as $session) {
            $start = (int) $session['timestart'];
            $finish = (int) $session['timefinish'];
            if ($start <= 0 || $finish <= $start) {
                continue;
            }
            $normalised[] = ['timestart' => $start, 'timefinish' => $finish];
        }
        usort($normalised, static function (array $a, array $b): int {
            return $a['timestart'] <=> $b['timestart'];
        });
        $existing = array_values(self::sessions($appointmentid));
        $changed = count($existing) !== count($normalised);
        $now = time();
        foreach ($normalised as $index => $slot) {
            if (isset($existing[$index])) {
                $row = $existing[$index];
                if ((int) $row->timestart !== $slot['timestart'] || (int) $row->timefinish !== $slot['timefinish']) {
                    $changed = true;
                    $DB->update_record('muappointments_session', (object) [
                        'id' => $row->id,
                        'timestart' => $slot['timestart'],
                        'timefinish' => $slot['timefinish'],
                        'timemodified' => $now,
                    ]);
                }
                continue;
            }
            $changed = true;
            $DB->insert_record('muappointments_session', (object) [
                'appointmentid' => $appointmentid,
                'timestart' => $slot['timestart'],
                'timefinish' => $slot['timefinish'],
                'eventid' => 0,
                'timemodified' => $now,
            ]);
        }
        if (count($existing) > count($normalised)) {
            $changed = true;
            foreach (array_slice($existing, count($normalised)) as $row) {
                calendar_service::delete_event((int) $row->eventid);
                $DB->delete_records('muappointments_userevent', ['sessionid' => $row->id]);
                $DB->delete_records('muappointments_session', ['id' => $row->id]);
            }
        }
        return $changed;
    }

    /**
     * @param int $from
     * @param int $to
     */
    private static function copy_fields(int $from, int $to): void {
        $handler = appointment_handler::create();
        $context = $handler->get_instance_context($to);
        foreach ($handler->get_instance_data($from, false) as $data) {
            $value = $data->get_value();
            if ($value === null || $value === '') {
                continue;
            }
            $created = \core_customfield\data_controller::create(0, (object) ['instanceid' => $to], $data->get_field());
            $created->set($created->datafield(), $value);
            $created->set('value', $value);
            $created->set('valueformat', $data->get('valueformat'));
            $created->set('contextid', $context->id);
            $created->save();
        }
    }
}
