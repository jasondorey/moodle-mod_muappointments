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
 * Duplicate an appointment onto a new start.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\local\appointment_service;

/**
 * New start date and time. Later sessions keep their spacing.
 */
class duplicate_form extends activity_dynamic_form {
    /**
     * Access.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/muappointments:editsessions', $this->get_context_for_dynamic_submission());
    }

    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $appointmentid = $this->optional_param('appointmentid', 0, PARAM_INT);
        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'appointmentid', $appointmentid);
        $mform->setType('appointmentid', PARAM_INT);

        $sessions = appointment_service::sessions($appointmentid);
        $current = $sessions ? userdate(reset($sessions)->timestart) : get_string('nodate', 'mod_muappointments');
        $mform->addElement('static', 'currentstart', get_string('currentstart', 'mod_muappointments'), $current);
        $default = $sessions ? (int) reset($sessions)->timestart : time();
        $mform->addElement('date_time_selector', 'newstart', get_string('newstart', 'mod_muappointments'));
        $mform->setDefault('newstart', $default);
    }

    /**
     * Nothing to preload beyond defaults.
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();
        $id = appointment_service::duplicate((int) $data->appointmentid, (int) $data->newstart);
        return ['id' => $id];
    }
}
