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
 * Shared and per-user calendar events.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

/**
 * Keep core calendar rows aligned with sessions and signups.
 */
class calendar_service {
    /**
     * Rebuild shared and user events for one appointment.
     *
     * @param int $appointmentid
     */
    public static function sync_appointment(int $appointmentid): void {
        global $DB;
        $appointment = $DB->get_record('muappointments_appointment', ['id' => $appointmentid]);
        if (!$appointment) {
            return;
        }
        $activity = activity::get((int) $appointment->muappointmentsid);
        $sessions = $DB->get_records('muappointments_session', ['appointmentid' => $appointmentid], 'timestart ASC');
        self::sync_shared($activity, $sessions);
        $signups = $DB->get_records('muappointments_signup', ['appointmentid' => $appointmentid]);
        foreach ($signups as $signup) {
            self::sync_user($activity, $signup, $sessions);
        }
    }

    /**
     * Rebuild every appointment in the activity. Used when the activity calendar settings change.
     *
     * @param int $activityid
     */
    public static function sync_activity(int $activityid): void {
        global $DB;
        $ids = $DB->get_fieldset_select('muappointments_appointment', 'id', 'muappointmentsid = ?', [$activityid]);
        foreach ($ids as $id) {
            self::sync_appointment((int) $id);
        }
    }

    /**
     * @param int $eventid
     */
    public static function delete_event(int $eventid): void {
        if ($eventid <= 0) {
            return;
        }
        try {
            $event = \calendar_event::load($eventid);
            $event->delete(true);
        } catch (\Exception $e) {
            // The calendar row is already gone.
            return;
        }
    }

    /**
     * @param \stdClass $activity
     * @param array $sessions
     */
    private static function sync_shared(\stdClass $activity, array $sessions): void {
        global $DB;
        $display = $activity->calendardisplay;
        foreach ($sessions as $session) {
            if ($display === 'none') {
                if (!empty($session->eventid)) {
                    self::delete_event((int) $session->eventid);
                    $DB->set_field('muappointments_session', 'eventid', 0, ['id' => $session->id]);
                    $session->eventid = 0;
                }
                continue;
            }
            $data = self::event_data($activity, $session, $display, 0);
            $session->eventid = self::upsert((int) $session->eventid, $data);
            $DB->set_field('muappointments_session', 'eventid', $session->eventid, ['id' => $session->id]);
        }
    }

    /**
     * @param \stdClass $activity
     * @param \stdClass $signup
     * @param array $sessions
     */
    public static function sync_user(\stdClass $activity, \stdClass $signup, array $sessions): void {
        global $DB;
        $existing = $DB->get_records('muappointments_userevent', ['signupid' => $signup->id]);
        $bySession = [];
        foreach ($existing as $row) {
            $bySession[$row->sessionid] = $row;
        }
        $wanted = !empty($activity->showoncalendar) && schedule::has_user_event($signup->status);
        $keep = [];
        if ($wanted) {
            foreach ($sessions as $session) {
                $data = self::event_data($activity, $session, 'user', (int) $signup->userid);
                $current = $bySession[$session->id] ?? null;
                $eventid = self::upsert($current ? (int) $current->eventid : 0, $data);
                if ($current) {
                    if ((int) $current->eventid !== $eventid) {
                        $DB->set_field('muappointments_userevent', 'eventid', $eventid, ['id' => $current->id]);
                    }
                    $keep[] = $current->id;
                } else {
                    $keep[] = $DB->insert_record('muappointments_userevent', (object) [
                        'signupid' => $signup->id,
                        'sessionid' => $session->id,
                        'eventid' => $eventid,
                    ]);
                }
            }
        }
        foreach ($existing as $row) {
            if (!in_array($row->id, $keep, true)) {
                self::delete_event((int) $row->eventid);
                $DB->delete_records('muappointments_userevent', ['id' => $row->id]);
            }
        }
    }

    /**
     * @param int $eventid
     * @param \stdClass $data
     * @return int
     */
    private static function upsert(int $eventid, \stdClass $data): int {
        if ($eventid > 0) {
            try {
                $event = \calendar_event::load($eventid);
                $event->update($data, false);
                return $eventid;
            } catch (\Exception $e) {
                $eventid = 0;
            }
        }
        $created = \calendar_event::create($data, false);
        return (int) $created->id;
    }

    /**
     * @param \stdClass $activity
     * @param \stdClass $session
     * @param string $display site, course, or user.
     * @param int $userid
     * @return \stdClass
     */
    private static function event_data(\stdClass $activity, \stdClass $session, string $display, int $userid): \stdClass {
        $cm = activity::cm((int) $activity->id);
        $name = $activity->shortname !== '' ? $activity->shortname : $activity->name;
        $event = new \stdClass();
        $event->name = format_string($name);
        $event->description = \html_writer::link(
            new \moodle_url('/mod/muappointments/view.php', ['id' => $cm->id]),
            format_string($activity->name)
        );
        $event->format = FORMAT_HTML;
        $event->groupid = 0;
        $event->repeatid = 0;
        $event->modulename = 'muappointments';
        $event->instance = $activity->id;
        $event->timestart = (int) $session->timestart;
        $event->timeduration = max(0, (int) $session->timefinish - (int) $session->timestart);
        $event->timesort = (int) $session->timestart;
        $event->visible = instance_is_visible('muappointments', $activity);
        $event->type = CALENDAR_EVENT_TYPE_STANDARD;
        if ($display === 'site') {
            $event->eventtype = 'site';
            $event->courseid = SITEID;
            $event->userid = 0;
        } else if ($display === 'user') {
            $event->eventtype = 'user';
            $event->courseid = 0;
            $event->userid = $userid;
        } else {
            $event->eventtype = 'course';
            $event->courseid = $activity->course;
            $event->userid = 0;
        }
        return $event;
    }
}
