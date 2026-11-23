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
 * Shared dynamic-form context.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_muappointments\form;

use mod_muappointments\local\activity;

/**
 * Base for dialogs opened from an activity page.
 */
abstract class activity_dynamic_form extends \core_form\dynamic_form {
    /**
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        $cm = get_coursemodule_from_id('muappointments', $this->cmid(), 0, false, MUST_EXIST);
        return \context_module::instance($cm->id);
    }

    /**
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/mod/muappointments/view.php', ['id' => $this->cmid()]);
    }

    /**
     * @return int
     */
    protected function cmid(): int {
        return $this->optional_param('cmid', 0, PARAM_INT);
    }

    /**
     * @return \stdClass
     */
    protected function activity_record(): \stdClass {
        $cm = get_coursemodule_from_id('muappointments', $this->cmid(), 0, false, MUST_EXIST);
        return activity::get((int) $cm->instance);
    }
}
