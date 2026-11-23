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
 * Book, and book-and-attend, completion rules.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\completion;

use core_completion\activity_custom_completion;
use mod_muappointments\local\schedule;
use mod_muappointments\local\signup_service;

/**
 * Custom completion for one user.
 */
class custom_completion extends activity_custom_completion {
    /**
     * @param string $rule
     * @return int
     */
    public function get_state(string $rule): int {
        $this->validate_rule($rule);
        $signup = signup_service::active_signup((int) $this->cm->instance, $this->userid);
        $status = $signup->status ?? null;
        $complete = false;
        if ($rule === 'completionbook') {
            $complete = schedule::completes_book($status);
        } else if ($rule === 'completionattend') {
            $complete = schedule::completes_attend($status);
        }
        return $complete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionbook', 'completionattend'];
    }

    /**
     * @return string[]
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionbook' => get_string('completiondetail:book', 'mod_muappointments'),
            'completionattend' => get_string('completiondetail:attend', 'mod_muappointments'),
        ];
    }

    /**
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionbook',
            'completionattend',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
