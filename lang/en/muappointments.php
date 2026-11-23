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
 * English strings.
 *
 * @package   mod_muappointments
 * @copyright 2026 Jason Dorey
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Muappointments';
$string['modulename'] = 'Appointment';
$string['modulenameplural'] = 'Appointments';
$string['modulename_help'] = 'An appointment activity holds one or more appointments. People sign up for the appointment. Each appointment can have several sessions.';
$string['pluginadministration'] = 'Appointment administration';
$string['muappointments:addinstance'] = 'Add a new appointment activity';
$string['muappointments:view'] = 'View appointment activity';
$string['muappointments:signup'] = 'Sign up for an appointment';
$string['muappointments:editsessions'] = 'Add, edit, duplicate, and delete appointments';
$string['muappointments:configurecancellation'] = 'Configure the default cancellation setting';
$string['muappointments:addattendees'] = 'Add attendees to an appointment';
$string['muappointments:removeattendees'] = 'Remove attendees from an appointment';
$string['muappointments:takeattendance'] = 'Take attendance';
$string['muappointments:viewattendees'] = 'View the attendee list';
$string['muappointments:viewcancellations'] = 'View cancellations';
$string['muappointments:overbook'] = 'Sign up when the appointment is full';
$string['muappointments:managecustomfields'] = 'Manage appointment custom fields';

$string['settings'] = 'Settings';
$string['separateemailperday'] = 'Separate email for each day';
$string['separateemailperday_desc'] = 'When an appointment has sessions on more than one day, send one message per calendar day instead of one message covering every session. Default is off.';
$string['managecustomfields'] = 'Manage custom fields';
$string['customfieldcategory'] = 'Appointment';
$string['field:location'] = 'Location';
$string['field:venue'] = 'Venue';
$string['field:room'] = 'Room';

$string['thirdpartyemails'] = 'Third-party email address(es)';
$string['thirdpartyemails_help'] = 'Comma-separated email addresses. These are not Moodle accounts. They receive confirmation, update, and cancellation for booked signups. They do not receive reminders.';
$string['thirdpartywaitlist'] = 'Notify third-party about wait-listed sessions';
$string['thirdpartywaitlist_help'] = 'When enabled, the third-party addresses also receive wait-list messages. Default is off.';
$string['allowcanceldefault'] = 'Allow cancelling default';
$string['calendar'] = 'Calendar';
$string['calendardisplay'] = 'Calendar display';
$string['calendardisplay_help'] = 'Site publishes one site event per session. Course publishes one course event per session. None publishes no shared event. Default is Course.';
$string['calendardisplay:site'] = 'Site';
$string['calendardisplay:course'] = 'Course';
$string['calendardisplay:none'] = 'None';
$string['showoncalendar'] = 'Show entry on user\'s calendar';
$string['showoncalendar_help'] = 'When on, each booked attendee also gets a user event per session. Turn this off to avoid a second copy beside a site or course event. Wait-listed users do not get a user event until they are promoted.';
$string['shortname'] = 'Short name';
$string['shortname_help'] = 'Name used for calendar events. If this is empty, the activity name is used.';
$string['notifications'] = 'Customised notifications';
$string['messagebody'] = 'Message';
$string['reminderperiod'] = 'Days before message is sent';
$string['reminderperiod_help'] = 'How many days before the earliest session the reminder is sent. Wait-listed users and third parties do not receive it. Zero sends no reminder.';
$string['template:confirmation'] = 'Confirmation subject';
$string['template:update'] = 'Update subject';
$string['template:reminder'] = 'Reminder subject';
$string['template:waitlisted'] = 'Wait-listed subject';
$string['template:cancellation'] = 'Cancellation subject';

$string['completionbook'] = 'Learner must book an appointment';
$string['completionattend'] = 'Learner must book and attend';
$string['completiondetail:book'] = 'Book an appointment';
$string['completiondetail:attend'] = 'Book an appointment and attend';

$string['capacity'] = 'Capacity';
$string['enablewaitlist'] = 'Enable waitlist';
$string['allowcancel'] = 'Allow cancelling';
$string['description'] = 'Description';
$string['sessionstart'] = 'Session start';
$string['sessionfinish'] = 'Session finish';
$string['addsession'] = 'Add session';
$string['deletesession'] = 'Remove session';
$string['addappointment'] = 'Add appointment';
$string['addmultiple'] = 'Add multiple appointments';
$string['splitlength'] = 'Automatic split';
$string['splitlength_help'] = 'Length of each generated appointment. Leave empty or set to zero to create one appointment covering the whole timeframe.';
$string['breaklength'] = 'Break';
$string['breaklength_help'] = 'Gap between generated appointments. Zero places them back to back. An appointment that starts inside the timeframe and runs past the end is still created.';
$string['framestart'] = 'Timeframe start';
$string['frameend'] = 'Timeframe end';
$string['addtimeframe'] = 'Add timeframe';
$string['duplicate'] = 'Duplicate';
$string['currentstart'] = 'Current start';
$string['newstart'] = 'New start';
$string['cancelreason'] = 'Reason for cancelling';
$string['suppressemail'] = 'Suppress email notifications';
$string['attendees'] = 'Attendees';
$string['addattendees'] = 'Add attendees';
$string['cancellations'] = 'Cancellations';
$string['takeattendance'] = 'Take attendance';
$string['remove'] = 'Remove';
$string['book'] = 'Book';
$string['booked'] = 'Booked';
$string['bookedby'] = 'Added by';
$string['joinwaitlist'] = 'Join waitlist';
$string['registerinterest'] = 'Register interest';
$string['overbook'] = 'Overbook';
$string['confirmoverbook'] = 'Sign up even though this appointment is full?';
$string['confirmdeleteappointment'] = 'Delete this appointment and its signups?';
$string['showdetails'] = 'Details';
$string['datetime'] = 'Date and time';
$string['bookedcapacity'] = 'Booked / capacity';
$string['status'] = 'Status';
$string['noappointments'] = 'There are no appointments yet.';
$string['noattendees'] = 'Nobody is signed up.';
$string['nocancellations'] = 'There are no cancellations.';
$string['nodate'] = 'No date';
$string['signupsaved'] = 'Your signup has been saved.';
$string['freeseats'] = '{$a} seats available';
$string['moresessions'] = '(+{$a} more)';

$string['display:open'] = 'Open';
$string['display:full'] = 'Full';
$string['display:inprogress'] = 'Session in progress';
$string['display:finished'] = 'Finished';
$string['status:booked'] = 'Booked';
$string['status:waitlisted'] = 'Wait-listed';
$string['status:usercancelled'] = 'User cancelled';
$string['status:noshow'] = 'No show';
$string['status:partiallyattended'] = 'Partially attended';
$string['status:fullyattended'] = 'Fully attended';

$string['appointmentfull'] = 'This appointment is full.';
$string['signupclosed'] = 'Sign-up is closed because a session is in progress or finished.';
$string['alreadybooked'] = 'You already have a signup in this activity.';
$string['cancelnotallowed'] = 'This appointment does not allow cancelling.';
$string['alreadycancelled'] = 'This signup is already cancelled.';
$string['overbooknotallowed'] = 'Overbooking is only available when the appointment is full and the wait list is off.';
$string['attendancenotopen'] = 'Attendance can be taken only after a session has started.';
$string['invalidattendance'] = 'That attendance status cannot be saved.';
$string['notenrolled'] = 'The user is not enrolled in this course.';
$string['couldnotlock'] = 'Could not lock the appointment to check remaining seats. Try again.';
$string['invalidcapacity'] = 'Capacity must be zero or greater.';
$string['invalidsessiontime'] = 'The session finish must be after the start.';
$string['invalidtimeframe'] = 'The timeframe end must be after the start.';
$string['invalidreminderperiod'] = 'Days before the reminder must be zero or greater.';

$string['reset:sessions'] = 'Remove all sessions';
$string['reset:signups'] = 'Remove all session sign-ups';

$string['task:sendreminders'] = 'Send appointment reminders';
$string['event:appointmentcreated'] = 'Appointment created';
$string['event:appointmentupdated'] = 'Appointment updated';
$string['event:appointmentdeleted'] = 'Appointment deleted';
$string['event:signupcreated'] = 'Appointment signup created';
$string['event:signupcancelled'] = 'Appointment signup cancelled';
$string['event:attendancetaken'] = 'Appointment attendance taken';

$string['privacy:metadata:signup'] = 'Signups stored for this activity.';
$string['privacy:metadata:signup:userid'] = 'The user who signed up.';
$string['privacy:metadata:signup:status'] = 'Current signup status, including attendance.';
$string['privacy:metadata:signup:cancelnote'] = 'Optional cancellation reason.';
$string['privacy:metadata:signup:bookedby'] = 'The user who created the signup.';
$string['privacy:metadata:signup:remindersent'] = 'When the reminder was sent.';
$string['privacy:metadata:signup:timecreated'] = 'When the signup was created.';
$string['privacy:metadata:signup:timemodified'] = 'When the signup was last changed.';
$string['privacy:metadata:status'] = 'History of signup status changes.';
$string['privacy:metadata:status:status'] = 'The status that was recorded.';
$string['privacy:metadata:status:note'] = 'Note stored with the status change.';
$string['privacy:metadata:status:userid'] = 'The user who recorded the change.';
$string['privacy:metadata:status:timecreated'] = 'When the change was recorded.';

$string['default:confirmation:subject'] = 'Appointment confirmation: [appointmentname]';
$string['default:confirmation:body'] = 'Dear [firstname] [lastname],

You are booked on [appointmentname].

[details]

Date: [sessiondate]
Start: [starttime]
Finish: [finishtime]
Duration: [duration]

All sessions:
[alldates]

Location: [session:location]
Venue: [session:venue]
Room: [session:room]

Attendees: [attendeeslink]';
$string['default:update:subject'] = 'Appointment updated: [appointmentname]';
$string['default:update:body'] = 'Dear [firstname] [lastname],

[appointmentname] has been updated.

[details]

Date: [sessiondate]
Start: [starttime]
Finish: [finishtime]
Duration: [duration]

All sessions:
[alldates]

Location: [session:location]
Venue: [session:venue]
Room: [session:room]';
$string['default:reminder:subject'] = 'Appointment reminder: [appointmentname]';
$string['default:reminder:body'] = 'Dear [firstname] [lastname],

This is a reminder [reminderperiod] day(s) before [appointmentname].

[details]

Date: [sessiondate]
Start: [starttime]
Finish: [finishtime]
Duration: [duration]

All sessions:
[alldates]

Location: [session:location]
Venue: [session:venue]
Room: [session:room]';
$string['default:waitlisted:subject'] = 'Appointment wait list: [appointmentname]';
$string['default:waitlisted:body'] = 'Dear [firstname] [lastname],

You are on the wait list for [appointmentname]. You will be booked, in signup order, if a seat opens.

[details]

Date: [sessiondate]
Start: [starttime]
Finish: [finishtime]

All sessions:
[alldates]

Location: [session:location]
Venue: [session:venue]
Room: [session:room]';
$string['default:cancellation:subject'] = 'Appointment cancellation: [appointmentname]';
$string['default:cancellation:body'] = 'Dear [firstname] [lastname],

Your signup for [appointmentname] has been cancelled.

[details]

Date: [sessiondate]
Start: [starttime]
Finish: [finishtime]

All sessions:
[alldates]';
