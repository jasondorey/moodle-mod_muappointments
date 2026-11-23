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
 * Cancellation history. History keeps a row after the person books again.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_muappointments\local\schedule;

$id = required_param('id', PARAM_INT);
$cm = get_coursemodule_from_id('muappointments', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/muappointments:viewcancellations', $context);

$PAGE->set_url('/mod/muappointments/cancellations.php', ['id' => $cm->id]);
$PAGE->set_title(get_string('cancellations', 'mod_muappointments'));
$PAGE->set_heading(format_string($course->fullname));

$sql = "SELECT h.id, h.note, h.timecreated, h.userid AS actorid, s.userid, s.appointmentid
          FROM {muappointments_status} h
          JOIN {muappointments_signup} s ON s.id = h.signupid
          JOIN {muappointments_appointment} a ON a.id = s.appointmentid
         WHERE a.muappointmentsid = :instance
           AND h.status = :cancelled
      ORDER BY h.timecreated DESC";
$records = $DB->get_records_sql($sql, [
    'instance' => $cm->instance,
    'cancelled' => schedule::USERCANCELLED,
]);
$rows = [];
foreach ($records as $record) {
    $user = $DB->get_record('user', ['id' => $record->userid]);
    $actor = $record->actorid ? $DB->get_record('user', ['id' => $record->actorid]) : null;
    $rows[] = [
        'name' => $user ? fullname($user) : '',
        'note' => format_string($record->note ?? ''),
        'time' => userdate((int) $record->timecreated),
        'actor' => $actor ? fullname($actor) : '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('cancellations', 'mod_muappointments'));
echo $OUTPUT->render_from_template('mod_muappointments/cancellations', [
    'rows' => $rows,
    'hasrows' => !empty($rows),
]);
echo $OUTPUT->footer();
