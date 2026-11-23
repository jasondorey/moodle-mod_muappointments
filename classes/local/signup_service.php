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
 * Signup, wait list, promotion, cancellation, and attendance.
 *
 * Capacity and the one-active-signup rule are re-checked inside the activity lock.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

/**
 * Signup writes.
 */
class signup_service {
    /**
     * Self-service signup. Session-less appointments always wait-list.
     *
     * @param int $appointmentid
     * @param int $userid
     * @return \stdClass
     */
    public static function book(int $appointmentid, int $userid): \stdClass {
        global $USER;
        [$activity, $appointment] = self::load($appointmentid);
        $context = activity::context((int) $activity->id);
        if ((int) $USER->id !== $userid) {
            require_capability('mod/muappointments:addattendees', $context);
        }
        require_capability('mod/muappointments:signup', $context, $userid);
        self::assert_enrolled($context, $userid);

        $signup = activity::lock((int) $activity->id, function () use ($activity, $userid, $appointmentid) {
            global $DB;
            self::assert_no_other_active((int) $activity->id, $userid);
            $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
            $sessions = appointment_service::sessions($appointmentid);
            $display = self::display($appointment, $sessions);
            self::assert_not_closed($display);
            if ($sessions === []) {
                if ($display === schedule::FULL && empty($appointment->waitlist)) {
                    throw new \moodle_exception('appointmentfull', 'mod_muappointments');
                }
                return self::insert($appointmentid, $userid, $userid, schedule::WAITLISTED);
            }
            if ($display === schedule::OPEN) {
                $booked = self::count_booked($appointmentid);
                if ($booked >= (int) $appointment->capacity) {
                    if (!empty($appointment->waitlist)) {
                        return self::insert($appointmentid, $userid, $userid, schedule::WAITLISTED);
                    }
                    throw new \moodle_exception('appointmentfull', 'mod_muappointments');
                }
                return self::insert($appointmentid, $userid, $userid, schedule::BOOKED);
            }
            if ($display === schedule::FULL && !empty($appointment->waitlist)) {
                return self::insert($appointmentid, $userid, $userid, schedule::WAITLISTED);
            }
            throw new \moodle_exception('appointmentfull', 'mod_muappointments');
        });
        self::after_signup($activity, $appointment, $signup, false);
        return $signup;
    }

    /**
     * Book past capacity. Only when the appointment is full and the wait list is off.
     *
     * @param int $appointmentid
     * @param int $userid
     * @return \stdClass
     */
    public static function overbook(int $appointmentid, int $userid): \stdClass {
        [$activity, $appointment] = self::load($appointmentid);
        $context = activity::context((int) $activity->id);
        require_capability('mod/muappointments:overbook', $context, $userid);
        self::assert_enrolled($context, $userid);
        $signup = activity::lock((int) $activity->id, function () use ($activity, $userid, $appointmentid) {
            global $DB;
            self::assert_no_other_active((int) $activity->id, $userid);
            $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
            $sessions = appointment_service::sessions($appointmentid);
            $display = self::display($appointment, $sessions);
            self::assert_not_closed($display);
            if ($display !== schedule::FULL || !empty($appointment->waitlist)) {
                throw new \moodle_exception('overbooknotallowed', 'mod_muappointments');
            }
            return self::insert($appointmentid, $userid, $userid, schedule::BOOKED);
        });
        self::after_signup($activity, $appointment, $signup, false);
        return $signup;
    }

    /**
     * Teacher add. May pass capacity, never uses the wait list, and can suppress mail.
     *
     * @param int $appointmentid
     * @param int[] $userids
     * @param int $actorid
     * @param bool $suppressemail
     * @return \stdClass[]
     */
    public static function add_attendees(
        int $appointmentid,
        array $userids,
        int $actorid,
        bool $suppressemail = false
    ): array {
        [$activity, $appointment] = self::load($appointmentid);
        $context = activity::context((int) $activity->id);
        require_capability('mod/muappointments:addattendees', $context);
        $created = activity::lock((int) $activity->id, function () use ($activity, $appointmentid, $userids, $actorid, $context) {
            global $DB;
            $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
            $sessions = appointment_service::sessions($appointmentid);
            $display = self::display($appointment, $sessions);
            self::assert_not_closed($display);
            $rows = [];
            foreach ($userids as $userid) {
                $userid = (int) $userid;
                if ($userid <= 0) {
                    continue;
                }
                self::assert_enrolled($context, $userid);
                self::assert_no_other_active((int) $activity->id, $userid);
                $rows[] = self::insert($appointmentid, $userid, $actorid, schedule::BOOKED);
            }
            return $rows;
        });
        foreach ($created as $signup) {
            self::after_signup($activity, $appointment, $signup, $suppressemail);
        }
        return $created;
    }

    /**
     * Cancel or remove a signup. A freed seat promotes the oldest wait-listed signup once sessions exist.
     *
     * @param int $signupid
     * @param int $actorid
     * @param string $note
     * @return void
     */
    public static function cancel(int $signupid, int $actorid, string $note = ''): void {
        global $DB;
        $signup = $DB->get_record('muappointments_signup', ['id' => $signupid], '*', MUST_EXIST);
        [$activity, $appointment] = self::load((int) $signup->appointmentid);
        $context = activity::context((int) $activity->id);
        $self = (int) $actorid === (int) $signup->userid;
        if ($self) {
            require_capability('mod/muappointments:signup', $context, $actorid);
            if (empty($appointment->allowcancel)) {
                throw new \moodle_exception('cancelnotallowed', 'mod_muappointments');
            }
        } else {
            require_capability('mod/muappointments:removeattendees', $context, $actorid);
        }
        if (!schedule::is_active($signup->status)) {
            throw new \moodle_exception('alreadycancelled', 'mod_muappointments');
        }
        $snapshot = clone $signup;
        $promoted = [];
        activity::lock((int) $activity->id, function () use ($signup, $actorid, $note, $appointment, &$promoted) {
            global $DB;
            $current = $DB->get_record('muappointments_signup', ['id' => $signup->id], '*', MUST_EXIST);
            if (!schedule::is_active($current->status)) {
                throw new \moodle_exception('alreadycancelled', 'mod_muappointments');
            }
            $wasseat = schedule::consumes_seat($current->status);
            $now = time();
            $DB->update_record('muappointments_signup', (object) [
                'id' => $current->id,
                'status' => schedule::USERCANCELLED,
                'cancelnote' => $note,
                'timemodified' => $now,
            ]);
            self::history((int) $current->id, schedule::USERCANCELLED, $note, $actorid, $now);
            $sessions = appointment_service::sessions((int) $appointment->id);
            if ($wasseat && $sessions !== []) {
                $reloaded = $DB->get_record('muappointments_appointment', ['id' => $appointment->id], '*', MUST_EXIST);
                $free = max(0, (int) $reloaded->capacity - self::count_booked((int) $reloaded->id));
                if ($free > 0) {
                    $promoted = self::promote_unlocked($reloaded, 1);
                }
            }
        });
        $sessions = appointment_service::sessions((int) $appointment->id);
        calendar_service::sync_appointment((int) $appointment->id);
        notification_service::send('cancellation', $activity, $appointment, $sessions, $snapshot);
        self::update_completion((int) $activity->id, (int) $signup->userid);
        self::trigger_cancelled($snapshot, (int) $activity->id, $actorid);
        foreach ($promoted as $row) {
            notification_service::send('confirmation', $activity, $appointment, $sessions, $row);
            self::update_completion((int) $activity->id, (int) $row->userid);
            self::trigger_signup($row, (int) $activity->id);
        }
    }

    /**
     * One attendance status for the signup, after a session has started.
     *
     * @param int $appointmentid
     * @param array<int, string> $signuptostatus Signup id => status.
     * @param int $actorid
     */
    public static function take_attendance(int $appointmentid, array $signuptostatus, int $actorid): void {
        global $DB;
        [$activity, $appointment] = self::load($appointmentid);
        $context = activity::context((int) $activity->id);
        require_capability('mod/muappointments:takeattendance', $context);
        $sessions = appointment_service::sessions($appointmentid);
        $started = false;
        $now = time();
        foreach ($sessions as $session) {
            if ((int) $session->timestart <= $now) {
                $started = true;
                break;
            }
        }
        if (!$started) {
            throw new \moodle_exception('attendancenotopen', 'mod_muappointments');
        }
        $changed = activity::lock((int) $activity->id, function () use ($appointmentid, $signuptostatus, $actorid) {
            global $DB;
            $rows = [];
            $allowed = array_merge([schedule::BOOKED], schedule::attendance_statuses());
            foreach ($signuptostatus as $signupid => $status) {
                if (!in_array($status, $allowed, true)) {
                    throw new \moodle_exception('invalidattendance', 'mod_muappointments');
                }
                $signup = $DB->get_record('muappointments_signup', ['id' => (int) $signupid], '*', MUST_EXIST);
                if ((int) $signup->appointmentid !== $appointmentid || !schedule::consumes_seat($signup->status)) {
                    throw new \moodle_exception('invalidattendance', 'mod_muappointments');
                }
                if ($signup->status === $status) {
                    continue;
                }
                $now = time();
                $DB->update_record('muappointments_signup', (object) [
                    'id' => $signup->id,
                    'status' => $status,
                    'timemodified' => $now,
                ]);
                self::history((int) $signup->id, $status, '', $actorid, $now);
                $signup->status = $status;
                $rows[] = $signup;
            }
            return $rows;
        });
        foreach ($changed as $signup) {
            self::update_completion((int) $activity->id, (int) $signup->userid);
            $event = \mod_muappointments\event\attendance_taken::create([
                'objectid' => $signup->id,
                'context' => $context,
                'relateduserid' => $signup->userid,
                'other' => [
                    'appointmentid' => $appointment->id,
                    'status' => $signup->status,
                ],
            ]);
            $event->trigger();
        }
    }

    /**
     * Promote up to $max wait-listed signups. Caller must already hold the activity lock.
     *
     * @param \stdClass $appointment
     * @param int $max
     * @return \stdClass[] Promoted signup rows (status booked).
     */
    public static function promote_unlocked(\stdClass $appointment, int $max): array {
        global $DB, $USER;
        if ($max <= 0 || appointment_service::sessions((int) $appointment->id) === []) {
            return [];
        }
        $free = max(0, (int) $appointment->capacity - self::count_booked((int) $appointment->id));
        $take = min($max, $free);
        if ($take <= 0) {
            return [];
        }
        $sql = "SELECT *
                  FROM {muappointments_signup}
                 WHERE appointmentid = :appointmentid
                   AND status = :status
              ORDER BY timecreated ASC, id ASC";
        $waiting = $DB->get_records_sql($sql, [
            'appointmentid' => $appointment->id,
            'status' => schedule::WAITLISTED,
        ], 0, $take);
        $actor = !empty($USER->id) ? (int) $USER->id : 0;
        $promoted = [];
        foreach ($waiting as $signup) {
            $now = time();
            $DB->update_record('muappointments_signup', (object) [
                'id' => $signup->id,
                'status' => schedule::BOOKED,
                'timemodified' => $now,
            ]);
            self::history((int) $signup->id, schedule::BOOKED, '', $actor, $now);
            $signup->status = schedule::BOOKED;
            $signup->timemodified = $now;
            $promoted[] = $signup;
        }
        return $promoted;
    }

    /**
     * @param int $appointmentid
     * @return int
     */
    public static function count_booked(int $appointmentid): int {
        global $DB;
        [$insql, $params] = $DB->get_in_or_equal(schedule::seat_statuses(), SQL_PARAMS_NAMED);
        $params['appointmentid'] = $appointmentid;
        return $DB->count_records_select(
            'muappointments_signup',
            "appointmentid = :appointmentid AND status $insql",
            $params
        );
    }

    /**
     * @param int $activityid
     * @param int $userid
     * @return \stdClass|null
     */
    public static function active_signup(int $activityid, int $userid): ?\stdClass {
        global $DB;
        $sql = "SELECT s.*
                  FROM {muappointments_signup} s
                  JOIN {muappointments_appointment} a ON a.id = s.appointmentid
                 WHERE a.muappointmentsid = :activityid
                   AND s.userid = :userid
                   AND s.status <> :cancelled";
        $rows = $DB->get_records_sql($sql, [
            'activityid' => $activityid,
            'userid' => $userid,
            'cancelled' => schedule::USERCANCELLED,
        ]);
        if (!$rows) {
            return null;
        }
        return reset($rows);
    }

    /**
     * Remaining seats on appointments that are still open for signup.
     *
     * @param int $activityid
     * @return int
     */
    public static function count_free_seats(int $activityid): int {
        global $DB;
        $appointments = $DB->get_records('muappointments_appointment', ['muappointmentsid' => $activityid]);
        $free = 0;
        $now = time();
        foreach ($appointments as $appointment) {
            $sessions = appointment_service::sessions((int) $appointment->id);
            $booked = self::count_booked((int) $appointment->id);
            $display = schedule::display_status($sessions, $booked, (int) $appointment->capacity, $now);
            if ($display === schedule::FINISHED || $display === schedule::INPROGRESS) {
                continue;
            }
            $free += max(0, (int) $appointment->capacity - $booked);
        }
        return $free;
    }

    /**
     * Next session that has not finished, for the course-page extra info.
     *
     * @param int $appointmentid
     * @return \stdClass|null
     */
    public static function next_session(int $appointmentid): ?\stdClass {
        $now = time();
        $chosen = null;
        foreach (appointment_service::sessions($appointmentid) as $session) {
            if ((int) $session->timefinish < $now) {
                continue;
            }
            if ($chosen === null || (int) $session->timestart < (int) $chosen->timestart) {
                $chosen = $session;
            }
        }
        return $chosen;
    }

    /**
     * @param int $activityid
     * @param int $userid
     */
    public static function update_completion(int $activityid, int $userid): void {
        $activity = activity::get($activityid);
        $course = get_course($activity->course);
        $cm = get_coursemodule_from_instance('muappointments', $activityid, $course->id, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) && (int) $cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
        }
    }

    /**
     * @param \stdClass $signup
     * @param int $activityid
     */
    public static function trigger_signup(\stdClass $signup, int $activityid): void {
        \mod_muappointments\event\signup_created::create([
            'objectid' => $signup->id,
            'context' => activity::context($activityid),
            'relateduserid' => $signup->userid,
            'other' => [
                'appointmentid' => $signup->appointmentid,
                'status' => $signup->status,
            ],
        ])->trigger();
    }

    /**
     * @param int $appointmentid
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private static function load(int $appointmentid): array {
        global $DB;
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $activity = activity::get((int) $appointment->muappointmentsid);
        return [$activity, $appointment];
    }

    /**
     * @param \stdClass $appointment
     * @param array $sessions
     * @return string
     */
    private static function display(\stdClass $appointment, array $sessions): string {
        return schedule::display_status(
            $sessions,
            self::count_booked((int) $appointment->id),
            (int) $appointment->capacity,
            time()
        );
    }

    /**
     * @param string $display
     */
    private static function assert_not_closed(string $display): void {
        if ($display === schedule::FINISHED || $display === schedule::INPROGRESS) {
            throw new \moodle_exception('signupclosed', 'mod_muappointments');
        }
    }

    /**
     * @param int $activityid
     * @param int $userid
     */
    private static function assert_no_other_active(int $activityid, int $userid): void {
        if (self::active_signup($activityid, $userid)) {
            throw new \moodle_exception('alreadybooked', 'mod_muappointments');
        }
    }

    /**
     * @param \context $context
     * @param int $userid
     */
    private static function assert_enrolled(\context $context, int $userid): void {
        if (!is_enrolled($context, $userid, '', true)) {
            throw new \moodle_exception('notenrolled', 'mod_muappointments');
        }
    }

    /**
     * @param int $appointmentid
     * @param int $userid
     * @param int $actorid
     * @param string $status
     * @return \stdClass
     */
    private static function insert(int $appointmentid, int $userid, int $actorid, string $status): \stdClass {
        global $DB;
        $now = time();
        $record = (object) [
            'appointmentid' => $appointmentid,
            'userid' => $userid,
            'status' => $status,
            'cancelnote' => '',
            'bookedby' => $actorid,
            'remindersent' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('muappointments_signup', $record);
        self::history((int) $record->id, $status, '', $actorid, $now);
        return $record;
    }

    /**
     * @param int $signupid
     * @param string $status
     * @param string $note
     * @param int $actorid
     * @param int $time
     */
    private static function history(int $signupid, string $status, string $note, int $actorid, int $time): void {
        global $DB;
        $DB->insert_record('muappointments_status', (object) [
            'signupid' => $signupid,
            'status' => $status,
            'note' => $note,
            'timecreated' => $time,
            'userid' => $actorid,
        ]);
    }

    /**
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param \stdClass $signup
     * @param bool $suppressemail
     */
    private static function after_signup(
        \stdClass $activity,
        \stdClass $appointment,
        \stdClass $signup,
        bool $suppressemail
    ): void {
        $sessions = appointment_service::sessions((int) $appointment->id);
        calendar_service::sync_appointment((int) $appointment->id);
        if (!$suppressemail) {
            $template = $signup->status === schedule::WAITLISTED ? 'waitlisted' : 'confirmation';
            notification_service::send($template, $activity, $appointment, $sessions, $signup);
        }
        self::update_completion((int) $activity->id, (int) $signup->userid);
        self::trigger_signup($signup, (int) $activity->id);
    }

    /**
     * @param \stdClass $signup Snapshot from before the status change.
     * @param int $activityid
     * @param int $actorid
     */
    private static function trigger_cancelled(\stdClass $signup, int $activityid, int $actorid): void {
        \mod_muappointments\event\signup_cancelled::create([
            'objectid' => $signup->id,
            'context' => activity::context($activityid),
            'relateduserid' => $signup->userid,
            'other' => [
                'appointmentid' => $signup->appointmentid,
                'actorid' => $actorid,
            ],
        ])->trigger();
    }
}
