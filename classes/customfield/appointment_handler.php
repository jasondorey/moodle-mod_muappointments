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
 * Custom field handler. Area component mod_muappointments, area appointment.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\customfield;

use context;
use context_module;
use context_system;
use core_customfield\field_controller;
use core_customfield\handler;
use moodle_url;

/**
 * Appointment custom fields. Instance id is the appointment id.
 */
class appointment_handler extends handler {
    /**
     * @return bool
     */
    public function can_configure(): bool {
        return has_capability('mod/muappointments:managecustomfields', $this->get_configuration_context());
    }

    /**
     * @param field_controller $field
     * @param int $instanceid
     * @return bool
     */
    public function can_edit(field_controller $field, int $instanceid = 0): bool {
        if ($instanceid === 0) {
            return has_capability('mod/muappointments:editsessions', $this->get_instance_context(0));
        }
        return has_capability('mod/muappointments:editsessions', $this->get_instance_context($instanceid));
    }

    /**
     * @param field_controller $field
     * @param int $instanceid
     * @return bool
     */
    public function can_view(field_controller $field, int $instanceid): bool {
        return has_capability('mod/muappointments:view', $this->get_instance_context($instanceid));
    }

    /**
     * @return context
     */
    public function get_configuration_context(): context {
        return context_system::instance();
    }

    /**
     * @return moodle_url
     */
    public function get_configuration_url(): moodle_url {
        return new moodle_url('/mod/muappointments/customfield.php');
    }

    /**
     * @param int $instanceid Appointment id, or 0 while it is being created.
     * @return context
     */
    public function get_instance_context(int $instanceid = 0): context {
        global $DB, $PAGE;
        if ($instanceid > 0) {
            $appointment = $DB->get_record('muappointments_appointment', ['id' => $instanceid], 'muappointmentsid', MUST_EXIST);
            $cm = get_coursemodule_from_instance('muappointments', $appointment->muappointmentsid, 0, false, MUST_EXIST);
            return context_module::instance($cm->id);
        }
        // A new appointment is edited inside the activity. The dynamic form sets that page context.
        if (!empty($PAGE->context) && $PAGE->context instanceof context_module) {
            return $PAGE->context;
        }
        return context_system::instance();
    }

    /**
     * Location, venue, and room. An admin may delete them later.
     */
    public static function create_default_fields(): void {
        global $DB;
        if ($DB->record_exists('customfield_category', [
            'component' => 'mod_muappointments',
            'area' => 'appointment',
            'itemid' => 0,
        ])) {
            return;
        }
        $handler = self::create();
        $categoryid = $handler->create_category(get_string('customfieldcategory', 'mod_muappointments'));
        $category = \core_customfield\category_controller::create($categoryid);
        $fields = [
            'location' => get_string('field:location', 'mod_muappointments'),
            'venue' => get_string('field:venue', 'mod_muappointments'),
            'room' => get_string('field:room', 'mod_muappointments'),
        ];
        foreach ($fields as $shortname => $name) {
            $field = field_controller::create(0, (object) [
                'type' => 'text',
                'shortname' => $shortname,
                'name' => $name,
            ], $category);
            \core_customfield\api::save_field_configuration($field, (object) [
                'name' => $name,
                'shortname' => $shortname,
                'type' => 'text',
                'description' => '',
                'configdata' => [
                    'required' => 0,
                    'uniquevalues' => 0,
                    'locked' => 0,
                    'visibility' => 2,
                    'defaultvalue' => '',
                    'displaysize' => 50,
                    'maxlength' => 1333,
                    'ispassword' => 0,
                    'link' => '',
                ],
            ]);
        }
    }
}
