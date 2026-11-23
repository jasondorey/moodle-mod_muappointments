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
 * Optional cancellation reason.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\local\signup_service;

/**
 * Cancel your signup, or remove someone else's.
 */
class cancel_form extends activity_dynamic_form {
    /**
     * The signup owner, or someone who can remove attendees.
     */
    protected function check_access_for_dynamic_submission(): void {
        global $DB, $USER;
        $context = $this->get_context_for_dynamic_submission();
        $signupid = $this->optional_param('signupid', 0, PARAM_INT);
        $signup = $DB->get_record('muappointments_signup', ['id' => $signupid], '*', MUST_EXIST);
        if ((int) $signup->userid === (int) $USER->id) {
            require_capability('mod/muappointments:signup', $context);
            return;
        }
        require_capability('mod/muappointments:removeattendees', $context);
    }

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'signupid', $this->optional_param('signupid', 0, PARAM_INT));
        $mform->setType('signupid', PARAM_INT);
        $mform->addElement('textarea', 'note', get_string('cancelreason', 'mod_muappointments'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('note', PARAM_TEXT);
    }

    /**
     * No defaults.
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        signup_service::cancel((int) $data->signupid, (int) $USER->id, trim((string) ($data->note ?? '')));
        return ['ok' => true];
    }
}
