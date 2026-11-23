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
 * Site settings: one checkbox, plus the custom field page.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('modsettings', new admin_category(
        'modmuappointmentsfolder',
        new lang_string('pluginname', 'mod_muappointments'),
        $module->is_enabled() === false
    ));

    $settingspage = new admin_settingpage(
        'modsettingmuappointments',
        new lang_string('settings', 'mod_muappointments'),
        'moodle/site:config',
        $module->is_enabled() === false
    );
    if ($ADMIN->fulltree) {
        $settingspage->add(new admin_setting_configcheckbox(
            'mod_muappointments/separateemailperday',
            new lang_string('separateemailperday', 'mod_muappointments'),
            new lang_string('separateemailperday_desc', 'mod_muappointments'),
            0
        ));
    }
    $ADMIN->add('modmuappointmentsfolder', $settingspage);

    $ADMIN->add('modmuappointmentsfolder', new admin_externalpage(
        'mod_muappointments_customfield',
        new lang_string('managecustomfields', 'mod_muappointments'),
        new moodle_url('/mod/muappointments/customfield.php'),
        'mod/muappointments:managecustomfields',
        $module->is_enabled() === false
    ));
}

// The category above owns the pages. Do not also add the default settings node.
$settings = null;
