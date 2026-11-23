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

use mod_muappointments\completion\custom_completion;
use mod_muappointments\local\appointment_service;
use mod_muappointments\local\schedule;
use mod_muappointments\local\signup_service;

/**
 * Book, and book-and-attend, completion.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_muappointments\completion\custom_completion
 */
final class completion_test extends \advanced_testcase {
    /**
     * Booked, partially attended, and fully attended satisfy "book".
     * Only the two attendance statuses satisfy "book and attend".
     */
    public function test_book_and_attend_rules(): void {
        global $DB;
        $this->resetAfterTest();
        unset_config('noemailever');
        $this->redirectEmails();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionbook' => 1,
            'completionattend' => 1,
        ]);
        $DB->set_field('muappointments', 'completionbook', 1, ['id' => $instance->id]);
        $DB->set_field('muappointments', 'completionattend', 1, ['id' => $instance->id]);
        $cm = get_coursemodule_from_instance('muappointments', $instance->id, $course->id, false, MUST_EXIST);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);

        $start = time() + (3 * DAYSECS);
        $appointmentid = appointment_service::create((int) $instance->id, 10, false, true, '', FORMAT_HTML, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setAdminUser();

        $wanted = [
            'booked' => schedule::BOOKED,
            'partial' => schedule::PARTIALLYATTENDED,
            'full' => schedule::FULLYATTENDED,
            'noshow' => schedule::NOSHOW,
            'cancelled' => schedule::USERCANCELLED,
        ];
        $signups = [];
        foreach ($wanted as $name => $status) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $added = signup_service::add_attendees($appointmentid, [(int) $user->id], (int) $teacher->id, true);
            $signups[$name] = ['user' => $user, 'signup' => $added[0], 'status' => $status];
        }

        $session = reset(appointment_service::sessions($appointmentid));
        $DB->update_record('muappointments_session', (object) [
            'id' => $session->id,
            'timestart' => time() - (2 * HOURSECS),
            'timefinish' => time() - HOURSECS,
        ]);

        $this->setUser($teacher);
        foreach (['partial', 'full', 'noshow'] as $name) {
            signup_service::take_attendance($appointmentid, [
                (int) $signups[$name]['signup']->id => $signups[$name]['status'],
            ], (int) $teacher->id);
        }
        signup_service::cancel((int) $signups['cancelled']['signup']->id, (int) $teacher->id, 'removed');

        $waituser = $generator->create_user();
        $generator->enrol_user($waituser->id, $course->id, 'student');
        $waitid = appointment_service::create((int) $instance->id, 0, true, true, '', FORMAT_HTML, [
            ['timestart' => time() + DAYSECS, 'timefinish' => time() + DAYSECS + HOURSECS],
        ]);
        $this->setUser($waituser);
        $waiting = signup_service::book($waitid, (int) $waituser->id);
        $this->assertSame(schedule::WAITLISTED, $waiting->status);
        $signups['wait'] = ['user' => $waituser, 'status' => schedule::WAITLISTED];

        $expect = [
            'booked' => [COMPLETION_COMPLETE, COMPLETION_INCOMPLETE],
            'partial' => [COMPLETION_COMPLETE, COMPLETION_COMPLETE],
            'full' => [COMPLETION_COMPLETE, COMPLETION_COMPLETE],
            'noshow' => [COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE],
            'cancelled' => [COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE],
            'wait' => [COMPLETION_INCOMPLETE, COMPLETION_INCOMPLETE],
        ];
        $modinfo = get_fast_modinfo($course);
        $cminfo = $modinfo->get_cm($cm->id);
        foreach ($expect as $name => [$book, $attend]) {
            $completion = new custom_completion($cminfo, (int) $signups[$name]['user']->id);
            $this->assertSame($book, $completion->get_state('completionbook'), $name . ' book');
            $this->assertSame($attend, $completion->get_state('completionattend'), $name . ' attend');
        }
    }
}
