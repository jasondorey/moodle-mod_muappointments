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
 * List of appointment activities in a course.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = get_course($id);
require_login($course);
$PAGE->set_url('/mod/muappointments/index.php', ['id' => $id]);
$PAGE->set_title(get_string('modulenameplural', 'mod_muappointments'));
$PAGE->set_heading(format_string($course->fullname));

$instances = get_all_instances_in_course('muappointments', $course);
$table = new html_table();
$table->head = [get_string('name')];
foreach ($instances as $instance) {
    if (!$instance->visible && !has_capability('moodle/course:viewhiddenactivities', context_course::instance($course->id))) {
        continue;
    }
    $url = new moodle_url('/mod/muappointments/view.php', ['id' => $instance->coursemodule]);
    $table->data[] = [html_writer::link($url, format_string($instance->name))];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_muappointments'));
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noappointments', 'mod_muappointments'), 'info');
}
echo $OUTPUT->footer();
