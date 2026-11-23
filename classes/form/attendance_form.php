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
 * Take attendance.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\local\schedule;
use mod_muappointments\local\signup_service;

/**
 * One status per seat-holding signup.
 */
class attendance_form extends activity_dynamic_form {
    /**
     * Access.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/muappointments:takeattendance', $this->get_context_for_dynamic_submission());
    }

    /**
     * Form definition.
     */
    protected function definition(): void {
        global $DB;
        $mform = $this->_form;
        $appointmentid = $this->optional_param('appointmentid', 0, PARAM_INT);
        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'appointmentid', $appointmentid);
        $mform->setType('appointmentid', PARAM_INT);

        $options = [
            schedule::BOOKED => get_string('status:booked', 'mod_muappointments'),
            schedule::NOSHOW => get_string('status:noshow', 'mod_muappointments'),
            schedule::PARTIALLYATTENDED => get_string('status:partiallyattended', 'mod_muappointments'),
            schedule::FULLYATTENDED => get_string('status:fullyattended', 'mod_muappointments'),
        ];
        [$insql, $params] = $DB->get_in_or_equal(schedule::seat_statuses(), SQL_PARAMS_NAMED);
        $params['appointmentid'] = $appointmentid;
        $signups = $DB->get_records_select(
            'muappointments_signup',
            "appointmentid = :appointmentid AND status $insql",
            $params,
            'timecreated ASC'
        );
        if (!$signups) {
            $mform->addElement('static', 'nobody', '', get_string('noattendees', 'mod_muappointments'));
            return;
        }
        foreach ($signups as $signup) {
            $user = $DB->get_record('user', ['id' => $signup->userid], 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename');
            $name = 'attend_' . $signup->id;
            $mform->addElement('select', $name, fullname($user), $options);
            $mform->setDefault($name, $signup->status);
            $mform->setType($name, PARAM_ALPHA);
        }
    }

    /**
     * Defaults are set in definition.
     */
    public function set_data_for_dynamic_submission(): void {
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        global $USER;
        $data = $this->get_data();
        $map = [];
        foreach ((array) $data as $key => $value) {
            if (preg_match('/^attend_(\d+)$/', (string) $key, $matches)) {
                $map[(int) $matches[1]] = $value;
            }
        }
        if ($map) {
            signup_service::take_attendance((int) $data->appointmentid, $map, (int) $USER->id);
        }
        return ['ok' => true];
    }
}
