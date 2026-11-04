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
 * Inline card actions and live sections.
 *
 * Forms and buttons in a card that carry data-spiffy-action are sent to
 * format_spiffy_inline_action; the sections that come back replace the ones on the page, so
 * steps unlock, hints move and progress updates without a reload. While the page is open and
 * visible, the sections are refreshed now and then, so a classmate's post or a teacher's grade
 * shows up; a section the user is typing in is left alone.
 *
 * An element in a card with data-spiffy-module="format_spiffy/<name>" gets that AMD module's
 * init(element) called once, on page load and whenever its section is replaced; inline views
 * that need more than plain forms use this, since section HTML carries no {{#js}}.
 *
 * Other modules listen to the root for:
 * - format_spiffy:sectionsupdated, detail {sectionids: number[]}, after sections were replaced;
 * - format_spiffy:sectioncomplete, detail {section: HTMLElement}, when a section became complete.
 *
 * @module     format_spiffy/inline
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {add as addToast} from 'core/toast';
import {getCurrentCourseEditor} from 'core_courseformat/courseeditor';
import CourseEvents from 'core_course/events';

/** @var {number} How often open sections are refreshed, in milliseconds. */
const REFRESH_INTERVAL = 60000;

const SELECTORS = {
    ACTION: '[data-spiffy-action]',
    SECTION: 'section.spiffy-section[data-sectionid]',
    CARD: '.spiffy-item[data-id]',
};

export const EVENTS = {
    SECTIONS_UPDATED: 'format_spiffy:sectionsupdated',
    SECTION_COMPLETE: 'format_spiffy:sectioncomplete',
};

/**
 * Collect the fields of a form (and the button that sent it) as an object of strings and
 * arrays of strings. Names ending in [] and repeated names become arrays.
 *
 * @param {HTMLFormElement} form
 * @param {HTMLElement|null} submitter
 * @returns {Object}
 */
const formData = (form, submitter) => {
    const data = {};
    const fields = new FormData(form, submitter && submitter.name ? submitter : undefined);
    for (const [rawname, value] of fields.entries()) {
        if (typeof value !== 'string') {
            continue;
        }
        const list = rawname.endsWith('[]');
        const name = list ? rawname.slice(0, -2) : rawname;
        if (list || name in data) {
            data[name] = [].concat(data[name] ?? [], value);
        } else {
            data[name] = value;
        }
    }
    return data;
};

/**
 * The section whose page this is (course/section.php), or 0 on the course page.
 *
 * @param {HTMLElement} root
 * @returns {number}
 */
const pageSection = (root) => Number(root.dataset.spiffySectionid || 0);

/**
 * The sections of the root, by id.
 *
 * @param {HTMLElement} root
 * @returns {Map<string, HTMLElement>}
 */
const sectionMap = (root) => new Map(
    [...root.querySelectorAll(SELECTORS.SECTION)].map((section) => [section.dataset.sectionid, section])
);

/**
 * Whether the user is busy in a section: typing in it, or it has an action under way.
 *
 * @param {HTMLElement} section
 * @returns {boolean}
 */
const isBusy = (section) => {
    // An open "Read more", a playing video or a PDF would be reset by a replacement.
    if (section.querySelector('[data-spiffy-busy], details.spiffy-readmore[open]')) {
        return true;
    }
    const active = document.activeElement;
    if (active && section.contains(active) && active.matches('input, textarea, select, iframe, object, [contenteditable]')) {
        return true;
    }
    return [...section.querySelectorAll('textarea, input[type="text"]')].some((field) => field.value !== field.defaultValue);
};

/**
 * Replace the sections that changed.
 *
 * @param {HTMLElement} root
 * @param {Array} sections from the web service: {id, hash, html}
 * @param {Object} options
 * @param {boolean} options.skipBusy leave sections the user is busy in
 * @param {number|null} options.focusCmid card to put the focus back on
 * @returns {Promise<number[]>} the ids of the replaced sections
 */
export const applySections = async(root, sections, {skipBusy = false, focusCmid = null} = {}) => {
    const hashes = JSON.parse(root.dataset.spiffyHashes || '{}');
    const current = sectionMap(root);
    const replaced = [];
    const completed = [];
    for (const {id, hash, html} of sections) {
        const key = String(id);
        const old = current.get(key);
        if (!old || hashes[key] === hash || (skipBusy && isBusy(old))) {
            continue;
        }
        const wascomplete = old.dataset.spiffyComplete === '1';
        await Templates.replaceNode(old, html, '');
        hashes[key] = hash;
        replaced.push(id);
        const fresh = sectionMap(root).get(key);
        if (fresh && !wascomplete && fresh.dataset.spiffyComplete === '1') {
            completed.push(fresh);
        }
    }
    root.dataset.spiffyHashes = JSON.stringify(hashes);
    if (!replaced.length) {
        return replaced;
    }

    if (focusCmid) {
        const card = root.querySelector(`${SELECTORS.CARD}[data-id="${CSS.escape(String(focusCmid))}"]`);
        if (card) {
            const target = card.querySelector('[data-region="spiffy-inline"] button, [data-region="spiffy-inline"] a') ?? card;
            if (target === card) {
                card.setAttribute('tabindex', '-1');
            }
            target.focus({preventScroll: true});
        }
    }

    startModules(root);
    root.dispatchEvent(new CustomEvent(EVENTS.SECTIONS_UPDATED, {bubbles: true, detail: {sectionids: replaced}}));
    completed.forEach((section) => {
        root.dispatchEvent(new CustomEvent(EVENTS.SECTION_COMPLETE, {bubbles: true, detail: {section}}));
    });
    refreshCourseIndex(root, replaced);
    return replaced;
};

/**
 * Ask the course editor to reload the activities of the replaced sections, so the course index
 * shows the same completion and restrictions as the cards.
 *
 * @param {HTMLElement} root
 * @param {number[]} sectionids
 */
const refreshCourseIndex = (root, sectionids) => {
    const editor = getCurrentCourseEditor();
    if (!editor) {
        return;
    }
    const cmids = [];
    sectionids.forEach((id) => {
        const section = root.querySelector(`${SELECTORS.SECTION}[data-sectionid="${CSS.escape(String(id))}"]`);
        section?.querySelectorAll(SELECTORS.CARD).forEach((card) => cmids.push(card.dataset.id));
    });
    if (cmids.length) {
        editor.dispatch('cmState', cmids).catch(() => null);
    }
};

/**
 * Start the AMD modules that elements inside the root ask for with data-spiffy-module.
 *
 * @param {HTMLElement} root
 */
const startModules = (root) => {
    root.querySelectorAll('[data-spiffy-module]:not([data-spiffy-started])').forEach((element) => {
        element.dataset.spiffyStarted = '1';
        const name = element.dataset.spiffyModule;
        if (!/^format_spiffy\/[a-z0-9_]+$/.test(name)) {
            return;
        }
        import(name).then((module) => module.init(element)).catch(Notification.exception);
    });
};

/**
 * Send one card action.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} source the form or button
 * @param {Object} data
 */
const sendAction = async(root, source, data) => {
    if (source.dataset.spiffyBusy) {
        return;
    }
    const cmid = Number(source.dataset.cmid || source.closest(SELECTORS.CARD)?.dataset.id);
    const action = source.dataset.spiffyAction;
    source.dataset.spiffyBusy = '1';
    source.setAttribute('aria-busy', 'true');
    const controls = source.matches('form') ? [...source.elements] : [source];
    controls.forEach((control) => {
        control.dataset.spiffyWasDisabled = control.disabled ? '1' : '';
        control.disabled = true;
    });
    try {
        const response = await Ajax.call([{
            methodname: 'format_spiffy_inline_action',
            args: {cmid, action, data: JSON.stringify(data), sectionid: pageSection(root)},
        }])[0];
        delete source.dataset.spiffyBusy;
        await applySections(root, response.sections, {focusCmid: cmid});
        if (response.message) {
            addToast(response.message);
        }
    } catch (error) {
        Notification.exception(error);
    } finally {
        if (source.isConnected) {
            delete source.dataset.spiffyBusy;
            source.removeAttribute('aria-busy');
            controls.forEach((control) => {
                control.disabled = control.dataset.spiffyWasDisabled === '1';
            });
        }
    }
};

/**
 * Fetch the sections and replace those that changed and the user is not busy in.
 *
 * @param {HTMLElement} root
 * @param {boolean} skipBusy leave sections the user is busy in
 * @returns {Promise}
 */
export const refresh = async(root, skipBusy = true) => {
    const response = await Ajax.call([{
        methodname: 'format_spiffy_get_sections',
        args: {courseid: Number(root.dataset.courseid), sectionid: pageSection(root)},
    }])[0];
    return applySections(root, response, {skipBusy});
};

/**
 * Set up inline actions and live refresh for a Spiffy course page.
 *
 * @param {string} rootselector selector of the format's content root
 */
export const init = (rootselector) => {
    const root = document.querySelector(rootselector);
    if (!root || root.dataset.spiffyInline) {
        return;
    }
    root.dataset.spiffyInline = '1';
    startModules(root);

    root.addEventListener('submit', (event) => {
        const form = event.target.closest(`form${SELECTORS.ACTION}`);
        if (!form || !root.contains(form)) {
            return;
        }
        event.preventDefault();
        sendAction(root, form, formData(form, event.submitter));
    });
    root.addEventListener('click', (event) => {
        const button = event.target.closest(`${SELECTORS.ACTION}:not(form)`);
        if (!button || !root.contains(button) || button.closest(`form${SELECTORS.ACTION}`)) {
            return;
        }
        event.preventDefault();
        const data = button.dataset.spiffyValue !== undefined ? {value: button.dataset.spiffyValue} : {};
        sendAction(root, button, data);
    });

    // Core's "Mark as done" button only redraws itself: bring the card, its section and the next step up to date.
    root.addEventListener(CourseEvents.manualCompletionToggled, () => {
        refresh(root, false).catch(Notification.exception);
    });

    let timer = null;
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(async() => {
            if (document.visibilityState === 'visible') {
                await refresh(root).catch(() => null);
            }
            schedule();
        }, REFRESH_INTERVAL);
    };
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refresh(root).catch(() => null);
        }
    });
    schedule();
};
