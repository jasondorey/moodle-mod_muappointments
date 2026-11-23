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
 * Activity settings form.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

use mod_muappointments\local\schedule;

/**
 * Create or update an appointment activity.
 */
class mod_muappointments_mod_form extends moodleform_mod {
    /**
     * Definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('name'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('text', 'thirdpartyemails', get_string('thirdpartyemails', 'mod_muappointments'), ['size' => '64']);
        $mform->setType('thirdpartyemails', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('thirdpartyemails', 'thirdpartyemails', 'mod_muappointments');

        $mform->addElement('advcheckbox', 'thirdpartywaitlist', get_string('thirdpartywaitlist', 'mod_muappointments'));
        $mform->addHelpButton('thirdpartywaitlist', 'thirdpartywaitlist', 'mod_muappointments');

        $mform->addElement('advcheckbox', 'allowcanceldefault', get_string('allowcanceldefault', 'mod_muappointments'));
        $mform->setDefault('allowcanceldefault', 1);
        if (!has_capability('mod/muappointments:configurecancellation', $this->context)) {
            $mform->hardFreeze('allowcanceldefault');
        }

        $mform->addElement('header', 'calendarhdr', get_string('calendar', 'mod_muappointments'));
        $mform->addElement('select', 'calendardisplay', get_string('calendardisplay', 'mod_muappointments'), [
            'site' => get_string('calendardisplay:site', 'mod_muappointments'),
            'course' => get_string('calendardisplay:course', 'mod_muappointments'),
            'none' => get_string('calendardisplay:none', 'mod_muappointments'),
        ]);
        $mform->setDefault('calendardisplay', 'course');
        $mform->addHelpButton('calendardisplay', 'calendardisplay', 'mod_muappointments');

        $mform->addElement('advcheckbox', 'showoncalendar', get_string('showoncalendar', 'mod_muappointments'));
        $mform->setDefault('showoncalendar', 1);
        $mform->addHelpButton('showoncalendar', 'showoncalendar', 'mod_muappointments');

        $mform->addElement('text', 'shortname', get_string('shortname', 'mod_muappointments'), ['size' => '32']);
        $mform->setType('shortname', PARAM_TEXT);
        $mform->addHelpButton('shortname', 'shortname', 'mod_muappointments');

        $mform->addElement('header', 'notifyhdr', get_string('notifications', 'mod_muappointments'));
        foreach (schedule::TEMPLATES as $template) {
            $mform->addElement('text', 'notify_' . $template . '_subject',
                get_string('template:' . $template, 'mod_muappointments'), ['size' => '64']);
            $mform->setType('notify_' . $template . '_subject', PARAM_TEXT);
            $mform->setDefault('notify_' . $template . '_subject',
                get_string('default:' . $template . ':subject', 'mod_muappointments'));

            $mform->addElement('textarea', 'notify_' . $template . '_body',
                get_string('messagebody', 'mod_muappointments'), ['rows' => 8, 'cols' => 70]);
            $mform->setType('notify_' . $template . '_body', PARAM_RAW);
            $mform->setDefault('notify_' . $template . '_body',
                get_string('default:' . $template . ':body', 'mod_muappointments'));

            if ($template === 'reminder') {
                $mform->addElement('text', 'reminderperiod', get_string('reminderperiod', 'mod_muappointments'));
                $mform->setType('reminderperiod', PARAM_INT);
                $mform->setDefault('reminderperiod', 2);
                $mform->addHelpButton('reminderperiod', 'reminderperiod', 'mod_muappointments');
            }
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        global $DB;
        if (empty($this->_instance)) {
            return;
        }
        $rows = $DB->get_records('muappointments_notify', ['muappointmentsid' => $this->_instance]);
        foreach ($rows as $row) {
            $defaultvalues['notify_' . $row->template . '_subject'] = $row->subject;
            $defaultvalues['notify_' . $row->template . '_body'] = $row->body;
        }
    }

    /**
     * @param stdClass $data
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            foreach (['completionbook', 'completionattend'] as $field) {
                $key = $field . $suffix;
                if (empty($data->$key)) {
                    $data->$key = 0;
                }
            }
        }
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['thirdpartyemails'])) {
            foreach (preg_split('/\s*,\s*/', trim($data['thirdpartyemails'])) as $email) {
                if ($email !== '' && !validate_email($email)) {
                    $errors['thirdpartyemails'] = get_string('invalidemail');
                    break;
                }
            }
        }
        if (isset($data['reminderperiod']) && (int) $data['reminderperiod'] < 0) {
            $errors['reminderperiod'] = get_string('invalidreminderperiod', 'mod_muappointments');
        }
        if (!empty($data['calendardisplay']) && !in_array($data['calendardisplay'], schedule::CALENDAR_DISPLAYS, true)) {
            $errors['calendardisplay'] = get_string('invaliddata', 'error');
        }
        return $errors;
    }

    /**
     * @return string[]
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();
        $book = 'completionbook' . $suffix;
        $attend = 'completionattend' . $suffix;
        $mform->addElement('checkbox', $book, '', get_string('completionbook', 'mod_muappointments'));
        $mform->addElement('checkbox', $attend, '', get_string('completionattend', 'mod_muappointments'));
        return [$book, $attend];
    }

    /**
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionbook' . $suffix]) || !empty($data['completionattend' . $suffix]);
    }
}
