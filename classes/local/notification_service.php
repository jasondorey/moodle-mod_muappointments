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
 * Templates, placeholders, iCalendar attachments, and the reminder run.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

use mod_muappointments\customfield\appointment_handler;

/**
 * Outbound mail for one signup.
 */
class notification_service {
    /**
     * Store the five templates from the activity form, or the language-pack defaults.
     *
     * @param \stdClass $data Form data that includes the activity id.
     */
    public static function save_from_form(\stdClass $data): void {
        global $DB;
        $activityid = (int) ($data->id ?? 0);
        if ($activityid <= 0) {
            return;
        }
        foreach (schedule::TEMPLATES as $template) {
            $subjectkey = 'notify_' . $template . '_subject';
            $bodykey = 'notify_' . $template . '_body';
            $subject = trim((string) ($data->$subjectkey ?? ''));
            $body = (string) ($data->$bodykey ?? '');
            if ($subject === '' && trim($body) === '') {
                $subject = get_string('default:' . $template . ':subject', 'mod_muappointments');
                $body = get_string('default:' . $template . ':body', 'mod_muappointments');
            }
            $existing = $DB->get_record('muappointments_notify', [
                'muappointmentsid' => $activityid,
                'template' => $template,
            ]);
            $record = (object) [
                'muappointmentsid' => $activityid,
                'template' => $template,
                'subject' => \core_text::substr($subject, 0, 255),
                'body' => $body,
            ];
            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('muappointments_notify', $record);
            } else {
                $DB->insert_record('muappointments_notify', $record);
            }
        }
    }

    /**
     * @param int $activityid
     * @param string $template
     * @return \stdClass subject and body.
     */
    public static function template(int $activityid, string $template): \stdClass {
        global $DB;
        $row = $DB->get_record('muappointments_notify', [
            'muappointmentsid' => $activityid,
            'template' => $template,
        ]);
        if ($row) {
            return $row;
        }
        return (object) [
            'subject' => get_string('default:' . $template . ':subject', 'mod_muappointments'),
            'body' => get_string('default:' . $template . ':body', 'mod_muappointments'),
        ];
    }

    /**
     * Send one template for a signup.
     *
     * Third parties receive confirmation, update, and cancellation for seat-holding signups.
     * They receive wait-list mail only when the activity flag is on. They never receive reminders.
     *
     * @param string $template
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param array $sessions
     * @param \stdClass $signup
     */
    public static function send(
        string $template,
        \stdClass $activity,
        \stdClass $appointment,
        array $sessions,
        \stdClass $signup
    ): void {
        global $DB;
        if ($template === 'reminder') {
            if ($signup->status !== schedule::BOOKED || !empty($signup->remindersent)) {
                return;
            }
        }
        $user = $DB->get_record('user', ['id' => $signup->userid, 'deleted' => 0]);
        if (!$user) {
            return;
        }
        $includeuser = true;
        $includethird = $template !== 'reminder' && self::third_party_wants($template, $activity, $signup);
        self::deliver($template, $activity, $appointment, $sessions, $signup, $user, $includeuser, $includethird);
        if ($template === 'reminder') {
            $DB->set_field('muappointments_signup', 'remindersent', time(), ['id' => $signup->id]);
        }
    }

    /**
     * Daily reminder pass. Safe to run again: the signup stores the sent time.
     */
    public static function send_due_reminders(): void {
        global $DB;
        $sql = "SELECT s.*, a.muappointmentsid
                  FROM {muappointments_signup} s
                  JOIN {muappointments_appointment} a ON a.id = s.appointmentid
                 WHERE s.status = :booked
                   AND s.remindersent = 0";
        $signups = $DB->get_records_sql($sql, ['booked' => schedule::BOOKED]);
        $now = time();
        foreach ($signups as $signup) {
            $sessions = $DB->get_records('muappointments_session', ['appointmentid' => $signup->appointmentid], 'timestart ASC');
            $earliest = null;
            foreach ($sessions as $session) {
                if ($earliest === null || (int) $session->timestart < $earliest) {
                    $earliest = (int) $session->timestart;
                }
            }
            $activity = activity::get((int) $signup->muappointmentsid);
            if (!schedule::reminder_due($now, $earliest, (int) $activity->reminderperiod, (int) $signup->remindersent)) {
                continue;
            }
            $appointment = $DB->get_record('muappointments_appointment', ['id' => $signup->appointmentid], '*', MUST_EXIST);
            self::send('reminder', $activity, $appointment, $sessions, $signup);
        }
    }

    /**
     * @param string $template
     * @param \stdClass $activity
     * @param \stdClass $signup
     * @return bool
     */
    private static function third_party_wants(string $template, \stdClass $activity, \stdClass $signup): bool {
        if (trim((string) $activity->thirdpartyemails) === '') {
            return false;
        }
        if ($template === 'waitlisted' || $signup->status === schedule::WAITLISTED) {
            return !empty($activity->thirdpartywaitlist);
        }
        return schedule::consumes_seat($signup->status);
    }

    /**
     * Cancellation of a wait-listed signup is not a booked signup. Gate it on the flag.
     * Cancellation of a seat-holding signup always goes to third parties.
     * The check above treats cancellation of a wait-listed row (status already changed
     * to usercancelled) as a booked-signup cancellation. Callers pass the pre-cancel
     * status on the signup clone when the row was wait-listed, via $signup->status.
     *
     * @param string $template
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param array $sessions
     * @param \stdClass $signup Status is the status the message is about.
     * @param \stdClass $user
     * @param bool $includeuser
     * @param bool $includethird
     */
    private static function deliver(
        string $template,
        \stdClass $activity,
        \stdClass $appointment,
        array $sessions,
        \stdClass $signup,
        \stdClass $user,
        bool $includeuser,
        bool $includethird
    ): void {
        $perday = (bool) get_config('mod_muappointments', 'separateemailperday');
        $method = $template === 'cancellation' ? 'CANCEL' : 'REQUEST';
        $recipients = [];
        if ($includeuser) {
            $recipients[] = $user;
        }
        if ($includethird) {
            foreach (self::addresses($activity->thirdpartyemails) as $email) {
                $recipients[] = self::third_party_user($email);
            }
        }
        foreach ($recipients as $recipient) {
            $groups = self::session_groups($sessions, $recipient, $perday);
            if ($groups === []) {
                $groups = [[]];
            }
            foreach ($groups as $group) {
                self::send_one(
                    $template,
                    $activity,
                    $appointment,
                    $group,
                    $sessions,
                    $signup,
                    $user,
                    $recipient,
                    $method
                );
            }
        }
    }

    /**
     * @param string $raw
     * @return string[]
     */
    public static function addresses(string $raw): array {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email !== '' && validate_email($email)) {
                $emails[] = \core_text::strtolower($email);
            }
        }
        return array_values(array_unique($emails));
    }

    /**
     * One message, optionally covering a single calendar day.
     *
     * @param string $template
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param array $group Sessions included in this message.
     * @param array $allsessions Every session, used when per-day splitting is off.
     * @param \stdClass $signup
     * @param \stdClass $attendee The person the placeholders describe.
     * @param \stdClass $recipient
     * @param string $method REQUEST or CANCEL.
     */
    private static function send_one(
        string $template,
        \stdClass $activity,
        \stdClass $appointment,
        array $group,
        array $allsessions,
        \stdClass $signup,
        \stdClass $attendee,
        \stdClass $recipient,
        string $method
    ): void {
        $stored = self::template((int) $activity->id, $template);
        $tz = \core_date::get_user_timezone($recipient);
        $sessions = $group ?: $allsessions;
        $map = self::placeholders($activity, $appointment, $sessions, $attendee, $tz);
        $subject = trim(html_to_text(strtr($stored->subject, $map), 0, false));
        $rawbody = strtr($stored->body, $map);
        $rawbody = preg_replace('/\[session:[a-z][a-z0-9_]*\]/', '', $rawbody);
        $context = activity::context((int) $activity->id);
        $html = format_text($rawbody, FORMAT_MOODLE, ['context' => $context, 'filter' => true]);
        $text = html_to_text($html, 0, false);
        $attachment = '';
        $attachname = '';
        $ical = self::ical_for($activity, $sessions, $text, $method);
        if ($ical !== '') {
            $dir = make_request_directory();
            $attachment = $dir . '/appointment.ics';
            file_put_contents($attachment, $ical);
            $attachname = 'appointment.ics';
        }
        $from = \core_user::get_noreply_user();
        email_to_user($recipient, $from, $subject, $text, $html, $attachment, $attachname);
    }

    /**
     * @param \stdClass $activity
     * @param \stdClass $appointment
     * @param array $sessions Sessions this message covers. Earliest drives the single-session placeholders.
     * @param \stdClass $attendee
     * @param string $timezone
     * @return array<string, string>
     */
    public static function placeholders(
        \stdClass $activity,
        \stdClass $appointment,
        array $sessions,
        \stdClass $attendee,
        string $timezone
    ): array {
        $earliest = null;
        foreach ($sessions as $session) {
            if ($earliest === null || (int) $session->timestart < (int) $earliest->timestart) {
                $earliest = $session;
            }
        }
        $dates = [];
        foreach ($sessions as $session) {
            $dates[] = userdate((int) $session->timestart, get_string('strftimedatetime', 'langconfig'), $timezone)
                . ' - '
                . userdate((int) $session->timefinish, get_string('strftimetime', 'langconfig'), $timezone);
        }
        $cm = activity::cm((int) $activity->id);
        $attendeesurl = new \moodle_url('/mod/muappointments/attendees.php', [
            'id' => $cm->id,
            'appointmentid' => $appointment->id,
        ]);
        $context = \context_module::instance($cm->id);
        $details = '';
        if (!empty($appointment->description)) {
            $details = trim(html_to_text(format_text(
                $appointment->description,
                $appointment->descriptionformat ?? FORMAT_HTML,
                ['context' => $context, 'filter' => false]
            ), 0, false));
        }
        $map = [
            '[alldates]' => implode("\n", $dates),
            '[appointmentname]' => format_string($activity->name),
            '[attendeeslink]' => $attendeesurl->out(false),
            '[details]' => $details,
            '[duration]' => $earliest ? format_time((int) $earliest->timefinish - (int) $earliest->timestart) : '',
            '[finishtime]' => $earliest
                ? userdate((int) $earliest->timefinish, get_string('strftimetime', 'langconfig'), $timezone) : '',
            '[firstname]' => $attendee->firstname ?? '',
            '[lastname]' => $attendee->lastname ?? '',
            '[reminderperiod]' => (string) $activity->reminderperiod,
            '[sessiondate]' => $earliest
                ? userdate((int) $earliest->timestart, get_string('strftimedate', 'langconfig'), $timezone) : '',
            '[starttime]' => $earliest
                ? userdate((int) $earliest->timestart, get_string('strftimetime', 'langconfig'), $timezone) : '',
        ];
        $handler = appointment_handler::create();
        $datarecords = $handler->get_instance_data((int) $appointment->id, true);
        foreach ($datarecords as $data) {
            $shortname = $data->get_field()->get('shortname');
            $value = trim((string) $data->get_value());
            $map['[session:' . $shortname . ']'] = $value;
        }
        return $map;
    }

    /**
     * @param array $sessions
     * @param \stdClass $user
     * @param bool $perday
     * @return array<int, array>
     */
    private static function session_groups(array $sessions, \stdClass $user, bool $perday): array {
        if (!$sessions) {
            return [];
        }
        if (!$perday) {
            return [array_values($sessions)];
        }
        $tz = \core_date::get_user_timezone($user);
        $groups = [];
        foreach ($sessions as $session) {
            $day = userdate((int) $session->timestart, '%Y-%m-%d', $tz);
            $groups[$day][] = $session;
        }
        return array_values($groups);
    }

    /**
     * @param \stdClass $activity
     * @param array $sessions
     * @param string $description
     * @param string $method
     * @return string
     */
    private static function ical_for(\stdClass $activity, array $sessions, string $description, string $method): string {
        if (!$sessions) {
            return '';
        }
        $name = $activity->shortname !== '' ? $activity->shortname : $activity->name;
        $events = [];
        foreach ($sessions as $session) {
            $events[] = [
                'uid' => icalendar::uid((int) $session->id),
                'start' => (int) $session->timestart,
                'end' => (int) $session->timefinish,
                'summary' => format_string($name),
                'description' => $description,
                'sequence' => (int) ($session->timemodified ?: time()),
            ];
        }
        return icalendar::build($events, $method);
    }

    /**
     * A user-shaped object with an address and no account.
     *
     * @param string $email
     * @return \stdClass
     */
    private static function third_party_user(string $email): \stdClass {
        $user = new \stdClass();
        $user->id = -1;
        $user->email = $email;
        $user->firstname = $email;
        $user->lastname = '.';
        $user->middlename = '';
        $user->firstnamephonetic = '';
        $user->lastnamephonetic = '';
        $user->alternatename = '';
        $user->maildisplay = \core_user::MAILDISPLAY_EVERYONE;
        $user->mailformat = 1;
        $user->deleted = 0;
        $user->suspended = 0;
        $user->emailstop = 0;
        $user->auth = 'manual';
        $user->username = $email;
        $user->timezone = '99';
        return $user;
    }
}
