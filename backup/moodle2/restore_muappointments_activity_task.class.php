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
 * Restore task.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/muappointments/backup/moodle2/restore_muappointments_stepslib.php');

/**
 * Appointment activity restore.
 */
class restore_muappointments_activity_task extends restore_activity_task {
    /**
     * No extra settings.
     */
    protected function define_my_settings() {
    }

    /**
     * One structure step.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_muappointments_activity_structure_step('muappointments_structure', 'muappointments.xml'));
    }

    /**
     * @param string $content
     * @return string
     */
    public static function define_decode_contents() {
        $contents = [];
        $contents[] = new restore_decode_content('muappointments', ['intro'], 'muappointments');
        $contents[] = new restore_decode_content('muappointments_appointment', ['description'], 'muappointments_appointment');
        $contents[] = new restore_decode_content('muappointments_notify', ['body'], 'muappointments_notify');
        return $contents;
    }

    /**
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('MUAPPOINTMENTSVIEWBYID', '/mod/muappointments/view.php?id=$1', 'course_module'),
        ];
    }
}
