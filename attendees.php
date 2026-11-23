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
 * Attendee list for one appointment.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_muappointments\local\schedule;

$id = required_param('id', PARAM_INT);
$appointmentid = required_param('appointmentid', PARAM_INT);

$cm = get_coursemodule_from_id('muappointments', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/muappointments:viewattendees', $context);

$appointment = $DB->get_record('muappointments_appointment', [
    'id' => $appointmentid,
    'muappointmentsid' => $cm->instance,
], '*', MUST_EXIST);

$PAGE->set_url('/mod/muappointments/attendees.php', ['id' => $cm->id, 'appointmentid' => $appointmentid]);
$PAGE->set_title(get_string('attendees', 'mod_muappointments'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->js_call_amd('mod_muappointments/view', 'init', [$cm->id]);

[$insql, $params] = $DB->get_in_or_equal(array_merge(schedule::seat_statuses(), [schedule::WAITLISTED]), SQL_PARAMS_NAMED);
$params['appointmentid'] = $appointmentid;
$signups = $DB->get_records_select('muappointments_signup', "appointmentid = :appointmentid AND status $insql", $params, 'timecreated ASC');

$rows = [];
$canremove = has_capability('mod/muappointments:removeattendees', $context);
$cantake = has_capability('mod/muappointments:takeattendance', $context);
foreach ($signups as $signup) {
    $user = $DB->get_record('user', ['id' => $signup->userid]);
    $bookedbyuser = $signup->bookedby ? $DB->get_record('user', ['id' => $signup->bookedby]) : null;
    $bookedby = $bookedbyuser ? fullname($bookedbyuser) : '';
    $actions = '';
    if ($canremove && $signup->status !== schedule::USERCANCELLED) {
        $actions = html_writer::tag('button', get_string('remove', 'mod_muappointments'), [
            'type' => 'button',
            'class' => 'btn btn-outline-danger btn-sm',
            'data-action' => 'cancel',
            'data-signupid' => $signup->id,
            'data-appointmentid' => $appointmentid,
            'data-title' => get_string('remove', 'mod_muappointments'),
        ]);
    }
    $rows[] = [
        'name' => $user ? fullname($user) : '',
        'email' => $user->email ?? '',
        'status' => get_string('status:' . $signup->status, 'mod_muappointments'),
        'time' => userdate((int) $signup->timecreated),
        'bookedby' => $bookedby,
        'actions' => $actions,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attendees', 'mod_muappointments'));
if ($cantake) {
    echo html_writer::tag('button', get_string('takeattendance', 'mod_muappointments'), [
        'type' => 'button',
        'class' => 'btn btn-primary mb-3',
        'data-action' => 'attendance',
        'data-appointmentid' => $appointmentid,
        'data-title' => get_string('takeattendance', 'mod_muappointments'),
    ]);
}
echo $OUTPUT->render_from_template('mod_muappointments/attendees', [
    'rows' => $rows,
    'hasrows' => !empty($rows),
]);
echo $OUTPUT->footer();
