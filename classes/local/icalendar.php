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
 * iCalendar payloads. One stable UID per session, times in UTC.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\local;

/**
 * Build a VCALENDAR document.
 */
class icalendar {
    /**
     * Stable UID for a session. An update reuses it so clients revise the same event.
     *
     * @param int $sessionid Session id.
     * @return string
     */
    public static function uid(int $sessionid): string {
        global $CFG;
        $host = parse_url($CFG->wwwroot ?? '', PHP_URL_HOST);
        if (!$host) {
            $host = 'localhost';
        }
        return 'muappointments-session-' . $sessionid . '@' . $host;
    }

    /**
     * @param array<int, array{uid: string, start: int, end: int, summary: string, description: string, sequence: int}> $events
     * @param string $method REQUEST or CANCEL.
     * @return string CRLF calendar body. Empty when there are no events.
     */
    public static function build(array $events, string $method = 'REQUEST'): string {
        if ($events === []) {
            return '';
        }
        $method = $method === 'CANCEL' ? 'CANCEL' : 'REQUEST';
        $status = $method === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED';
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Moodle//mod_muappointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
        ];
        $stamp = self::utc(time());
        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $event['uid'];
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc($event['start']);
            $lines[] = 'DTEND:' . self::utc($event['end']);
            $lines[] = 'SUMMARY:' . self::escape($event['summary']);
            $lines[] = 'DESCRIPTION:' . self::escape($event['description']);
            $lines[] = 'SEQUENCE:' . max(0, (int) $event['sequence']);
            $lines[] = 'STATUS:' . $status;
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = self::fold($line);
        }
        return implode("\r\n", $folded) . "\r\n";
    }

    /**
     * @param int $timestamp Unix time.
     * @return string UTC basic format.
     */
    public static function utc(int $timestamp): string {
        return gmdate('Ymd\THis\Z', $timestamp);
    }

    /**
     * @param string $value Raw text.
     * @return string
     */
    public static function escape(string $value): string {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\n', $value);
        return str_replace([',', ';'], ['\,', '\;'], $value);
    }

    /**
     * Fold a content line at 75 octets.
     *
     * @param string $line One content line without CRLF.
     * @return string
     */
    public static function fold(string $line): string {
        if (strlen($line) <= 75) {
            return $line;
        }
        $parts = [];
        while (strlen($line) > 75) {
            $parts[] = substr($line, 0, 75);
            $line = ' ' . substr($line, 75);
        }
        $parts[] = $line;
        return implode("\r\n", $parts);
    }
}
