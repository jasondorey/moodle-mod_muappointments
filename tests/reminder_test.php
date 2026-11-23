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
use mod_muappointments\local\signup_service;
use mod_muappointments\task\send_reminders;

/**
 * The reminder is sent once, and not to the wait list or third parties.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_muappointments\task\send_reminders
 */
final class reminder_test extends \advanced_testcase {
    /**
     * One reminder to the booked user. A second run does not send it again.
     */
    public function test_reminder_sent_once(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('noemailever');
        $sink = $this->redirectEmails();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_user();
        $waiting = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $generator->enrol_user($waiting->id, $course->id, 'student');
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance([
            'course' => $course->id,
            'reminderperiod' => 2,
            'thirdpartyemails' => 'desk@example.com',
            'thirdpartywaitlist' => 0,
        ]);
        $start = time() + DAYSECS;
        $appointmentid = appointment_service::create((int) $instance->id, 1, true, true, '', FORMAT_HTML, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $this->setUser($student);
        signup_service::book($appointmentid, (int) $student->id);
        $this->setUser($waiting);
        signup_service::book($appointmentid, (int) $waiting->id);
        $sink->clear();

        $task = new send_reminders();
        $task->execute();
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame($student->email, $messages[0]->to);
        $signup = $DB->get_record('muappointments_signup', ['appointmentid' => $appointmentid, 'userid' => $student->id]);
        $this->assertGreaterThan(0, (int) $signup->remindersent);

        $sink->clear();
        $task->execute();
        $this->assertCount(0, $sink->get_messages());
    }
}
