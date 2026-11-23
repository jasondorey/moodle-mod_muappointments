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
use mod_muappointments\local\schedule;
use mod_muappointments\local\signup_service;

/**
 * Signup capacity, wait list, and promotion.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_muappointments\local\signup_service
 */
final class signup_test extends \advanced_testcase {
    /**
     * Course, activity, and enrolled students.
     *
     * @param int $students
     * @param array $activity
     * @return array
     */
    private function fixture(int $students, array $activity = []): array {
        $this->resetAfterTest();
        unset_config('noemailever');
        $this->redirectEmails();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance(array_merge([
            'course' => $course->id,
        ], $activity));
        $users = [];
        for ($i = 0; $i < $students; $i++) {
            $user = $generator->create_user();
            $generator->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
        }
        return [$course, $instance, $users];
    }

    /**
     * A future session so the appointment is not an interest list.
     *
     * @param int $activityid
     * @param int $capacity
     * @param bool $waitlist
     * @param array $sessions
     * @return int
     */
    private function appointment(int $activityid, int $capacity, bool $waitlist, array $sessions): int {
        return appointment_service::create(
            $activityid,
            $capacity,
            $waitlist,
            true,
            '',
            FORMAT_HTML,
            $sessions
        );
    }

    /**
     * The second person cannot take the last seat. The count is re-read inside the lock.
     */
    public function test_last_seat(): void {
        [, $instance, $users] = $this->fixture(2);
        $start = time() + 2 * DAYSECS;
        $appointmentid = $this->appointment((int) $instance->id, 1, false, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $this->setUser($users[0]);
        signup_service::book($appointmentid, (int) $users[0]->id);
        $this->setUser($users[1]);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('appointmentfull', 'mod_muappointments'));
        signup_service::book($appointmentid, (int) $users[1]->id);
    }

    /**
     * Promotion follows signup time, not the order the test happens to call book.
     */
    public function test_waitlist_order(): void {
        global $DB;
        [, $instance, $users] = $this->fixture(3);
        $start = time() + 2 * DAYSECS;
        $appointmentid = $this->appointment((int) $instance->id, 1, true, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $this->setUser($users[0]);
        signup_service::book($appointmentid, (int) $users[0]->id);
        $this->setUser($users[1]);
        $second = signup_service::book($appointmentid, (int) $users[1]->id);
        $this->setUser($users[2]);
        $third = signup_service::book($appointmentid, (int) $users[2]->id);
        $this->assertSame(schedule::WAITLISTED, $second->status);
        $this->assertSame(schedule::WAITLISTED, $third->status);
        // Third signed up later, but give them the earlier signup time.
        $DB->set_field('muappointments_signup', 'timecreated', 100, ['id' => $third->id]);
        $DB->set_field('muappointments_signup', 'timecreated', 200, ['id' => $second->id]);

        $booked = $DB->get_record('muappointments_signup', ['appointmentid' => $appointmentid, 'userid' => $users[0]->id]);
        $this->setUser($users[0]);
        signup_service::cancel((int) $booked->id, (int) $users[0]->id, 'changed plans');

        $second = $DB->get_record('muappointments_signup', ['id' => $second->id]);
        $third = $DB->get_record('muappointments_signup', ['id' => $third->id]);
        $this->assertSame(schedule::BOOKED, $third->status);
        $this->assertSame(schedule::WAITLISTED, $second->status);
    }

    /**
     * Cancelling a booked signup promotes the first wait-listed user and frees the seat.
     */
    public function test_promotion_on_cancel(): void {
        global $DB;
        [, $instance, $users] = $this->fixture(2);
        $start = time() + 2 * DAYSECS;
        $appointmentid = $this->appointment((int) $instance->id, 1, true, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $this->setUser($users[0]);
        $booked = signup_service::book($appointmentid, (int) $users[0]->id);
        $this->setUser($users[1]);
        $waiting = signup_service::book($appointmentid, (int) $users[1]->id);
        $this->assertSame(schedule::WAITLISTED, $waiting->status);

        $this->setUser($users[0]);
        signup_service::cancel((int) $booked->id, (int) $users[0]->id, '');
        $waiting = $DB->get_record('muappointments_signup', ['id' => $waiting->id]);
        $this->assertSame(schedule::BOOKED, $waiting->status);
        $this->assertSame(1, signup_service::count_booked($appointmentid));
    }

    /**
     * Adding the first session promotes wait-listed users in signup order, up to capacity.
     */
    public function test_promotion_when_first_session_added(): void {
        global $DB;
        [, $instance, $users] = $this->fixture(3);
        $appointmentid = appointment_service::create((int) $instance->id, 2, false, true, '', FORMAT_HTML, []);
        foreach ($users as $index => $user) {
            $this->setUser($user);
            $signup = signup_service::book($appointmentid, (int) $user->id);
            $this->assertSame(schedule::WAITLISTED, $signup->status);
            $DB->set_field('muappointments_signup', 'timecreated', 1000 + $index, ['id' => $signup->id]);
        }
        $start = time() + 2 * DAYSECS;
        $this->setAdminUser();
        appointment_service::update($appointmentid, 2, false, true, '', FORMAT_HTML, [
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
        ]);
        $statuses = [];
        foreach ($users as $user) {
            $row = $DB->get_record('muappointments_signup', ['appointmentid' => $appointmentid, 'userid' => $user->id]);
            $statuses[] = $row->status;
        }
        $this->assertSame([schedule::BOOKED, schedule::BOOKED, schedule::WAITLISTED], $statuses);
    }

    /**
     * A user holds at most one active signup. After cancelling, they may book a different appointment.
     */
    public function test_one_signup_per_activity(): void {
        [, $instance, $users] = $this->fixture(1);
        $start = time() + 2 * DAYSECS;
        $session = [['timestart' => $start, 'timefinish' => $start + HOURSECS]];
        $first = $this->appointment((int) $instance->id, 5, false, $session);
        $second = $this->appointment((int) $instance->id, 5, false, $session);
        $user = $users[0];
        $this->setUser($user);
        $signup = signup_service::book($first, (int) $user->id);
        try {
            signup_service::book($second, (int) $user->id);
            $this->fail('A second active signup must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('alreadybooked', $e->errorcode);
        }
        signup_service::cancel((int) $signup->id, (int) $user->id, '');
        $again = signup_service::book($second, (int) $user->id);
        $this->assertSame(schedule::BOOKED, $again->status);
    }
}
