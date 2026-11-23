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
 * Backup structure.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Activity, templates, appointments, sessions, custom field values, and optional signups.
 */
class backup_muappointments_activity_structure_step extends backup_activity_structure_step {
    /**
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $activity = new backup_nested_element('muappointments', ['id'], [
            'name', 'intro', 'introformat', 'thirdpartyemails', 'thirdpartywaitlist',
            'allowcanceldefault', 'calendardisplay', 'showoncalendar', 'shortname',
            'reminderperiod', 'completionbook', 'completionattend', 'timecreated', 'timemodified',
        ]);
        $notifies = new backup_nested_element('notifies');
        $notify = new backup_nested_element('notify', ['id'], ['template', 'subject', 'body']);
        $appointments = new backup_nested_element('appointments');
        $appointment = new backup_nested_element('appointment', ['id'], [
            'capacity', 'waitlist', 'allowcancel', 'description', 'descriptionformat',
            'timecreated', 'timemodified',
        ]);
        $sessions = new backup_nested_element('sessions');
        $session = new backup_nested_element('session', ['id'], ['timestart', 'timefinish']);
        $fields = new backup_nested_element('customfields');
        $field = new backup_nested_element('customfield', ['id'], [
            'shortname', 'type', 'value', 'valueformat', 'valuetrust',
            'charvalue', 'shortcharvalue', 'decvalue', 'intvalue',
        ]);
        $signups = new backup_nested_element('signups');
        $signup = new backup_nested_element('signup', ['id'], [
            'userid', 'status', 'cancelnote', 'bookedby', 'remindersent', 'timecreated', 'timemodified',
        ]);
        $history = new backup_nested_element('history');
        $status = new backup_nested_element('statusrow', ['id'], [
            'status', 'note', 'timecreated', 'userid',
        ]);

        $activity->add_child($notifies);
        $notifies->add_child($notify);
        $activity->add_child($appointments);
        $appointments->add_child($appointment);
        $appointment->add_child($sessions);
        $sessions->add_child($session);
        $appointment->add_child($fields);
        $fields->add_child($field);
        $appointment->add_child($signups);
        $signups->add_child($signup);
        $signup->add_child($history);
        $history->add_child($status);

        $activity->set_source_table('muappointments', ['id' => backup::VAR_ACTIVITYID]);
        $notify->set_source_table('muappointments_notify', ['muappointmentsid' => backup::VAR_PARENTID]);
        $appointment->set_source_table('muappointments_appointment', ['muappointmentsid' => backup::VAR_PARENTID]);
        $session->set_source_table('muappointments_session', ['appointmentid' => backup::VAR_PARENTID], 'timestart ASC');
        $field->set_source_sql(
            "SELECT d.id, f.shortname, f.type, d.value, d.valueformat, d.valuetrust,
                    d.charvalue, d.shortcharvalue, d.decvalue, d.intvalue
               FROM {customfield_data} d
               JOIN {customfield_field} f ON f.id = d.fieldid
               JOIN {customfield_category} c ON c.id = f.categoryid
              WHERE d.instanceid = :appointmentid
                AND c.component = 'mod_muappointments'
                AND c.area = 'appointment'",
            ['appointmentid' => backup::VAR_PARENTID]
        );
        if ($userinfo) {
            $signup->set_source_table('muappointments_signup', ['appointmentid' => backup::VAR_PARENTID]);
            $status->set_source_table('muappointments_status', ['signupid' => backup::VAR_PARENTID], 'timecreated ASC');
        }

        $signup->annotate_ids('user', 'userid');
        $signup->annotate_ids('user', 'bookedby');
        $status->annotate_ids('user', 'userid');
        $activity->annotate_files('mod_muappointments', 'intro', null);
        $appointment->annotate_files('mod_muappointments', 'appointment', 'id');

        return $this->prepare_activity_structure($activity);
    }
}
