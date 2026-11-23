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
 * Signup cancelled or removed.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\event;

/**
 * Logged when a signup is cancelled.
 */
class signup_cancelled extends \core\event\base {
    /**
     * Init.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'muappointments_signup';
    }

    /**
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:signupcancelled', 'mod_muappointments');
    }

    /**
     * @return string
     */
    public function get_description(): string {
        return "The user with id '$this->userid' cancelled the signup with id '$this->objectid'.";
    }

    /**
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        return new \moodle_url('/mod/muappointments/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'muappointments_signup', 'restore' => 'muappointments_signup'];
    }
}
