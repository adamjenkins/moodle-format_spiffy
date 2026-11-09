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
 * Spotlight mode: the current card on centre stage, the others in a side strip.
 *
 * - The cards on stage start as the server chose (data-spiffy-stage on the root): the open,
 *   unfinished steps of the highlighted section, or else the first open, unfinished step.
 * - Clicking a card (not its links, buttons or forms) or its title link focuses it: it grows
 *   onto the stage of its island and the island's other cards move to the side strip. Clicking a
 *   tile brings it back. Ctrl-click (Cmd-click on a Mac) or the "Open alongside" button adds a
 *   card to the stage; narrow screens show one card at a time.
 * - Sections without a card on stage shrink to compact islands; clicking one, or its "Show
 *   activities" button, expands it.
 * - The focused cards are in the address (#card-12, #card-12+15) with history.pushState, so the
 *   back button works and a link to #card-12 opens with that card focused. #module-12 (the course
 *   index) and #section-3 work too.
 * - "Show me everything" switches to the full list, remembered in the user preference
 *   format_spiffy_viewmode. Clicking a card there opens it in spotlight mode.
 * - The General section never shrinks when the course setting "General section always open" is on
 *   (data-spiffy-alwaysopen on the island).
 * - Changes are animated with the View Transitions API when the browser has it and the user has
 *   not asked for reduced motion.
 * - A section that becomes complete gets confetti (format_spiffy/confetti), a glow and an
 *   announcement, unless the course setting "Celebrate finished sections" is off
 *   (data-spiffy-celebrate on the root).
 * - The course setting "Spotlight view" off (data-spiffy-fixedview) keeps the full list, with no
 *   toggle, and card clicks do nothing special.
 *
 * Sections are rendered without any of this state (format_spiffy/inline replaces them after an
 * action or a refresh), so the layout is applied again on format_spiffy:sectionsupdated. The
 * cards are only moved between the island's list and its side strip; their markup is untouched.
 * After each layout the root gets format_spiffy:spotlightchanged.
 *
 * @module     format_spiffy/spotlight
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import Ajax from 'core/ajax';
import Pending from 'core/pending';
import {setUserPreference} from 'core_user/repository';
import {celebrate as throwConfetti} from 'format_spiffy/confetti';

/** @var {number} Below this root width (px) one card is shown at a time. */
const NARROW = 640;

/** @var {number} How long the celebration lasts, in milliseconds. */
const CELEBRATION = 2600;

const SELECTORS = {
    SECTION: 'section.spiffy-section[data-sectionid]',
    ISLAND: '[data-spiffy-island]',
    ISLANDHEAD: '.spiffy-island-head',
    WRAP: '.spiffy-cards-wrap',
    LIST: '.spiffy-cards',
    STRIP: '[data-region="spiffy-strip"]',
    ITEM: '.spiffy-item[data-id]',
    CARD: '.spiffy-item[data-spiffy-state]',
    FOCUSLINK: '[data-spiffy-focus]',
    PIN: '[data-spiffy-pin]',
    TOGGLE: '[data-spiffy-toggle]',
    VIEWTOGGLE: '[data-spiffy-viewtoggle]',
    ANNOUNCE: '[data-region="spiffy-announce"]',
    WHATSNEXT: '[data-region="spiffy-whatsnext"]',
    WHATSNEXTEMPTY: '[data-region="spiffy-whatsnext-empty"]',
    WHATSNEXTMORE: '[data-region="spiffy-whatsnext-more"]',
    TITLE: '.spiffy-card-title',
    // Clicks on these inside a card do their own thing instead of focusing the card.
    CONTROL: 'a, button, input, select, textarea, label, summary, details, iframe, video, audio, [role="button"],'
        + ' [contenteditable], [tabindex]:not([tabindex="-1"]), form, .dropdown-menu',
};

const CLASSES = {
    SPOTLIGHT: 'spiffy-view-spotlight',
    LIST: 'spiffy-view-list',
    HASSTAGE: 'spiffy-has-stage',
    STAGE: 'spiffy-is-stage',
    TILE: 'spiffy-is-tile',
    NEXT: 'spiffy-is-next',
    WRAPSTAGE: 'spiffy-wrap--stage',
    ISLANDSTAGE: 'spiffy-island--stage',
    COMPACT: 'spiffy-island--compact',
    CELEBRATE: 'spiffy-celebrate',
    HIGHLIGHT: 'spiffy-is-highlighted',
};

const MODES = {
    SPOTLIGHT: 'spotlight',
    LIST: 'list',
};

const EVENTS = {
    CHANGED: 'format_spiffy:spotlightchanged',
    SECTIONS_UPDATED: 'format_spiffy:sectionsupdated',
    SECTION_COMPLETE: 'format_spiffy:sectioncomplete',
};

/**
 * Whether the user asked for reduced motion.
 *
 * @returns {boolean}
 */
const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Remove duplicates, keeping the first of each.
 *
 * @param {string[]} list
 * @returns {string[]}
 */
const unique = (list) => [...new Set(list)];

/**
 * The spotlight of one course page.
 */
class Spotlight {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root the format's content root
     */
    constructor(root) {
        /** @type {HTMLElement} */
        this.root = root;
        /** @type {boolean} the course setting "Spotlight view" is off: the full list only */
        this.fixed = root.dataset.spiffyFixedview === '1';
        /** @type {string} spotlight or list */
        this.mode = this.fixed || root.dataset.spiffyViewmode === MODES.LIST ? MODES.LIST : MODES.SPOTLIGHT;
        /** @type {string[]} the course module ids on stage */
        this.focus = [];
        /** @type {boolean} whether the stage is the default choice rather than the user's */
        this.auto = true;
        /** @type {Set<string>} islands the user expanded */
        this.expanded = new Set();
        /** @type {Map<string, string>} card states at the last layout, by course module id */
        this.states = new Map();
        /** @type {string} the address hash last followed or set */
        this.lasthash = window.location.hash;
    }

    /**
     * Start: first layout, then the listeners.
     */
    start() {
        const initial = (this.root.dataset.spiffyStage || '').split(' ').filter((id) => this.card(id));
        this.focus = this.isNarrow() ? initial.slice(0, 1) : this.limit(initial);
        if (!this.applyHash({initial: true})) {
            this.layout();
        }

        this.root.addEventListener('click', (event) => this.onClick(event));
        window.addEventListener('popstate', () => this.applyHash());
        window.addEventListener('hashchange', () => this.applyHash());
        this.root.addEventListener(EVENTS.SECTIONS_UPDATED, () => this.onSectionsUpdated());
        this.root.addEventListener(EVENTS.SECTION_COMPLETE, (event) => this.celebrate(event.detail?.section));

        let timer = null;
        let wasnarrow = this.isNarrow();
        window.addEventListener('resize', () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const narrow = this.isNarrow();
                if (narrow !== wasnarrow) {
                    wasnarrow = narrow;
                    this.focus = this.limit(this.focus);
                    this.layout();
                }
            }, 200);
        });
    }

    /**
     * A card by course module id.
     *
     * @param {string} cmid
     * @returns {HTMLElement|null}
     */
    card(cmid) {
        if (!/^\d+$/.test(String(cmid))) {
            return null;
        }
        return this.root.querySelector(`${SELECTORS.CARD}[data-id="${cmid}"]`);
    }

    /**
     * Whether the page is too narrow for more than one card on stage.
     *
     * @returns {boolean}
     */
    isNarrow() {
        return this.root.getBoundingClientRect().width < NARROW;
    }

    /**
     * Keep only cards that exist, once each, and only the last on a narrow screen.
     *
     * @param {string[]} ids
     * @returns {string[]}
     */
    limit(ids) {
        const list = unique(ids.map(String)).filter((id) => this.card(id));
        return this.isNarrow() ? list.slice(-1) : list;
    }

    /**
     * The default stage, worked out from the page as the server does it
     * (format_spiffy\local\spotlight::get_stage()).
     *
     * @returns {string[]}
     */
    defaultStage() {
        const open = [...this.root.querySelectorAll(`${SELECTORS.CARD}[data-spiffy-state="available"]`)];
        const tracked = open.filter((card) => card.dataset.spiffyTracked === '1');
        const pool = tracked.length ? tracked : open;
        const current = pool.filter((card) => card.closest(SELECTORS.SECTION)?.classList.contains('current'));
        const chosen = current.length ? current : pool.slice(0, 1);
        return chosen.map((card) => card.dataset.id).slice(0, this.isNarrow() ? 1 : undefined);
    }

    /**
     * The items that belong to a card grid itself (not to a subsection inside it), in page order.
     *
     * @param {HTMLElement} wrap
     * @returns {HTMLElement[]}
     */
    itemsOf(wrap) {
        const items = [...wrap.querySelectorAll(SELECTORS.ITEM)].filter((item) => item.closest(SELECTORS.WRAP) === wrap);
        // The page order, kept so cards can go back to their place from the side strip.
        let next = 0;
        items.forEach((item) => {
            if (item.dataset.spiffyOrder === undefined) {
                item.dataset.spiffyOrder = String(next);
            }
            next = Math.max(next, Number(item.dataset.spiffyOrder)) + 1;
        });
        return items.sort((a, b) => Number(a.dataset.spiffyOrder) - Number(b.dataset.spiffyOrder));
    }

    /**
     * The "Show activities" button of an island itself (not of a subsection inside it).
     *
     * @param {HTMLElement} island
     * @returns {HTMLElement|undefined}
     */
    toggleOf(island) {
        return [...island.querySelectorAll(SELECTORS.TOGGLE)].find((button) => button.closest(SELECTORS.ISLAND) === island);
    }

    /**
     * Put an item into a list at its page-order place, moving it only if it is not there yet.
     *
     * @param {HTMLElement} list
     * @param {HTMLElement} item
     */
    place(list, item) {
        if (item.parentElement === list) {
            return;
        }
        const order = Number(item.dataset.spiffyOrder);
        const before = [...list.children].find((child) => Number(child.dataset.spiffyOrder) > order) ?? null;
        list.insertBefore(item, before);
    }

    /**
     * Lay the page out for the current mode, stage and expanded islands.
     */
    layout() {
        const root = this.root;
        const spotlight = this.mode === MODES.SPOTLIGHT;
        const stage = new Set(spotlight ? this.focus : []);
        const active = document.activeElement;

        root.classList.toggle(CLASSES.SPOTLIGHT, spotlight);
        root.classList.toggle(CLASSES.LIST, !spotlight);
        root.classList.toggle(CLASSES.HASSTAGE, stage.size > 0);
        root.dataset.spiffyViewmode = this.mode;

        root.querySelectorAll(SELECTORS.CARD).forEach((card) => {
            card.classList.toggle(CLASSES.STAGE, stage.has(card.dataset.id));
        });

        root.querySelectorAll(SELECTORS.WRAP).forEach((wrap) => {
            const list = wrap.querySelector(`:scope > ${SELECTORS.LIST}`);
            const strip = wrap.querySelector(`:scope > ${SELECTORS.STRIP}`);
            if (!list || !strip) {
                return;
            }
            const items = this.itemsOf(wrap);
            const holds = (item) => item.classList.contains(CLASSES.STAGE) || !!item.querySelector(`.${CLASSES.STAGE}`);
            const hasstage = items.some(holds);
            items.forEach((item) => {
                // On stage: the focused cards, a subsection holding one, and the notes, which are
                // there to be read beside the cards.
                const onstage = !hasstage || holds(item) || (!item.matches(SELECTORS.CARD) && !item.querySelector(SELECTORS.CARD));
                this.place(onstage ? list : strip, item);
                item.classList.toggle(CLASSES.TILE, !onstage);
            });
            wrap.classList.toggle(CLASSES.WRAPSTAGE, hasstage);
            strip.hidden = !hasstage || !strip.children.length;
        });

        root.querySelectorAll(SELECTORS.ISLAND).forEach((island) => {
            const hasstage = !!island.querySelector(`.${CLASSES.STAGE}`);
            const hascards = !!island.querySelector(SELECTORS.WRAP);
            // The General section stays open if the course says so (data-spiffy-alwaysopen).
            const compact = spotlight && stage.size > 0 && hascards && !hasstage && !this.expanded.has(island.dataset.spiffyIsland)
                && island.dataset.spiffyAlwaysopen !== '1';
            if (hasstage) {
                // Shrinks again once its cards leave the stage.
                this.expanded.delete(island.dataset.spiffyIsland);
            }
            island.classList.toggle(CLASSES.ISLANDSTAGE, hasstage);
            island.classList.toggle(CLASSES.COMPACT, compact);
            const toggle = this.toggleOf(island);
            toggle?.setAttribute('aria-expanded', compact ? 'false' : 'true');
        });

        root.querySelectorAll(SELECTORS.PIN).forEach((pin) => {
            const card = pin.closest(SELECTORS.CARD);
            const onstage = !!card?.classList.contains(CLASSES.STAGE);
            pin.dataset.spiffyPinned = onstage ? '1' : '';
            // The only card on stage has nothing to close to; phones show one card anyway.
            pin.hidden = !card || !spotlight || this.isNarrow() || (onstage && stage.size === 1);
        });

        // The title links focus a card; in the list they open it in spotlight mode.
        root.querySelectorAll(`${SELECTORS.CARD} ${SELECTORS.FOCUSLINK}`).forEach((link) => {
            link.tabIndex = 0;
        });

        root.querySelectorAll(SELECTORS.VIEWTOGGLE).forEach((button) => {
            button.setAttribute('aria-pressed', spotlight ? 'false' : 'true');
        });

        this.updateWhatsNext();
        root.querySelectorAll(SELECTORS.CARD).forEach((card) => this.states.set(card.dataset.id, card.dataset.spiffyState));

        // Moving a card out of its list and back loses the keyboard focus; put it back.
        if (active && active !== document.body && root.contains(active) && document.activeElement !== active) {
            active.focus({preventScroll: true});
        }
        root.dispatchEvent(new CustomEvent(EVENTS.CHANGED, {bubbles: true, detail: {focus: [...stage], mode: this.mode}}));
    }

    /**
     * Run a layout change, animated with a view transition where that is possible and wanted.
     *
     * @param {Function} update the change
     * @returns {Promise}
     */
    transition(update) {
        const pending = new Pending('format_spiffy/spotlight:transition');
        if (!document.startViewTransition || reducedMotion()) {
            update();
            pending.resolve();
            return Promise.resolve();
        }
        // Name the visible cards and islands so the browser moves each one to its new place.
        const named = [];
        this.root.querySelectorAll(`${SELECTORS.ITEM}, ${SELECTORS.ISLAND}`).forEach((element) => {
            if (!element.getClientRects().length) {
                return;
            }
            const name = element.matches(SELECTORS.ISLAND) ?
                `spiffy-island-${element.dataset.spiffyIsland}` : `spiffy-card-${element.dataset.id}`;
            if (/^spiffy-(island|card)-\d+$/.test(name)) {
                element.style.viewTransitionName = name;
                named.push(element);
            }
        });
        let transition;
        try {
            transition = document.startViewTransition(() => update());
        } catch (error) {
            named.forEach((element) => element.style.removeProperty('view-transition-name'));
            update();
            pending.resolve();
            return Promise.resolve();
        }
        // A transition started while this one runs skips it, which rejects "ready" as well as "finished".
        transition.ready.catch(() => null);
        return transition.finished.catch(() => null).finally(() => {
            named.forEach((element) => element.style.removeProperty('view-transition-name'));
            pending.resolve();
        });
    }

    /**
     * Put cards on stage.
     *
     * @param {string[]} ids course module ids
     * @param {Object} options
     * @param {boolean} options.push add a history entry
     * @param {boolean} options.auto whether this is the default stage rather than the user's choice
     * @param {boolean} options.move move the keyboard focus to the card and scroll to it
     * @returns {Promise}
     */
    setFocus(ids, {push = true, auto = false, move = true} = {}) {
        const focus = this.limit(ids);
        const changed = focus.join(' ') !== this.focus.join(' ');
        if (!auto) {
            this.remember(this.auto ? focus : focus.filter((id) => !this.focus.includes(id)));
        }
        this.focus = focus;
        this.auto = auto;
        if (push) {
            this.pushUrl(auto ? [] : focus);
        }
        const done = changed ? this.transition(() => this.layout()) : Promise.resolve(this.layout());
        return done.then(() => {
            if (move && focus.length) {
                this.reveal(focus[focus.length - 1]);
                this.announceCard(focus[focus.length - 1]);
            }
            return null;
        });
    }

    /**
     * Save the cards the user just chose for "Recently viewed", straight away so that a refresh
     * straight after still knows. Nothing depends on the answer.
     *
     * @param {string[]} ids course module ids
     */
    remember(ids) {
        if (!ids.length || !this.root.dataset.spiffyRecent) {
            return;
        }
        const calls = ids.map((cmid) => ({methodname: 'format_spiffy_card_viewed', args: {cmid: Number(cmid)}}));
        Ajax.call(calls).forEach((call) => call.catch(() => null));
    }

    /**
     * Add a card to the stage, or take it off.
     *
     * @param {string} cmid
     */
    togglePin(cmid) {
        if (this.mode === MODES.SPOTLIGHT && this.focus.includes(cmid)) {
            const rest = this.focus.filter((id) => id !== cmid);
            if (rest.length) {
                this.setFocus(rest, {move: false});
            } else {
                this.setFocus(this.defaultStage(), {auto: true, move: false});
            }
            return;
        }
        this.setFocus([...(this.mode === MODES.SPOTLIGHT ? this.focus : []), cmid]);
    }

    /**
     * Reflect the stage in the address, without a reload.
     *
     * @param {string[]} ids
     */
    pushUrl(ids) {
        const url = new URL(window.location.href);
        url.hash = ids.length ? `card-${ids.join('+')}` : '';
        if (url.href !== window.location.href) {
            window.history.pushState({spiffyCards: ids}, '', url.href);
        }
        this.lasthash = window.location.hash;
    }

    /**
     * Follow the address: #card-12, #card-12+15, #module-12 or #section-3.
     *
     * @param {Object} options
     * @param {boolean} options.initial whether the page has just loaded
     * @returns {boolean} whether the address named something on this page
     */
    applyHash({initial = false} = {}) {
        // Going back can fire both popstate and hashchange for the same entry.
        if (!initial && window.location.hash === this.lasthash) {
            return true;
        }
        this.lasthash = window.location.hash;
        let hash = '';
        try {
            hash = decodeURIComponent(window.location.hash.slice(1));
        } catch (error) {
            hash = '';
        }
        const cards = hash.match(/^card-(\d+(?:[+ ,]\d+)*)$/);
        const module = hash.match(/^module-(\d+)$/);
        const section = hash.match(/^section-(\d+)$/);

        if (cards || module) {
            const ids = (cards ? cards[1].split(/[+ ,]/) : [module[1]]).filter((id) => this.card(id));
            if (ids.length) {
                if (this.mode === MODES.LIST) {
                    // Kept for when the user switches back to spotlight mode.
                    this.focus = this.limit(ids);
                    this.auto = false;
                    this.layout();
                    this.reveal(ids[0], {highlight: true});
                } else {
                    this.setFocus(ids, {push: false});
                }
                return true;
            }
            if (module) {
                // A note or a subsection: show its island, the browser scrolls to it.
                const item = this.root.querySelector(`${SELECTORS.ITEM}[data-id="${module[1]}"]`);
                if (item) {
                    this.expandAround(item);
                    return true;
                }
            }
        }
        if (section) {
            const element = this.root.querySelector(`${SELECTORS.SECTION}[data-number="${section[1]}"]`);
            if (element) {
                this.expandAround(element);
                return true;
            }
        }
        if (!initial && !hash) {
            // Back to the entry without a card: the default stage.
            this.setFocus(this.defaultStage(), {push: false, auto: true, move: false});
            return true;
        }
        return false;
    }

    /**
     * Expand the islands around an element and scroll to it.
     *
     * @param {HTMLElement} element
     */
    expandAround(element) {
        let island = element.matches(SELECTORS.ISLAND) ? element : element.closest(SELECTORS.ISLAND);
        while (island) {
            this.expanded.add(island.dataset.spiffyIsland);
            island = island.parentElement?.closest(SELECTORS.ISLAND);
        }
        this.layout();
        element.scrollIntoView({block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth'});
    }

    /**
     * Scroll to a card if needed and give it the keyboard focus.
     *
     * @param {string} cmid
     * @param {Object} options
     * @param {boolean} options.highlight briefly mark the card (list mode)
     */
    reveal(cmid, {highlight = false} = {}) {
        const card = this.card(cmid);
        if (!card) {
            return;
        }
        const box = card.getBoundingClientRect();
        if (box.top < 0 || box.top > window.innerHeight * 0.6) {
            card.scrollIntoView({block: 'start', behavior: reducedMotion() ? 'auto' : 'smooth'});
        }
        const link = card.querySelector(SELECTORS.FOCUSLINK);
        if (link && link.tabIndex >= 0) {
            link.focus({preventScroll: true});
        } else {
            card.setAttribute('tabindex', '-1');
            card.focus({preventScroll: true});
        }
        if (highlight) {
            card.classList.add(CLASSES.HIGHLIGHT);
            setTimeout(() => card.classList.remove(CLASSES.HIGHLIGHT), CELEBRATION);
        }
    }

    /**
     * Say which card is now on stage.
     *
     * @param {string} cmid
     */
    announceCard(cmid) {
        const title = this.card(cmid)?.querySelector(SELECTORS.TITLE)?.textContent.trim();
        if (title) {
            getString('nowshowing', 'format_spiffy', title).then((text) => this.announce(text)).catch(() => null);
        }
    }

    /**
     * Put a message in the live region.
     *
     * @param {string} text
     */
    announce(text) {
        const region = this.root.querySelector(SELECTORS.ANNOUNCE);
        if (region) {
            region.textContent = '';
            setTimeout(() => {
                region.textContent = text;
            }, 50);
        }
    }

    /**
     * Switch between spotlight mode and the full list, and remember it.
     *
     * @param {string} mode
     */
    setMode(mode) {
        if (this.fixed) {
            return;
        }
        this.mode = mode === MODES.LIST ? MODES.LIST : MODES.SPOTLIGHT;
        if (this.mode === MODES.SPOTLIGHT && !this.focus.length) {
            this.focus = this.defaultStage();
            this.auto = true;
        }
        this.transition(() => this.layout());
        this.savePreference();
    }

    /**
     * Save the view mode in the user preference.
     */
    savePreference() {
        setUserPreference('format_spiffy_viewmode', this.mode).catch(() => null);
    }

    /**
     * Clicks on the page: cards, tiles, pins, island toggles, the view toggle and "What's next".
     *
     * @param {MouseEvent} event
     */
    onClick(event) {
        const target = event.target;
        if (!(target instanceof Element) || event.button !== 0 || event.defaultPrevented) {
            return;
        }
        if (this.onControlClick(event, target)) {
            return;
        }
        const card = target.closest(SELECTORS.CARD);
        if (card) {
            this.onCardClick(event, target, card);
        } else {
            this.onOutsideCardClick(event, target);
        }
    }

    /**
     * Clicks on the view toggle, a pin or an island toggle.
     *
     * @param {MouseEvent} event
     * @param {Element} target
     * @returns {boolean} whether the click was handled
     */
    onControlClick(event, target) {
        if (target.closest(SELECTORS.VIEWTOGGLE)) {
            event.preventDefault();
            this.setMode(this.mode === MODES.LIST ? MODES.SPOTLIGHT : MODES.LIST);
            return true;
        }
        const pin = target.closest(SELECTORS.PIN);
        if (pin) {
            const card = pin.closest(SELECTORS.CARD);
            if (card) {
                event.preventDefault();
                this.togglePin(card.dataset.id);
            }
            return true;
        }
        const toggle = target.closest(SELECTORS.TOGGLE);
        if (toggle) {
            event.preventDefault();
            this.toggleIsland(toggle.closest(SELECTORS.ISLAND));
            return true;
        }
        return false;
    }

    /**
     * Clicks outside cards: "What's next" links and compact islands.
     *
     * @param {MouseEvent} event
     * @param {Element} target
     */
    onOutsideCardClick(event, target) {
        const link = target.closest(SELECTORS.FOCUSLINK);
        if (link && this.mode === MODES.SPOTLIGHT && this.card(link.dataset.spiffyFocus)) {
            event.preventDefault();
            this.setFocus([link.dataset.spiffyFocus]);
            return;
        }
        const island = target.closest(SELECTORS.ISLAND);
        if (island?.classList.contains(CLASSES.COMPACT) && !target.closest(SELECTORS.CONTROL)) {
            this.toggleIsland(island);
        }
    }

    /**
     * Clicks on a card, outside its controls: focus it, or pin it with Ctrl/Cmd. In the full list
     * ("Show me everything") the click opens the card in spotlight mode.
     *
     * @param {MouseEvent} event
     * @param {Element} target
     * @param {HTMLElement} card
     */
    onCardClick(event, target, card) {
        const link = target.closest(SELECTORS.FOCUSLINK);
        const control = target.closest(SELECTORS.CONTROL);
        if ((control && control !== link) || window.getSelection()?.toString()) {
            return;
        }
        if (this.fixed) {
            // The full list only: the title link scrolls to its card as a normal link.
            return;
        }
        event.preventDefault();
        const cmid = card.dataset.id;
        if (this.mode !== MODES.SPOTLIGHT) {
            this.openFromList(cmid);
        } else if ((event.ctrlKey || event.metaKey) && !this.isNarrow()) {
            this.togglePin(cmid);
        } else if (this.auto || this.focus.length !== 1 || this.focus[0] !== cmid) {
            this.setFocus([cmid]);
        }
    }

    /**
     * Leave the full list for spotlight mode with one card on stage, and remember the mode.
     *
     * @param {string} cmid
     */
    openFromList(cmid) {
        this.mode = MODES.SPOTLIGHT;
        this.focus = this.limit([cmid]);
        this.remember(this.focus);
        this.auto = false;
        this.pushUrl(this.focus);
        this.savePreference();
        this.transition(() => this.layout()).then(() => {
            this.reveal(cmid);
            this.announceCard(cmid);
            return null;
        }).catch(() => null);
    }

    /**
     * Expand or shrink an island.
     *
     * @param {HTMLElement|null} island
     */
    toggleIsland(island) {
        if (!island) {
            return;
        }
        const key = island.dataset.spiffyIsland;
        if (island.classList.contains(CLASSES.COMPACT)) {
            this.expanded.add(key);
        } else {
            this.expanded.delete(key);
        }
        this.transition(() => this.layout()).then(() => {
            const toggle = this.toggleOf(island);
            toggle?.focus({preventScroll: true});
            return null;
        }).catch(() => null);
    }

    /**
     * After sections were replaced: keep the user's cards on stage; on the default stage, keep a
     * card that has just been done (so its result shows) beside the next one.
     */
    onSectionsUpdated() {
        const previous = this.states;
        const existing = this.focus.filter((id) => this.card(id));
        if (this.auto || !existing.length) {
            const justdone = existing.filter((id) => this.card(id).dataset.spiffyState === 'done' && previous.get(id) !== 'done');
            const next = this.defaultStage();
            this.focus = this.isNarrow() ? (justdone.length ? justdone : next).slice(0, 1) : unique([...justdone, ...next]);
            this.auto = true;
        } else {
            this.focus = this.limit(existing);
        }
        // Mark the cards that have just opened up, so the strip shows where to go next.
        this.root.querySelectorAll(SELECTORS.CARD).forEach((card) => {
            const was = previous.get(card.dataset.id);
            card.classList.toggle(CLASSES.NEXT, was === 'locked' && card.dataset.spiffyState === 'available');
        });
        this.layout();
    }

    /**
     * Hide "What's next" items whose card on this page is done; suggestions stay, and so do items
     * for other pages. Show the "all done" text when none are left.
     */
    updateWhatsNext() {
        const region = this.root.querySelector(SELECTORS.WHATSNEXT);
        if (!region) {
            return;
        }
        let shown = 0;
        region.querySelectorAll('li[data-cmid]').forEach((item) => {
            const card = this.card(item.dataset.cmid);
            const suggestion = item.closest('[data-region="spiffy-whatsnext-suggestions"]');
            item.hidden = !suggestion && !!card && card.dataset.spiffyState !== 'available';
            shown += item.hidden ? 0 : 1;
        });
        const empty = region.querySelector(SELECTORS.WHATSNEXTEMPTY);
        if (empty) {
            empty.hidden = shown > 0;
        }
        const more = region.querySelector(SELECTORS.WHATSNEXTMORE);
        if (more) {
            more.hidden = shown === 0;
        }
    }

    /**
     * A small celebration when a section becomes complete.
     *
     * @param {HTMLElement|undefined} section
     */
    celebrate(section) {
        // The course setting "Celebrate finished sections".
        if (!section || !this.root.contains(section) || this.root.dataset.spiffyCelebrate !== '1') {
            return;
        }
        const name = section.dataset.sectionname || '';
        getString('sectioncompleteannounce', 'format_spiffy', name).then((text) => this.announce(text)).catch(() => null);
        section.classList.add(CLASSES.CELEBRATE);
        throwConfetti(section);
        setTimeout(() => section.classList.remove(CLASSES.CELEBRATE), CELEBRATION);
    }
}

/**
 * Set up for a Spiffy course page.
 *
 * @param {string} rootselector selector of the format's content root
 */
export const init = (rootselector) => {
    const root = document.querySelector(rootselector);
    if (!root || root.dataset.spiffySpotlight) {
        return;
    }
    root.dataset.spiffySpotlight = '1';
    const pending = new Pending('format_spiffy/spotlight:init');
    new Spotlight(root).start();
    pending.resolve();
};
