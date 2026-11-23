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

namespace mod_muappointments;

use mod_muappointments\local\appointment_service;
use mod_muappointments\local\calendar_service;
use mod_muappointments\local\schedule;
use mod_muappointments\local\signup_service;

/**
 * User events are omitted when the activity says not to show them.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_muappointments\local\calendar_service
 */
final class calendar_test extends \advanced_testcase {
    /**
     * Course events stay. User events are created only when the checkbox is on, and not for the wait list.
     */
    public function test_duplicate_suppression(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('noemailever');
        $this->redirectEmails();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $waiting = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($waiting->id, $course->id, 'student');
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance([
            'course' => $course->id,
            'calendardisplay' => 'course',
            'showoncalendar' => 0,
        ]);
        $start = time() + (2 * DAYSECS);
        $appointmentid = appointment_service::create((int) $instance->id, 1, true, true, '', FORMAT_HTML, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $this->setUser($student);
        signup_service::book($appointmentid, (int) $student->id);
        $this->setUser($waiting);
        $wait = signup_service::book($appointmentid, (int) $waiting->id);
        $this->assertSame(schedule::WAITLISTED, $wait->status);

        $userevents = $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
            'userid' => $student->id,
        ]);
        $courseevents = $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
            'eventtype' => 'course',
        ]);
        $waitevents = $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
            'userid' => $waiting->id,
        ]);
        $this->assertSame(0, $userevents);
        $this->assertSame(1, $courseevents);
        $this->assertSame(0, $waitevents);

        $DB->set_field('muappointments', 'showoncalendar', 1, ['id' => $instance->id]);
        calendar_service::sync_appointment($appointmentid);
        $userevents = $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
            'userid' => $student->id,
        ]);
        $waitevents = $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
            'userid' => $waiting->id,
        ]);
        $this->assertSame(1, $userevents);
        $this->assertSame(0, $waitevents);

        $DB->set_field('muappointments', 'calendardisplay', 'none', ['id' => $instance->id]);
        $DB->set_field('muappointments', 'showoncalendar', 0, ['id' => $instance->id]);
        calendar_service::sync_appointment($appointmentid);
        $this->assertSame(0, $DB->count_records('event', [
            'modulename' => 'muappointments',
            'instance' => $instance->id,
        ]));
    }
}
