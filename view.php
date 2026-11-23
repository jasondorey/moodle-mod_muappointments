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
 * Activity view.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_muappointments\local\appointment_service;
use mod_muappointments\local\signup_service;
use mod_muappointments\local\view_data;

$id = required_param('id', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$book = optional_param('book', 0, PARAM_INT);
$overbook = optional_param('overbook', 0, PARAM_INT);

$cm = get_coursemodule_from_id('muappointments', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$activity = $DB->get_record('muappointments', ['id' => $cm->instance], '*', MUST_EXIST);
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/muappointments:view', $context);

$PAGE->set_url('/mod/muappointments/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));

$return = new moodle_url('/mod/muappointments/view.php', ['id' => $cm->id]);

if ($delete && confirm_sesskey()) {
    require_capability('mod/muappointments:editsessions', $context);
    appointment_service::delete($delete, true);
    redirect($return);
}
if ($book && confirm_sesskey()) {
    try {
        signup_service::book($book, (int) $USER->id);
        redirect($return, get_string('signupsaved', 'mod_muappointments'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($return, $e->getMessage(), 0, \core\output\notification::NOTIFY_ERROR);
    }
}
if ($overbook && confirm_sesskey()) {
    try {
        signup_service::overbook($overbook, (int) $USER->id);
        redirect($return, get_string('signupsaved', 'mod_muappointments'), 0, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        redirect($return, $e->getMessage(), 0, \core\output\notification::NOTIFY_ERROR);
    }
}

$PAGE->requires->js_call_amd('mod_muappointments/view', 'init', [$cm->id]);
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_muappointments/view', view_data::activity($activity, $cm, (int) $USER->id));
echo $OUTPUT->footer();
