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
 * Generate appointments from timeframes.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\customfield\appointment_handler;
use mod_muappointments\local\appointment_service;

/**
 * Multiple appointments from one or more timeframes.
 */
class multiple_form extends activity_dynamic_form {
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
        $activity = $this->activity_record();

        $mform->addElement('hidden', 'cmid', $this->cmid());
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'capacity', get_string('capacity', 'mod_muappointments'));
        $mform->setType('capacity', PARAM_INT);
        $mform->setDefault('capacity', 10);
        $mform->addRule('capacity', null, 'required', null, 'client');

        $mform->addElement('advcheckbox', 'waitlist', get_string('enablewaitlist', 'mod_muappointments'));
        $mform->addElement('advcheckbox', 'allowcancel', get_string('allowcancel', 'mod_muappointments'));
        $mform->setDefault('allowcancel', (int) $activity->allowcanceldefault);

        $mform->addElement('textarea', 'description', get_string('description'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('description', PARAM_CLEANHTML);

        $mform->addElement('duration', 'split', get_string('splitlength', 'mod_muappointments'), [
            'optional' => true,
            'defaultunit' => MINSECS,
        ]);
        $mform->setDefault('split', HOURSECS);
        $mform->addHelpButton('split', 'splitlength', 'mod_muappointments');

        $mform->addElement('duration', 'breaklength', get_string('breaklength', 'mod_muappointments'), [
            'optional' => true,
            'defaultunit' => MINSECS,
        ]);
        $mform->setDefault('breaklength', 0);
        $mform->addHelpButton('breaklength', 'breaklength', 'mod_muappointments');

        $repeat = [
            $mform->createElement('date_time_selector', 'framestart', get_string('framestart', 'mod_muappointments')),
            $mform->createElement('date_time_selector', 'frameend', get_string('frameend', 'mod_muappointments')),
        ];
        $this->repeat_elements(
            $repeat,
            1,
            [],
            'framecount',
            'frameadd',
            1,
            get_string('addtimeframe', 'mod_muappointments'),
            true,
            'deleteframe'
        );

        $handler = appointment_handler::create();
        $handler->instance_form_definition($mform, 0);
    }

    /**
     * No existing record.
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
        if ((int) $data['capacity'] < 0) {
            $errors['capacity'] = get_string('invalidcapacity', 'mod_muappointments');
        }
        $starts = $data['framestart'] ?? [];
        $ends = $data['frameend'] ?? [];
        $any = false;
        foreach ($starts as $index => $start) {
            $end = $ends[$index] ?? 0;
            if (!$start && !$end) {
                continue;
            }
            $any = true;
            if (!$start || !$end || (int) $end <= (int) $start) {
                $errors['frameend[' . $index . ']'] = get_string('invalidtimeframe', 'mod_muappointments');
            }
        }
        if (!$any) {
            $errors['framestart[0]'] = get_string('invalidtimeframe', 'mod_muappointments');
        }
        $handler = appointment_handler::create();
        return array_merge($errors, $handler->instance_form_validation($data, $files));
    }

    /**
     * @return array
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();
        $activity = $this->activity_record();
        $frames = [];
        foreach ($data->framestart as $index => $start) {
            $end = $data->frameend[$index] ?? 0;
            if ($start && $end && (int) $end > (int) $start) {
                $frames[] = ['start' => (int) $start, 'end' => (int) $end];
            }
        }
        $ids = appointment_service::create_from_timeframes(
            (int) $activity->id,
            $frames,
            (int) $data->split,
            (int) $data->breaklength,
            (int) $data->capacity,
            !empty($data->waitlist),
            !empty($data->allowcancel),
            (string) ($data->description ?? ''),
            FORMAT_HTML,
        );
        $handler = appointment_handler::create();
        foreach ($ids as $id) {
            $data->id = $id;
            $handler->instance_form_save($data);
        }
        return ['ids' => $ids];
    }
}
