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
 * Book enrolled users, optionally without email.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\local\signup_service;

/**
 * Picker of users enrolled in the course.
 */
class add_attendees_form extends activity_dynamic_form {
    /**
     * Access.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/muappointments:addattendees', $this->get_context_for_dynamic_submission());
    }

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $context = $this->get_context_for_dynamic_submission();
        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'appointmentid', $this->optional_param('appointmentid', 0, PARAM_INT));
        $mform->setType('appointmentid', PARAM_INT);

        $users = get_enrolled_users(
            $context,
            'mod/muappointments:view',
            0,
            'u.id, u.firstname, u.lastname, u.email, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
            'u.lastname, u.firstname'
        );
        $options = [];
        foreach ($users as $user) {
            $options[$user->id] = fullname($user) . ' (' . $user->email . ')';
        }
        $mform->addElement('autocomplete', 'userids', get_string('attendees', 'mod_muappointments'), $options, ['multiple' => true]);
        $mform->addRule('userids', null, 'required', null, 'client');
        $mform->addElement('advcheckbox', 'suppressemail', get_string('suppressemail', 'mod_muappointments'));
    }

    /**
     * No defaults.
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $activity = $this->activity_record();
        foreach ($data['userids'] ?? [] as $userid) {
            if (signup_service::active_signup((int) $activity->id, (int) $userid)) {
                $errors['userids'] = get_string('alreadybooked', 'mod_muappointments');
                break;
            }
        }
        return $errors;
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        $ids = array_map('intval', (array) $data->userids);
        signup_service::add_attendees(
            (int) $data->appointmentid,
            $ids,
            (int) $USER->id,
            !empty($data->suppressemail)
        );
        return ['count' => count($ids)];
    }
}
