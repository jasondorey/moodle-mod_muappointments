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
 * Restore structure.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_muappointments\customfield\appointment_handler;
use mod_muappointments\local\calendar_service;

defined('MOODLE_INTERNAL') || die();

/**
 * Restore one appointment activity and rebuild calendar events afterwards.
 */
class restore_muappointments_activity_structure_step extends restore_activity_structure_step {
    /** @var int[] Appointment ids restored in this step. */
    private array $appointmentids = [];

    /**
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');
        $paths[] = new restore_path_element('muappointments', '/activity/muappointments');
        $paths[] = new restore_path_element('muappointments_notify', '/activity/muappointments/notifies/notify');
        $paths[] = new restore_path_element('muappointments_appointment', '/activity/muappointments/appointments/appointment');
        $paths[] = new restore_path_element('muappointments_session', '/activity/muappointments/appointments/appointment/sessions/session');
        $paths[] = new restore_path_element('muappointments_customfield',
            '/activity/muappointments/appointments/appointment/customfields/customfield');
        if ($userinfo) {
            $paths[] = new restore_path_element('muappointments_signup',
                '/activity/muappointments/appointments/appointment/signups/signup');
            $paths[] = new restore_path_element('muappointments_status',
                '/activity/muappointments/appointments/appointment/signups/signup/history/statusrow');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('muappointments', $data);
        $this->apply_activity_instance($newid);
        $this->set_mapping('muappointments', $oldid, $newid, true);
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_notify($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->muappointmentsid = $this->get_new_parentid('muappointments');
        $newid = $DB->insert_record('muappointments_notify', $data);
        $this->set_mapping('muappointments_notify', $oldid, $newid);
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_appointment($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->muappointmentsid = $this->get_new_parentid('muappointments');
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $newid = $DB->insert_record('muappointments_appointment', $data);
        $this->set_mapping('muappointments_appointment', $oldid, $newid, true);
        $this->appointmentids[] = (int) $newid;
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_session($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->appointmentid = $this->get_new_parentid('muappointments_appointment');
        $data->timestart = $this->apply_date_offset($data->timestart);
        $data->timefinish = $this->apply_date_offset($data->timefinish);
        $data->eventid = 0;
        $data->timemodified = time();
        $newid = $DB->insert_record('muappointments_session', $data);
        $this->set_mapping('muappointments_session', $oldid, $newid);
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_customfield($data) {
        $data = (object) $data;
        $appointmentid = (int) $this->get_new_parentid('muappointments_appointment');
        $handler = appointment_handler::create();
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') !== $data->shortname || $field->get('type') !== $data->type) {
                continue;
            }
            $record = \core_customfield\data_controller::create(0, (object) ['instanceid' => $appointmentid], $field);
            foreach (['value', 'charvalue', 'shortcharvalue', 'decvalue', 'intvalue'] as $column) {
                if (isset($data->$column) && $data->$column !== '' && $data->$column !== null) {
                    $record->set($column, $data->$column);
                }
            }
            if (isset($data->valueformat)) {
                $record->set('valueformat', $data->valueformat);
            }
            $record->set('contextid', $this->task->get_contextid());
            $record->save();
            return;
        }
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_signup($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        unset($data->id);
        $data->appointmentid = $this->get_new_parentid('muappointments_appointment');
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $data->bookedby = $this->get_mappingid('user', $data->bookedby) ?: 0;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!empty($data->remindersent)) {
            $data->remindersent = $this->apply_date_offset($data->remindersent);
        }
        if (!$data->userid) {
            return;
        }
        $newid = $DB->insert_record('muappointments_signup', $data);
        $this->set_mapping('muappointments_signup', $oldid, $newid);
    }

    /**
     * @param array|stdClass $data
     */
    protected function process_muappointments_status($data) {
        global $DB;
        $data = (object) $data;
        unset($data->id);
        $data->signupid = $this->get_new_parentid('muappointments_signup');
        if (!$data->signupid) {
            return;
        }
        $data->userid = $this->get_mappingid('user', $data->userid) ?: 0;
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $DB->insert_record('muappointments_status', $data);
    }

    /**
     * Files and calendar rows.
     */
    protected function after_execute() {
        $this->add_related_files('mod_muappointments', 'intro', null);
        $this->add_related_files('mod_muappointments', 'appointment', 'muappointments_appointment');
        foreach ($this->appointmentids as $id) {
            calendar_service::sync_appointment($id);
        }
    }
}
