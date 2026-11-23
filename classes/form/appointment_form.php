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
 * Add or edit one appointment.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\customfield\appointment_handler;
use mod_muappointments\local\appointment_service;
use mod_muappointments\local\schedule;

/**
 * One appointment and its sessions.
 */
class appointment_form extends activity_dynamic_form {
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
        $activity = $this->activity_record();

        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'appointmentid', $appointmentid);
        $mform->setType('appointmentid', PARAM_INT);
        $mform->addElement('hidden', 'id', $appointmentid);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'capacity', get_string('capacity', 'mod_muappointments'));
        $mform->setType('capacity', PARAM_INT);
        $mform->setDefault('capacity', 10);
        $mform->addRule('capacity', null, 'required', null, 'client');
        $mform->addRule('capacity', null, 'numeric', null, 'client');

        $mform->addElement('advcheckbox', 'waitlist', get_string('enablewaitlist', 'mod_muappointments'));
        $mform->addElement('advcheckbox', 'allowcancel', get_string('allowcancel', 'mod_muappointments'));
        $mform->setDefault('allowcancel', (int) $activity->allowcanceldefault);

        $mform->addElement('editor', 'description_editor', get_string('description'), ['rows' => 4], $this->editor_options());
        $mform->setType('description_editor', PARAM_RAW);

        $repeat = [
            $mform->createElement('date_time_selector', 'sessionstart', get_string('sessionstart', 'mod_muappointments'), ['optional' => true]),
            $mform->createElement('date_time_selector', 'sessionfinish', get_string('sessionfinish', 'mod_muappointments'), ['optional' => true]),
        ];
        $repeatno = 1;
        if ($appointmentid) {
            $repeatno = count(appointment_service::sessions($appointmentid));
        }
        $next = schedule::next_full_hour(time(), \core_date::get_user_timezone_object());
        $options = [
            'sessionstart' => ['default' => $next],
            'sessionfinish' => ['default' => $next + HOURSECS],
        ];
        $this->repeat_elements(
            $repeat,
            $repeatno,
            $options,
            'sessioncount',
            'sessionadd',
            1,
            get_string('addsession', 'mod_muappointments'),
            true,
            'deletesession'
        );

        $handler = appointment_handler::create();
        $handler->instance_form_definition($mform, $appointmentid);
    }

    /**
     * Load an existing appointment.
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;
        $appointmentid = $this->optional_param('appointmentid', 0, PARAM_INT);
        if (!$appointmentid) {
            return;
        }
        $record = $DB->get_record('muappointments_appointment', ['id' => $appointmentid], '*', MUST_EXIST);
        $record->appointmentid = $record->id;
        $record->cmid = $this->cmid();
        $index = 0;
        foreach (appointment_service::sessions($appointmentid) as $session) {
            $record->{'sessionstart[' . $index . ']'} = $session->timestart;
            $record->{'sessionfinish[' . $index . ']'} = $session->timefinish;
            $index++;
        }
        $context = $this->get_context_for_dynamic_submission();
        file_prepare_standard_editor(
            $record,
            'description',
            $this->editor_options(),
            $context,
            'mod_muappointments',
            'appointment',
            $record->id
        );
        $handler = appointment_handler::create();
        $handler->instance_form_before_set_data($record);
        $this->set_data($record);
    }

    /**
     * Custom field elements that need the draft data.
     */
    public function definition_after_data(): void {
        parent::definition_after_data();
        $handler = appointment_handler::create();
        $handler->instance_form_definition_after_data(
            $this->_form,
            $this->optional_param('appointmentid', 0, PARAM_INT)
        );
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!isset($data['capacity']) || (int) $data['capacity'] < 0) {
            $errors['capacity'] = get_string('invalidcapacity', 'mod_muappointments');
        }
        $starts = $data['sessionstart'] ?? [];
        $finishes = $data['sessionfinish'] ?? [];
        foreach ($starts as $index => $start) {
            $finish = $finishes[$index] ?? 0;
            if (!$start && !$finish) {
                continue;
            }
            if (!$start || !$finish || (int) $finish <= (int) $start) {
                $errors['sessionfinish[' . $index . ']'] = get_string('invalidsessiontime', 'mod_muappointments');
            }
        }
        $handler = appointment_handler::create();
        return array_merge($errors, $handler->instance_form_validation($data, $files));
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        global $DB;
        $data = $this->get_data();
        $activity = $this->activity_record();
        $sessions = self::sessions_from_data($data);
        $text = $data->description_editor['text'] ?? '';
        $format = (int) ($data->description_editor['format'] ?? FORMAT_HTML);
        $handler = appointment_handler::create();
        if (empty($data->appointmentid)) {
            $id = appointment_service::create(
                (int) $activity->id,
                (int) $data->capacity,
                !empty($data->waitlist),
                !empty($data->allowcancel),
                $text,
                $format,
                $sessions
            );
        } else {
            $previous = appointment_service::field_values((int) $data->appointmentid);
            $result = appointment_service::update(
                (int) $data->appointmentid,
                (int) $data->capacity,
                !empty($data->waitlist),
                !empty($data->allowcancel),
                $text,
                $format,
                $sessions
            );
            $id = (int) $data->appointmentid;
        }
        $data->id = $id;
        $data = file_postupdate_standard_editor(
            $data,
            'description',
            $this->editor_options(),
            $this->get_context_for_dynamic_submission(),
            'mod_muappointments',
            'appointment',
            $id
        );
        $DB->update_record('muappointments_appointment', (object) [
            'id' => $id,
            'description' => $data->description,
            'descriptionformat' => $data->descriptionformat,
        ]);
        $handler->instance_form_save($data);
        if (!empty($data->appointmentid)) {
            appointment_service::publish_edits($id, $result, $previous);
        }
        return ['id' => $id];
    }

    /**
     * @param \stdClass $data
     * @return array<int, array{timestart: int, timefinish: int}>
     */
    public static function sessions_from_data(\stdClass $data): array {
        $starts = $data->sessionstart ?? [];
        $finishes = $data->sessionfinish ?? [];
        if (!is_array($starts)) {
            return [];
        }
        $sessions = [];
        foreach ($starts as $index => $start) {
            $finish = $finishes[$index] ?? 0;
            if ($start && $finish && (int) $finish > (int) $start) {
                $sessions[] = ['timestart' => (int) $start, 'timefinish' => (int) $finish];
            }
        }
        return $sessions;
    }

    /**
     * @return array
     */
    private function editor_options(): array {
        return [
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => 0,
            'context' => $this->get_context_for_dynamic_submission(),
            'subdirs' => false,
        ];
    }
}
