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
 * Template data for the activity page and the attendee screens.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

use mod_muappointments\customfield\appointment_handler;

/**
 * Build render arrays.
 */
class view_data {
    /**
     * @param \stdClass $activity
     * @param \stdClass $cm
     * @param int $userid
     * @return array
     */
    public static function activity(\stdClass $activity, \stdClass $cm, int $userid): array {
        global $DB, $OUTPUT;
        $context = \context_module::instance($cm->id);
        $canedit = has_capability('mod/muappointments:editsessions', $context);
        $canviewattendees = has_capability('mod/muappointments:viewattendees', $context);
        $canadd = has_capability('mod/muappointments:addattendees', $context);
        $cantake = has_capability('mod/muappointments:takeattendance', $context);
        $cansignup = has_capability('mod/muappointments:signup', $context);
        $canoverbook = has_capability('mod/muappointments:overbook', $context);
        $manageurl = '';
        if (has_capability('mod/muappointments:managecustomfields', \context_system::instance())) {
            $manageurl = (new \moodle_url('/mod/muappointments/customfield.php'))->out(false);
        }
        $appointments = $DB->get_records('muappointments_appointment', ['muappointmentsid' => $activity->id], 'id ASC');
        $mine = signup_service::active_signup((int) $activity->id, $userid);
        $handler = appointment_handler::create();
        $rows = [];
        $now = time();
        foreach ($appointments as $appointment) {
            $sessions = appointment_service::sessions((int) $appointment->id);
            $booked = signup_service::count_booked((int) $appointment->id);
            $display = schedule::display_status($sessions, $booked, (int) $appointment->capacity, $now);
            $fields = [];
            foreach ($handler->get_instance_data((int) $appointment->id, true) as $data) {
                if (!$handler->can_view($data->get_field(), (int) $appointment->id)) {
                    continue;
                }
                $value = trim(strip_tags((string) $data->export_value()));
                if ($value === '') {
                    continue;
                }
                $fields[] = [
                    'name' => $data->get_field()->get_formatted_name(),
                    'value' => $value,
                ];
            }
            $sessionlabels = [];
            foreach ($sessions as $session) {
                $sessionlabels[] = [
                    'label' => userdate((int) $session->timestart, get_string('strftimedatetime', 'langconfig'))
                        . ' - ' . userdate((int) $session->timefinish, get_string('strftimetime', 'langconfig')),
                ];
            }
            $description = '';
            if (!empty($appointment->description)) {
                $description = format_text($appointment->description, (int) $appointment->descriptionformat, [
                    'context' => $context,
                ]);
            }
            $actions = [];
            $mysignup = null;
            if ($mine && (int) $mine->appointmentid === (int) $appointment->id) {
                $mysignup = $mine;
            }
            if ($cansignup && !$mine && $display !== schedule::FINISHED && $display !== schedule::INPROGRESS) {
                $bookurl = new \moodle_url('/mod/muappointments/view.php', [
                    'id' => $cm->id,
                    'book' => $appointment->id,
                    'sesskey' => sesskey(),
                ]);
                if ($sessions === [] && ($display === schedule::OPEN || !empty($appointment->waitlist))) {
                    $actions[] = ['html' => $OUTPUT->action_link(
                        $bookurl,
                        get_string('registerinterest', 'mod_muappointments'),
                        null,
                        ['class' => 'btn btn-primary btn-sm']
                    )];
                } else if ($display === schedule::OPEN) {
                    $actions[] = ['html' => $OUTPUT->action_link(
                        $bookurl,
                        get_string('book', 'mod_muappointments'),
                        null,
                        ['class' => 'btn btn-primary btn-sm']
                    )];
                } else if ($display === schedule::FULL && !empty($appointment->waitlist)) {
                    $actions[] = ['html' => $OUTPUT->action_link(
                        $bookurl,
                        get_string('joinwaitlist', 'mod_muappointments'),
                        null,
                        ['class' => 'btn btn-primary btn-sm']
                    )];
                } else if ($display === schedule::FULL && empty($appointment->waitlist) && $canoverbook) {
                    $overurl = new \moodle_url('/mod/muappointments/view.php', [
                        'id' => $cm->id,
                        'overbook' => $appointment->id,
                        'sesskey' => sesskey(),
                    ]);
                    $actions[] = ['html' => $OUTPUT->action_link(
                        $overurl,
                        get_string('overbook', 'mod_muappointments'),
                        new \confirm_action(get_string('confirmoverbook', 'mod_muappointments')),
                        ['class' => 'btn btn-primary btn-sm']
                    )];
                }
            }
            if ($mysignup && !empty($appointment->allowcancel) && $cansignup && (int) $mysignup->userid === $userid) {
                $actions[] = self::button('cancel', (int) $appointment->id, get_string('cancel'), 0, (int) $mysignup->id);
            }
            if ($canedit) {
                $actions[] = self::button('edit', (int) $appointment->id, get_string('edit'));
                $actions[] = self::button('duplicate', (int) $appointment->id, get_string('duplicate', 'mod_muappointments'));
                $deleteurl = new \moodle_url('/mod/muappointments/view.php', [
                    'id' => $cm->id,
                    'delete' => $appointment->id,
                    'sesskey' => sesskey(),
                ]);
                $actions[] = [
                    'html' => $OUTPUT->action_link(
                        $deleteurl,
                        get_string('delete'),
                        new \confirm_action(get_string('confirmdeleteappointment', 'mod_muappointments')),
                        ['class' => 'btn btn-outline-danger btn-sm']
                    ),
                ];
            }
            if ($canadd && $display !== schedule::FINISHED && $display !== schedule::INPROGRESS) {
                $actions[] = self::button('addattendees', (int) $appointment->id, get_string('addattendees', 'mod_muappointments'));
            }
            if ($canviewattendees) {
                $url = new \moodle_url('/mod/muappointments/attendees.php', [
                    'id' => $cm->id,
                    'appointmentid' => $appointment->id,
                ]);
                $actions[] = ['html' => \html_writer::link($url, get_string('attendees', 'mod_muappointments'), ['class' => 'btn btn-link btn-sm'])];
            }
            $started = false;
            foreach ($sessions as $session) {
                if ((int) $session->timestart <= $now) {
                    $started = true;
                }
            }
            if ($cantake && $started) {
                $actions[] = self::button('attendance', (int) $appointment->id, get_string('takeattendance', 'mod_muappointments'));
            }
            $rows[] = [
                'id' => $appointment->id,
                'when' => $sessionlabels ? $sessionlabels[0]['label'] : get_string('nodate', 'mod_muappointments'),
                'sessioncount' => count($sessions),
                'booked' => $booked,
                'capacity' => (int) $appointment->capacity,
                'statuslabel' => get_string('display:' . $display, 'mod_muappointments'),
                'description' => $description,
                'fields' => $fields,
                'sessions' => $sessionlabels,
                'actions' => $actions,
                'mystatus' => $mysignup ? get_string('status:' . $mysignup->status, 'mod_muappointments') : '',
            ];
        }
        return [
            'canedit' => $canedit,
            'managefieldsurl' => $manageurl,
            'cancellationsurl' => has_capability('mod/muappointments:viewcancellations', $context)
                ? (new \moodle_url('/mod/muappointments/cancellations.php', ['id' => $cm->id]))->out(false) : '',
            'appointments' => $rows,
            'hasappointments' => !empty($rows),
        ];
    }

    /**
     * Self-service book and overbook are buttons handled without a form.
     *
     * @param string $action
     * @param int $appointmentid
     * @param string $label
     * @param int $signupid
     * @return array
     */
    private static function button(string $action, int $appointmentid, string $label, int $unused = 0, int $signupid = 0): array {
        $attributes = [
            'type' => 'button',
            'class' => 'btn btn-secondary btn-sm',
            'data-action' => $action,
            'data-appointmentid' => $appointmentid,
            'data-title' => $label,
        ];
        if ($signupid) {
            $attributes['data-signupid'] = $signupid;
        }
        return ['html' => \html_writer::tag('button', $label, $attributes)];
    }
}
