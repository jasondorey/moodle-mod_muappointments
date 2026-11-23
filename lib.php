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
 * Activity API.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_muappointments\local\activity;
use mod_muappointments\local\calendar_service;
use mod_muappointments\local\notification_service;
use mod_muappointments\local\signup_service;

/**
 * @param string $feature
 * @return mixed
 */
function muappointments_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_OTHER;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        default:
            return null;
    }
}

/**
 * @param stdClass $data
 * @param moodleform_mod|null $mform
 * @return int
 */
function muappointments_add_instance($data, $mform = null) {
    global $DB;
    $record = activity::record_from_form($data, false);
    $record->id = $DB->insert_record('muappointments', $record);
    $data->id = $record->id;
    notification_service::save_from_form($data);
    return (int) $record->id;
}

/**
 * @param stdClass $data
 * @param moodleform_mod|null $mform
 * @return bool
 */
function muappointments_update_instance($data, $mform = null) {
    global $DB;
    $record = activity::record_from_form($data, true);
    $DB->update_record('muappointments', $record);
    $data->id = $record->id;
    notification_service::save_from_form($data);
    calendar_service::sync_activity((int) $record->id);
    return true;
}

/**
 * @param int $id
 * @return bool
 */
function muappointments_delete_instance($id) {
    return activity::delete_instance((int) $id);
}

/**
 * @param cm_info $cm
 * @return cached_cm_info|bool
 */
function muappointments_get_coursemodule_info($cm) {
    global $DB;
    $instance = $DB->get_record('muappointments', ['id' => $cm->instance],
        'id, name, intro, introformat, completionbook, completionattend');
    if (!$instance) {
        return false;
    }
    $info = new cached_cm_info();
    $info->name = $instance->name;
    if ($cm->showdescription) {
        $info->content = format_module_intro('muappointments', $instance, $cm->id, false);
    }
    if ((int) $cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionbook'] = $instance->completionbook;
        $info->customdata['customcompletionrules']['completionattend'] = $instance->completionattend;
    }
    return $info;
}

/**
 * Per-user seat count or next session date. Not cached with the course module info.
 *
 * @param cm_info $cm
 */
function muappointments_cm_info_view(cm_info $cm) {
    global $USER;
    if (!isloggedin() || isguestuser()) {
        return;
    }
    $signup = signup_service::active_signup((int) $cm->instance, (int) $USER->id);
    if ($signup) {
        $next = signup_service::next_session((int) $signup->appointmentid);
        if ($next) {
            $text = userdate((int) $next->timestart, get_string('strftimedatetimeshort', 'langconfig'));
        } else {
            $text = get_string('booked', 'mod_muappointments');
        }
    } else {
        $free = signup_service::count_free_seats((int) $cm->instance);
        $text = get_string('freeseats', 'mod_muappointments', $free);
    }
    $cm->set_after_link(html_writer::span($text, 'ms-2 text-muted muappointments-extra'));
}

/**
 * @param moodleform $mform
 */
function muappointments_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'muappointmentsheader', get_string('modulenameplural', 'mod_muappointments'));
    $mform->addElement('checkbox', 'reset_muappointments_sessions', get_string('reset:sessions', 'mod_muappointments'));
    $mform->addElement('checkbox', 'reset_muappointments_signups', get_string('reset:signups', 'mod_muappointments'));
}

/**
 * @param stdClass $course
 * @return array
 */
function muappointments_reset_course_form_defaults($course) {
    return [
        'reset_muappointments_sessions' => 0,
        'reset_muappointments_signups' => 0,
    ];
}

/**
 * @param stdClass $data
 * @return array
 */
function muappointments_reset_userdata($data) {
    global $DB;
    $status = [];
    if (empty($data->reset_muappointments_sessions) && empty($data->reset_muappointments_signups)) {
        return $status;
    }
    $instances = $DB->get_records('muappointments', ['course' => $data->courseid]);
    if (!empty($data->reset_muappointments_sessions)) {
        foreach ($instances as $instance) {
            activity::delete_appointments($instance, false);
        }
        $status[] = [
            'component' => get_string('modulenameplural', 'mod_muappointments'),
            'item' => get_string('reset:sessions', 'mod_muappointments'),
            'error' => false,
        ];
        return $status;
    }
    foreach ($instances as $instance) {
        activity::delete_signups($instance);
    }
    $status[] = [
        'component' => get_string('modulenameplural', 'mod_muappointments'),
        'item' => get_string('reset:signups', 'mod_muappointments'),
        'error' => false,
    ];
    return $status;
}

/**
 * @param int $courseid
 * @param stdClass|int|null $instance
 * @param int|stdClass|null $cm
 */
function muappointments_refresh_events($courseid = 0, $instance = null, $cm = null) {
    global $DB;
    if ($instance) {
        if (is_object($instance)) {
            $instance = $instance->id;
        }
        calendar_service::sync_activity((int) $instance);
        return;
    }
    $params = [];
    $sql = 'SELECT id FROM {muappointments}';
    if ($courseid) {
        $sql .= ' WHERE course = :course';
        $params['course'] = $courseid;
    }
    $ids = $DB->get_fieldset_sql($sql, $params);
    foreach ($ids as $id) {
        calendar_service::sync_activity((int) $id);
    }
}

/**
 * Serve appointment description and intro files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function muappointments_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    require_login($course, true, $cm);
    if (!has_capability('mod/muappointments:view', $context)) {
        return false;
    }
    if ($filearea === 'intro') {
        $itemid = 0;
    } else if ($filearea === 'appointment') {
        $itemid = (int) array_shift($args);
    } else {
        return false;
    }
    $filename = array_pop($args);
    if ($filename === null) {
        return false;
    }
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_muappointments', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
