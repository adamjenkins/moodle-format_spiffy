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
 * The teacher side of the card view: teacher spotlight, quick actions and the pencil menu.
 *
 * Cards are replaced whenever their section is re-rendered (format_spiffy/inline), so every
 * handler is delegated from the page root. The pencil menu is a plain Bootstrap dropdown of links
 * and needs nothing here. Messaging the students who have not finished takes two steps: the
 * first call only counts them (dry run) and asks for confirmation, the second sends.
 *
 * Clicking a card's name in the teacher spotlight follows its #module-N link (so the spotlight
 * layout can react to the hash) and sends format_spiffy:focuscard {cmid} on the root, then puts
 * the keyboard focus on the card.
 *
 * @module     format_spiffy/teacher
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';
import {refresh} from 'format_spiffy/inline';

const SELECTORS = {
    MESSAGE_FORM: 'form[data-spiffy-teacher="message"]',
    CONFIRM_BUTTON: '[data-spiffy-teacher-confirm]',
    CONFIRM: '[data-region="spiffy-teacher-confirm"]',
    CONFIRM_TEXT: '[data-region="spiffy-teacher-confirm-text"]',
    ACTIONS: '[data-region="spiffy-teacher-message-actions"]',
    STATUS: '[data-region="spiffy-teacher-status"]',
    SEEN: '[data-spiffy-teacher-seen]',
    FOCUS: '[data-spiffy-focus]',
    SPOTLIGHT: '[data-region="spiffy-teacher-spotlight"]',
    CARD: '.spiffy-item[data-id]',
};

export const EVENTS = {
    FOCUS_CARD: 'format_spiffy:focuscard',
};

/**
 * Call one web service.
 *
 * @param {string} methodname
 * @param {Object} args
 * @returns {Promise<Object>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0];

/**
 * Show a message in the status line of a form (an aria-live region).
 *
 * @param {HTMLElement} form
 * @param {string} message
 */
const setStatus = (form, message) => {
    const status = form.querySelector(SELECTORS.STATUS);
    if (status) {
        status.textContent = message;
    }
};

/**
 * Mark a container busy (so the live refresh leaves its section alone) and disable its controls.
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
    const controls = container.matches('button') ? [container] : container.querySelectorAll('button, textarea');
    controls.forEach((control) => {
        control.disabled = busy;
    });
};

/**
 * Show or hide the confirmation step of a message.
 *
 * @param {HTMLFormElement} form
 * @param {string|null} text the question to confirm, or null to hide it
 */
const toggleConfirm = (form, text) => {
    const confirm = form.querySelector(SELECTORS.CONFIRM);
    const actions = form.querySelector(SELECTORS.ACTIONS);
    const show = text !== null;
    confirm.hidden = !show;
    actions.hidden = show;
    form.elements.message.readOnly = show;
    if (show) {
        form.dataset.spiffyConfirming = '1';
        const label = confirm.querySelector(SELECTORS.CONFIRM_TEXT);
        label.textContent = text;
        label.focus();
    } else {
        delete form.dataset.spiffyConfirming;
    }
};

/**
 * The first step: check there is a message and count who will get it, then ask for confirmation.
 *
 * @param {HTMLFormElement} form
 */
const confirmMessage = async(form) => {
    if (form.dataset.spiffyBusy) {
        return;
    }
    const field = form.elements.message;
    if (!field.value.trim()) {
        setStatus(form, await getString('teachermessageempty', 'format_spiffy'));
        field.focus();
        return;
    }
    setStatus(form, '');
    setBusy(form, true);
    try {
        const response = await call('format_spiffy_message_unfinished',
            {cmid: Number(form.dataset.cmid), message: field.value, dryrun: true});
        setBusy(form, false);
        if (response.count > 0) {
            toggleConfirm(form, response.message);
        } else {
            setStatus(form, response.message);
            field.focus();
        }
    } catch (error) {
        setBusy(form, false);
        Notification.exception(error);
    }
};

/**
 * Send the confirmed message.
 *
 * @param {HTMLElement} root
 * @param {HTMLFormElement} form
 */
const sendMessage = async(root, form) => {
    if (form.dataset.spiffyBusy) {
        return;
    }
    const field = form.elements.message;
    setBusy(form, true);
    try {
        const response = await call('format_spiffy_message_unfinished',
            {cmid: Number(form.dataset.cmid), message: field.value, dryrun: false});
        setBusy(form, false);
        field.value = '';
        field.defaultValue = '';
        toggleConfirm(form, null);
        setStatus(form, response.message);
        addToast(response.message);
        form.querySelector(`${SELECTORS.ACTIONS} button`)?.focus();
    } catch (error) {
        setBusy(form, false);
        toggleConfirm(form, null);
        field.focus();
        Notification.exception(error);
    }
};

/**
 * Mark the new questions on a card as seen, then update the spotlight and the sections.
 *
 * @param {HTMLElement} root
 * @param {HTMLButtonElement} button
 */
const markSeen = async(root, button) => {
    if (button.dataset.spiffyBusy) {
        return;
    }
    setBusy(button, true);
    try {
        const response = await call('format_spiffy_mark_questions_seen', {cmid: Number(button.dataset.cmid)});
        const spotlight = root.querySelector(SELECTORS.SPOTLIGHT);
        if (spotlight) {
            if (response.spotlight) {
                await Templates.replaceNode(spotlight, response.spotlight, '');
            } else {
                spotlight.remove();
            }
        }
        addToast(response.message);
        if (button.isConnected) {
            setBusy(button, false);
        }
        const card = button.closest(SELECTORS.CARD);
        await refresh(root);
        // The button is gone once the card is re-rendered: keep the focus on the card.
        const fresh = card ? root.querySelector(`${SELECTORS.CARD}[data-id="${CSS.escape(card.dataset.id)}"]`) : null;
        if (fresh && !fresh.contains(document.activeElement)) {
            fresh.setAttribute('tabindex', '-1');
            fresh.focus({preventScroll: true});
        }
    } catch (error) {
        if (button.isConnected) {
            setBusy(button, false);
        }
        Notification.exception(error);
    }
};

/**
 * Bring a card into view and give it the keyboard focus, after telling the layout about it.
 *
 * @param {HTMLElement} root
 * @param {string} cmid
 */
const focusCard = (root, cmid) => {
    root.dispatchEvent(new CustomEvent(EVENTS.FOCUS_CARD, {bubbles: true, detail: {cmid: Number(cmid)}}));
    // Let the layout move the card first.
    requestAnimationFrame(() => {
        const card = root.querySelector(`${SELECTORS.CARD}[data-id="${CSS.escape(String(cmid))}"]`);
        if (!card) {
            return;
        }
        if (!card.hasAttribute('tabindex')) {
            card.setAttribute('tabindex', '-1');
        }
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        card.scrollIntoView({behavior: reduce ? 'auto' : 'smooth', block: 'center'});
        card.focus({preventScroll: true});
    });
};

/**
 * Set up the teacher side for a Spiffy course page.
 *
 * @param {string} rootselector selector of the format's content root
 */
export const init = (rootselector) => {
    const root = document.querySelector(rootselector);
    if (!root || root.dataset.spiffyTeacher) {
        return;
    }
    root.dataset.spiffyTeacher = '1';

    root.addEventListener('submit', (event) => {
        const form = event.target.closest(SELECTORS.MESSAGE_FORM);
        if (!form || !root.contains(form)) {
            return;
        }
        event.preventDefault();
        if (!form.dataset.spiffyConfirming) {
            confirmMessage(form);
        }
    });

    root.addEventListener('click', (event) => {
        const confirm = event.target.closest(SELECTORS.CONFIRM_BUTTON);
        if (confirm && root.contains(confirm)) {
            const form = confirm.closest(SELECTORS.MESSAGE_FORM);
            if (confirm.dataset.spiffyTeacherConfirm === 'send') {
                sendMessage(root, form);
            } else {
                toggleConfirm(form, null);
                form.elements.message.focus();
            }
            return;
        }
        const seen = event.target.closest(SELECTORS.SEEN);
        if (seen && root.contains(seen)) {
            event.preventDefault();
            markSeen(root, seen);
            return;
        }
        const focus = event.target.closest(SELECTORS.FOCUS);
        if (focus && root.contains(focus) && !event.ctrlKey && !event.metaKey && !event.shiftKey) {
            // The link's own #module-N navigation still happens, for the hash and the back button.
            focusCard(root, focus.dataset.spiffyFocus);
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        const form = event.target.closest(`${SELECTORS.MESSAGE_FORM}[data-spiffy-confirming]`);
        if (form && root.contains(form) && !form.dataset.spiffyBusy) {
            event.preventDefault();
            toggleConfirm(form, null);
            form.elements.message.focus();
        }
    });
};
