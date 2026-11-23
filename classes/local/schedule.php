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
 * Pure scheduling and status rules.
 *
 * No database and no Moodle globals, so the rules can be checked without a site.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

/**
 * Status constants and time calculations.
 */
class schedule {
    /** @var string Signup holds a seat. */
    public const BOOKED = 'booked';
    /** @var string Signup is waiting for a seat. */
    public const WAITLISTED = 'waitlisted';
    /** @var string Signup was cancelled and the row was kept. */
    public const USERCANCELLED = 'usercancelled';
    /** @var string Attendance: the person did not attend. */
    public const NOSHOW = 'noshow';
    /** @var string Attendance: the person attended part of the appointment. */
    public const PARTIALLYATTENDED = 'partiallyattended';
    /** @var string Attendance: the person attended. */
    public const FULLYATTENDED = 'fullyattended';

    /** @var string Derived appointment status. */
    public const FINISHED = 'finished';
    /** @var string Derived appointment status. */
    public const INPROGRESS = 'inprogress';
    /** @var string Derived appointment status. */
    public const FULL = 'full';
    /** @var string Derived appointment status. */
    public const OPEN = 'open';

    /** @var string[] Notification template keys, in form order. */
    public const TEMPLATES = ['confirmation', 'update', 'reminder', 'waitlisted', 'cancellation'];

    /** @var string[] Calendar display values. */
    public const CALENDAR_DISPLAYS = ['site', 'course', 'none'];

    /** @var int Seconds in a day. Kept local so this class does not need Moodle constants. */
    public const DAY = 86400;

    /**
     * Statuses that consume a seat.
     *
     * @return string[]
     */
    public static function seat_statuses(): array {
        return [self::BOOKED, self::NOSHOW, self::PARTIALLYATTENDED, self::FULLYATTENDED];
    }

    /**
     * Whether this signup status consumes a seat.
     *
     * @param string $status Signup status.
     * @return bool
     */
    public static function consumes_seat(string $status): bool {
        return in_array($status, self::seat_statuses(), true);
    }

    /**
     * Active signups block a second signup in the same activity. Cancelled rows do not.
     *
     * @param string $status Signup status.
     * @return bool
     */
    public static function is_active(string $status): bool {
        return $status !== self::USERCANCELLED;
    }

    /**
     * Booked and attendance statuses get a user calendar event. Wait-listed signups do not.
     *
     * @param string $status Signup status.
     * @return bool
     */
    public static function has_user_event(string $status): bool {
        return self::consumes_seat($status);
    }

    /**
     * Completion rule "learner must book".
     *
     * @param string|null $status Current active signup status, or null.
     * @return bool
     */
    public static function completes_book(?string $status): bool {
        return in_array($status, [self::BOOKED, self::FULLYATTENDED, self::PARTIALLYATTENDED], true);
    }

    /**
     * Completion rule "learner must book and attend".
     *
     * @param string|null $status Current active signup status, or null.
     * @return bool
     */
    public static function completes_attend(?string $status): bool {
        return in_array($status, [self::FULLYATTENDED, self::PARTIALLYATTENDED], true);
    }

    /**
     * Attendance statuses a teacher may store.
     *
     * @return string[]
     */
    public static function attendance_statuses(): array {
        return [self::NOSHOW, self::PARTIALLYATTENDED, self::FULLYATTENDED];
    }

    /**
     * Derived appointment status. Not stored.
     *
     * Finished wins, then in progress, then full, then open.
     * A session-less appointment is only open or full.
     *
     * @param array $sessions Session rows or arrays with timestart and timefinish.
     * @param int $booked Seat-consuming signup count.
     * @param int $capacity Appointment capacity.
     * @param int $now Unix time.
     * @return string One of the display status constants.
     */
    public static function display_status(array $sessions, int $booked, int $capacity, int $now): string {
        if ($sessions) {
            $latestfinish = 0;
            $inprogress = false;
            foreach ($sessions as $session) {
                [$start, $finish] = self::bounds($session);
                if ($finish > $latestfinish) {
                    $latestfinish = $finish;
                }
                if ($now >= $start && $now <= $finish) {
                    $inprogress = true;
                }
            }
            if ($latestfinish < $now) {
                return self::FINISHED;
            }
            if ($inprogress) {
                return self::INPROGRESS;
            }
        }
        if ($booked >= $capacity) {
            return self::FULL;
        }
        return self::OPEN;
    }

    /**
     * Split one timeframe into appointment slots.
     *
     * Split of 0 is one appointment covering the timeframe. Break of 0 places slots
     * back to back. A slot that starts inside the timeframe and runs past the end is kept.
     *
     * @param int $start Timeframe start.
     * @param int $end Timeframe end.
     * @param int $splitseconds Length of each appointment. 0 means do not split.
     * @param int $breakseconds Gap between appointments.
     * @return array<int, array{timestart: int, timefinish: int}>
     */
    public static function split_timeframe(int $start, int $end, int $splitseconds, int $breakseconds): array {
        if ($end <= $start) {
            return [];
        }
        if ($splitseconds <= 0) {
            return [['timestart' => $start, 'timefinish' => $end]];
        }
        if ($breakseconds < 0) {
            $breakseconds = 0;
        }
        $slots = [];
        $cursor = $start;
        while ($cursor < $end) {
            $slots[] = [
                'timestart' => $cursor,
                'timefinish' => $cursor + $splitseconds,
            ];
            $cursor += $splitseconds + $breakseconds;
        }
        return $slots;
    }

    /**
     * Apply one delta, taken from the first session, to every session.
     *
     * @param array<int, array{timestart: int, timefinish: int}> $sessions
     * @param int $newfirststart New start of the earliest session.
     * @return array<int, array{timestart: int, timefinish: int}>
     */
    public static function shift_sessions(array $sessions, int $newfirststart): array {
        if ($sessions === []) {
            return [];
        }
        $sessions = array_values($sessions);
        usort($sessions, static function (array $a, array $b): int {
            return $a['timestart'] <=> $b['timestart'];
        });
        $delta = $newfirststart - $sessions[0]['timestart'];
        $shifted = [];
        foreach ($sessions as $session) {
            $shifted[] = [
                'timestart' => $session['timestart'] + $delta,
                'timefinish' => $session['timefinish'] + $delta,
            ];
        }
        return $shifted;
    }

    /**
     * Next full hour strictly after $now, in the given timezone.
     *
     * @param int $now Unix time.
     * @param \DateTimeZone $timezone Editor timezone.
     * @return int
     */
    public static function next_full_hour(int $now, \DateTimeZone $timezone): int {
        $current = (new \DateTimeImmutable('@' . $now))->setTimezone($timezone);
        $top = $current->setTime((int) $current->format('G'), 0, 0);
        return $top->modify('+1 hour')->getTimestamp();
    }

    /**
     * Whether a reminder should be sent on this run.
     *
     * Anchored to the earliest session. Wait-listed callers must not ask.
     * A period of 0 sends nothing. Already-sent and already-started sessions send nothing.
     *
     * @param int $now Unix time.
     * @param int|null $earlieststart Earliest session start, or null when there is no session.
     * @param int $perioddays Days before the earliest session.
     * @param int $remindersent Unix time the reminder was sent, or 0.
     * @return bool
     */
    public static function reminder_due(int $now, ?int $earlieststart, int $perioddays, int $remindersent): bool {
        if ($remindersent > 0 || $earlieststart === null || $perioddays <= 0) {
            return false;
        }
        $windowstart = $earlieststart - ($perioddays * self::DAY);
        return $now >= $windowstart && $now < $earlieststart;
    }

    /**
     * @param array|object $session
     * @return array{0: int, 1: int}
     */
    private static function bounds(array|object $session): array {
        if (is_array($session)) {
            return [(int) $session['timestart'], (int) $session['timefinish']];
        }
        return [(int) $session->timestart, (int) $session->timefinish];
    }
}
