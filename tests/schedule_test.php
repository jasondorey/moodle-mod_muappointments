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

/**
 * Timeframe split, overrun, and duplicate offset.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_muappointments\local\schedule
 * @covers    \mod_muappointments\local\appointment_service
 */
final class schedule_test extends \advanced_testcase {
    /**
     * Split and break, including a slot that starts inside the timeframe and runs past the end.
     */
    public function test_split_break_and_overrun(): void {
        $start = strtotime('2026-11-23 09:00:00 UTC');
        $end = strtotime('2026-11-23 12:00:00 UTC');
        $slots = schedule::split_timeframe($start, $end, HOURSECS, 15 * MINSECS);
        $this->assertSame([
            ['timestart' => $start, 'timefinish' => $start + HOURSECS],
            ['timestart' => $start + HOURSECS + (15 * MINSECS), 'timefinish' => $start + (2 * HOURSECS) + (15 * MINSECS)],
            ['timestart' => $start + (2 * HOURSECS) + (30 * MINSECS), 'timefinish' => $start + (3 * HOURSECS) + (30 * MINSECS)],
        ], $slots);
        $this->assertGreaterThan($end, $slots[2]['timefinish']);
        $this->assertLessThan($end, $slots[2]['timestart']);

        $whole = schedule::split_timeframe($start, $end, 0, HOURSECS);
        $this->assertSame([['timestart' => $start, 'timefinish' => $end]], $whole);

        $backtoback = schedule::split_timeframe($start, $end, HOURSECS, 0);
        $this->assertCount(3, $backtoback);
        $this->assertSame($start + (2 * HOURSECS), $backtoback[2]['timestart']);
        $this->assertSame($end, $backtoback[2]['timefinish']);
    }

    /**
     * The generator stores the overrun slot.
     */
    public function test_split_is_persisted(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance(['course' => $course->id]);
        $start = strtotime('2026-11-23 09:00:00 UTC');
        $end = strtotime('2026-11-23 12:00:00 UTC');
        $ids = appointment_service::create_from_timeframes(
            (int) $instance->id,
            [['start' => $start, 'end' => $end]],
            HOURSECS,
            15 * MINSECS,
            10,
            false,
            true,
            'Room A',
            FORMAT_HTML
        );
        $this->assertCount(3, $ids);
        $last = appointment_service::sessions((int) end($ids));
        $last = reset($last);
        $this->assertGreaterThan($end, (int) $last->timefinish);
        $this->assertLessThan($end, (int) $last->timestart);
    }

    /**
     * The delta from the first session is applied to later sessions.
     */
    public function test_duplicate_date_offset(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $generator->get_plugin_generator('mod_muappointments')->create_instance(['course' => $course->id]);
        $first = strtotime('2026-11-23 09:00:00 UTC');
        $second = $first + (3 * DAYSECS);
        $appointmentid = appointment_service::create((int) $instance->id, 10, false, true, '', FORMAT_HTML, [
            ['timestart' => $first, 'timefinish' => $first + HOURSECS],
            ['timestart' => $second, 'timefinish' => $second + (2 * HOURSECS)],
        ]);
        $newfirst = $first + (7 * DAYSECS);
        $copy = appointment_service::duplicate($appointmentid, $newfirst);
        $sessions = array_values(appointment_service::sessions($copy));
        $this->assertCount(2, $sessions);
        $this->assertSame($newfirst, (int) $sessions[0]->timestart);
        $this->assertSame($newfirst + HOURSECS, (int) $sessions[0]->timefinish);
        $this->assertSame($newfirst + (3 * DAYSECS), (int) $sessions[1]->timestart);
        $this->assertSame($newfirst + (3 * DAYSECS) + (2 * HOURSECS), (int) $sessions[1]->timefinish);
    }
}
