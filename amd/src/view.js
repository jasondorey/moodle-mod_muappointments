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
 * Expand rows and open the appointment dialogs.
 *
 * @module     mod_muappointments/view
 * @copyright  2026 Jason Dorey
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalForm from 'core_form/modalform';

const forms = {
    add: 'mod_muappointments\\form\\appointment_form',
    edit: 'mod_muappointments\\form\\appointment_form',
    addmultiple: 'mod_muappointments\\form\\multiple_form',
    duplicate: 'mod_muappointments\\form\\duplicate_form',
    cancel: 'mod_muappointments\\form\\cancel_form',
    attendance: 'mod_muappointments\\form\\attendance_form',
    addattendees: 'mod_muappointments\\form\\add_attendees_form',
};

/**
 * @param {Number} cmid Course module id.
 */
export const init = (cmid) => {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-action]');
        if (!trigger) {
            return;
        }
        const action = trigger.dataset.action;
        if (action === 'toggle') {
            event.preventDefault();
            const id = trigger.dataset.appointmentid;
            const details = document.querySelector(`[data-region="details"][data-appointmentid="${id}"]`);
            if (!details) {
                return;
            }
            details.classList.toggle('d-none');
            const expanded = trigger.getAttribute('aria-expanded') === 'true';
            trigger.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            return;
        }
        if (!forms[action]) {
            return;
        }
        event.preventDefault();
        const form = new ModalForm({
            formClass: forms[action],
            args: {
                cmid: cmid,
                appointmentid: trigger.dataset.appointmentid || 0,
                signupid: trigger.dataset.signupid || 0,
            },
            modalConfig: {
                title: trigger.dataset.title || trigger.textContent.trim(),
            },
        });
        form.addEventListener(form.events.FORM_SUBMITTED, () => window.location.reload());
        form.show();
    });
};
