// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Student tools on cards: "Ask my teacher", "My notes" and the confidence check.
 *
 * Cards are replaced whenever their section is re-rendered (format_spiffy/inline), so every
 * handler is delegated from the page root, and results are shown in place in the card: nothing
 * is re-rendered here. The forms use data-spiffy-tool, not data-spiffy-action, so the inline
 * module leaves them alone. A question is only sent after the confirmation step
 * ("Are you sure you're ready to send?"); notes and confidence checks are saved straight away.
 *
 * @module     format_spiffy/tools
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';

const SELECTORS = {
    FORM: 'form[data-spiffy-tool]',
    CONFIRM_BUTTON: '[data-spiffy-tool-confirm]',
    CONFIRM: '[data-region="spiffy-ask-confirm"]',
    ACTIONS: '[data-region="spiffy-ask-actions"]',
    STATUS: '[data-region="spiffy-tool-status"]',
    PHOTO: '[data-region="spiffy-ask-photo"]',
    CONFIDENCE: '[data-region="spiffy-confidence"]',
    CONFIDENCE_OPTION: '[data-spiffy-confidence]',
};

/**
 * Show a message in the status line of a tool (an aria-live region).
 *
 * @param {HTMLElement} container the form or group
 * @param {string} message
 */
const setStatus = (container, message) => {
    const status = container.querySelector(SELECTORS.STATUS);
    if (status) {
        status.textContent = message;
    }
};

/**
 * Mark a tool busy (so the live refresh leaves its section alone) and disable its controls.
 *
 * @param {HTMLElement} container
 * @param {boolean} busy
 */
const setBusy = (container, busy) => {
    if (busy) {
        container.dataset.spiffyBusy = '1';
        container.setAttribute('aria-busy', 'true');
    } else {
        delete container.dataset.spiffyBusy;
        container.removeAttribute('aria-busy');
    }
    container.querySelectorAll('button, textarea, select').forEach((control) => {
        control.disabled = busy;
    });
};

/**
 * Call one web service of the tools.
 *
 * @param {string} methodname
 * @param {Object} args
 * @returns {Promise<Object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Save the note of a card.
 *
 * @param {HTMLFormElement} form
 */
const saveNote = async(form) => {
    if (form.dataset.spiffyBusy) {
        return;
    }
    const field = form.elements.note;
    setBusy(form, true);
    try {
        const response = await call('format_spiffy_save_note', {cmid: Number(form.dataset.cmid), note: field.value});
        field.value = field.value.trim();
        field.defaultValue = field.value;
        setBusy(form, false);
        setStatus(form, response.message);
        field.focus();
    } catch (error) {
        setBusy(form, false);
        Notification.exception(error);
    }
};

/**
 * Show or hide the confirmation step of a question.
 *
 * @param {HTMLFormElement} form
 * @param {boolean} show
 */
const toggleConfirm = (form, show) => {
    const confirm = form.querySelector(SELECTORS.CONFIRM);
    const actions = form.querySelector(SELECTORS.ACTIONS);
    confirm.hidden = !show;
    actions.hidden = show;
    form.elements.message.readOnly = show;
    if (show) {
        form.dataset.spiffyConfirming = '1';
        confirm.querySelector('[tabindex="-1"]').focus();
    } else {
        delete form.dataset.spiffyConfirming;
    }
};

/**
 * The first step of asking: check there is a question, then ask for confirmation.
 *
 * @param {HTMLFormElement} form
 */
const confirmQuestion = async(form) => {
    const field = form.elements.message;
    if (!field.value.trim()) {
        setStatus(form, await getString('questionempty', 'format_spiffy'));
        field.focus();
        return;
    }
    setStatus(form, '');
    toggleConfirm(form, true);
};

/**
 * Send the confirmed question to the teacher.
 *
 * @param {HTMLFormElement} form
 */
const sendQuestion = async(form) => {
    if (form.dataset.spiffyBusy) {
        return;
    }
    const field = form.elements.message;
    const args = {
        cmid: Number(form.dataset.cmid),
        teacherid: Number(form.elements.teacherid.value),
        message: field.value,
    };
    setBusy(form, true);
    try {
        const response = await call('format_spiffy_ask_teacher', args);
        setBusy(form, false);
        field.value = '';
        field.defaultValue = '';
        toggleConfirm(form, false);
        setStatus(form, response.message);
        addToast(response.message);
        form.querySelector(`${SELECTORS.ACTIONS} button`)?.focus();
    } catch (error) {
        setBusy(form, false);
        toggleConfirm(form, false);
        field.focus();
        Notification.exception(error);
    }
};

/**
 * Record the confidence check of a card.
 *
 * @param {HTMLButtonElement} button the face that was chosen
 */
const setConfidence = async(button) => {
    const group = button.closest(SELECTORS.CONFIDENCE);
    if (!group || group.dataset.spiffyBusy) {
        return;
    }
    const confidence = Number(button.dataset.spiffyConfidence);
    setBusy(group, true);
    try {
        const response = await call('format_spiffy_set_confidence', {cmid: Number(group.dataset.cmid), confidence});
        setBusy(group, false);
        group.querySelectorAll(SELECTORS.CONFIDENCE_OPTION).forEach((option) => {
            option.setAttribute('aria-pressed', option === button ? 'true' : 'false');
        });
        setStatus(group, response.message);
        button.focus();
    } catch (error) {
        setBusy(group, false);
        Notification.exception(error);
    }
};

/**
 * Set up the card tools for a Spiffy course page.
 *
 * @param {string} rootselector selector of the format's content root
 */
export const init = (rootselector) => {
    const root = document.querySelector(rootselector);
    if (!root || root.dataset.spiffyTools) {
        return;
    }
    root.dataset.spiffyTools = '1';

    root.addEventListener('submit', (event) => {
        const form = event.target.closest(SELECTORS.FORM);
        if (!form || !root.contains(form)) {
            return;
        }
        event.preventDefault();
        if (form.dataset.spiffyTool === 'note') {
            saveNote(form);
        } else if (form.dataset.spiffyTool === 'ask' && !form.dataset.spiffyConfirming) {
            confirmQuestion(form);
        }
    });

    root.addEventListener('click', (event) => {
        const confirm = event.target.closest(SELECTORS.CONFIRM_BUTTON);
        if (confirm && root.contains(confirm)) {
            const form = confirm.closest(SELECTORS.FORM);
            if (confirm.dataset.spiffyToolConfirm === 'send') {
                sendQuestion(form);
            } else {
                toggleConfirm(form, false);
                form.elements.message.focus();
            }
            return;
        }
        const option = event.target.closest(SELECTORS.CONFIDENCE_OPTION);
        if (option && root.contains(option)) {
            setConfidence(option);
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const form = event.target.closest(`${SELECTORS.FORM}[data-spiffy-confirming]`);
        if (form && root.contains(form) && !form.dataset.spiffyBusy) {
            event.preventDefault();
            toggleConfirm(form, false);
            form.elements.message.focus();
        }
    });

    root.addEventListener('change', (event) => {
        const select = event.target.closest(`${SELECTORS.FORM} select[name="teacherid"]`);
        if (!select || !root.contains(select)) {
            return;
        }
        const photo = select.closest(SELECTORS.FORM).querySelector(SELECTORS.PHOTO);
        const url = select.selectedOptions[0]?.dataset.pictureurl;
        if (photo && url) {
            photo.src = url;
        }
    });
};
