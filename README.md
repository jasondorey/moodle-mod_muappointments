# Moodle-mod_muappointments

Appointment booking activity for Moodle 5.3.

An appointment activity holds one or more appointments. Each appointment has zero or more sessions. People sign up for the appointment, not for a single session. Teachers add appointments, take attendance, and read the attendee and cancellation lists inside the activity. Learners book a seat, join the wait list, or register interest before any session exists.

The plugin uses Moodle core APIs only. It does not depend on MuTMS or Moodle Workplace.

Install this directory as `public/mod/muappointments`.

Requires Moodle 5.3 (`$plugin->requires` 2026100500, `$plugin->supported` 503).

PHPUnit tests in `tests/` run inside a Moodle 5.3 development site (`vendor/bin/phpunit mod/muappointments`). They cover the last seat, wait-list order, promotion, one signup per activity, both completion rules, duplicate date offset, split and break including overrun, calendar duplicate suppression, and a reminder sent once.
