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
 * Availability hints: when a student points at (or focuses) a step they cannot open yet,
 * the steps to do first are highlighted and the whole chain is drawn: an arrow for every
 * "activity completion" link from those steps through the locked steps in between to the one
 * pointed at. The steps to do first (data-spiffy-requires) are worked out by the server, so they
 * are steps the student can open; each locked step lists its own prerequisites
 * (data-spiffy-prereqs), so the chain can be followed back. The course setting "Hint arrows point"
 * (data-spiffy-arrows on the root) draws the arrows forwards, in the order the steps are done, or
 * backwards, from the locked step to where to begin, or not at all ("none": the steps are still
 * highlighted). Screen readers get the hint as text through
 * the locked button's aria-describedby.
 *
 * The arrows are drawn in a layer per card grid (.spiffy-cards-wrap), which in spotlight mode holds
 * both the cards on centre stage and the side strip, so a tile can point at the stage and back.
 * Cards that are not shown (in a compact island) get no arrow. The hints are cleared whenever the
 * sections are replaced or the spotlight layout changes.
 *
 * @module     format_spiffy/hints
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SVGNS = 'http://www.w3.org/2000/svg';

const SELECTORS = {
    LOCKED: '[data-spiffy-requires]',
    CARD: '.spiffy-card',
    WRAP: '.spiffy-cards-wrap',
};

const CLASSES = {
    LAYER: 'spiffy-hint-layer',
    ARROW: 'spiffy-hint-arrow',
    SOURCE: 'spiffy-hint-source',
    TARGET: 'spiffy-hint-target',
    CHAIN: 'spiffy-hint-chain',
};

let layercount = 0;

/**
 * Get (or create) the SVG layer of a card grid.
 *
 * @param {HTMLElement} wrap the grid wrapper
 * @returns {SVGSVGElement}
 */
const getLayer = (wrap) => {
    let layer = wrap.querySelector(`:scope > .${CLASSES.LAYER}`);
    if (layer) {
        return layer;
    }
    layercount++;
    const markerid = `spiffy-hint-head-${layercount}`;
    layer = document.createElementNS(SVGNS, 'svg');
    layer.classList.add(CLASSES.LAYER);
    layer.setAttribute('aria-hidden', 'true');
    layer.setAttribute('focusable', 'false');
    const defs = document.createElementNS(SVGNS, 'defs');
    const marker = document.createElementNS(SVGNS, 'marker');
    marker.setAttribute('id', markerid);
    marker.setAttribute('viewBox', '0 0 10 10');
    marker.setAttribute('refX', '7');
    marker.setAttribute('refY', '5');
    marker.setAttribute('markerWidth', '5');
    marker.setAttribute('markerHeight', '5');
    marker.setAttribute('orient', 'auto-start-reverse');
    const head = document.createElementNS(SVGNS, 'path');
    head.setAttribute('d', 'M0 0L10 5L0 10z');
    marker.appendChild(head);
    defs.appendChild(marker);
    layer.appendChild(defs);
    layer.dataset.marker = markerid;
    wrap.appendChild(layer);
    return layer;
};

/**
 * The path of an arrow from one card to another.
 *
 * Cards in the same row get an arc over their tops, so the arrow is visible even across a
 * narrow gap; cards side by side but not level (a stage card and a side tile) are joined from
 * their facing sides; cards in different rows are joined from the facing edges.
 *
 * @param {DOMRect} from the card that unlocks
 * @param {DOMRect} to the locked card
 * @param {DOMRect} origin the layer's box, for relative coordinates
 * @returns {string} SVG path data
 */
const arrowPath = (from, to, origin) => {
    const fx = from.left + from.width / 2 - origin.left;
    const tx = to.left + to.width / 2 - origin.left;
    const samerow = Math.abs(from.top - to.top) < Math.min(from.height, to.height) / 2;
    if (samerow) {
        const y = Math.min(from.top, to.top) - origin.top;
        const lift = Math.min(56, 24 + Math.abs(tx - fx) / 8);
        const sx = fx + Math.sign(tx - fx) * from.width / 6;
        const ex = tx - Math.sign(tx - fx) * to.width / 6;
        return `M${sx} ${y}C${sx} ${y - lift} ${ex} ${y - lift} ${ex} ${y - 4}`;
    }
    const overlap = Math.min(from.bottom, to.bottom) - Math.max(from.top, to.top);
    const apart = from.right <= to.left || to.right <= from.left;
    if (apart && overlap > 0) {
        const right = to.left >= from.right;
        const sx = (right ? from.right : from.left) - origin.left;
        const ex = (right ? to.left - 4 : to.right + 4) - origin.left;
        const sy = from.top + from.height / 2 - origin.top;
        const ey = Math.min(Math.max(from.top + from.height / 2, to.top + 24), to.bottom - 24) - origin.top;
        const bend = Math.max(24, Math.abs(ex - sx) / 2) * (right ? 1 : -1);
        return `M${sx} ${sy}C${sx + bend} ${sy} ${ex - bend} ${ey} ${ex} ${ey}`;
    }
    const down = to.top > from.top;
    const sy = (down ? from.bottom : from.top) - origin.top;
    const ey = (down ? to.top - 4 : to.bottom + 4) - origin.top;
    const bend = Math.max(24, Math.abs(ey - sy) / 2) * (down ? 1 : -1);
    return `M${fx} ${sy}C${fx} ${sy + bend} ${tx} ${ey - bend} ${tx} ${ey}`;
};

/**
 * Draw an arrow from one card to another.
 *
 * @param {SVGSVGElement} layer
 * @param {HTMLElement} source the card that unlocks
 * @param {HTMLElement} target the locked card
 */
const drawArrow = (layer, source, target) => {
    const path = document.createElementNS(SVGNS, 'path');
    path.classList.add(CLASSES.ARROW);
    path.setAttribute('d', arrowPath(source.getBoundingClientRect(), target.getBoundingClientRect(),
        layer.getBoundingClientRect()));
    path.setAttribute('marker-end', `url(#${layer.dataset.marker})`);
    layer.appendChild(path);
};

/**
 * Remove every hint inside a root element.
 *
 * @param {HTMLElement} root
 */
const clearHints = (root) => {
    root.querySelectorAll(`.${CLASSES.ARROW}`).forEach((arrow) => arrow.remove());
    root.querySelectorAll(`.${CLASSES.SOURCE}, .${CLASSES.TARGET}, .${CLASSES.CHAIN}`).forEach((card) => {
        card.classList.remove(CLASSES.SOURCE, CLASSES.TARGET, CLASSES.CHAIN);
    });
};

/**
 * Whether an element is shown (not inside something with display: none).
 *
 * @param {HTMLElement} element
 * @returns {boolean}
 */
const isShown = (element) => element.getClientRects().length > 0;

/**
 * The course module ids in a space separated attribute.
 *
 * @param {string|undefined} value
 * @returns {string[]}
 */
const ids = (value) => (value || '').split(' ').filter(Boolean);

/**
 * The links of the chain behind a locked card: each unfinished prerequisite and the step it
 * unlocks, followed back through prerequisites that are locked themselves.
 *
 * @param {HTMLElement} wrap the card grid
 * @param {HTMLElement} card the locked card
 * @returns {{links: Array<HTMLElement[]>, between: HTMLElement[]}} links as [prerequisite, step];
 *     the locked steps in between
 */
const chainOf = (wrap, card) => {
    const links = [];
    const between = [];
    const seen = new Set([card.dataset.id]);
    const queue = [card];
    while (queue.length) {
        const step = queue.shift();
        ids(step.dataset.spiffyPrereqs).forEach((cmid) => {
            const prerequisite = wrap.querySelector(`#module-${CSS.escape(cmid)}`);
            if (!prerequisite || !prerequisite.matches(SELECTORS.CARD) || prerequisite.dataset.spiffyState === 'done') {
                return;
            }
            links.push([prerequisite, step]);
            if (!seen.has(cmid)) {
                seen.add(cmid);
                if (prerequisite.dataset.spiffyRequires) {
                    between.push(prerequisite);
                    queue.push(prerequisite);
                }
            }
        });
    }
    return {links, between};
};

/**
 * Show the hints of one locked card.
 *
 * @param {HTMLElement} root
 * @param {HTMLElement} card the locked card
 */
const showHints = (root, card) => {
    clearHints(root);
    const wrap = card.closest(SELECTORS.WRAP);
    if (!wrap || !isShown(card)) {
        return;
    }
    const layer = getLayer(wrap);
    const backwards = root.dataset.spiffyArrows === 'backwards';
    const noarrows = root.dataset.spiffyArrows === 'none';
    const draw = (prerequisite, step) => {
        if (noarrows) {
            return;
        }
        if (backwards) {
            drawArrow(layer, step, prerequisite);
        } else {
            drawArrow(layer, prerequisite, step);
        }
    };
    const {links, between} = chainOf(wrap, card);
    const linked = new Set();
    links.forEach(([prerequisite, step]) => {
        if (isShown(prerequisite) && isShown(step)) {
            draw(prerequisite, step);
            linked.add(prerequisite.dataset.id);
        }
    });
    between.filter(isShown).forEach((step) => step.classList.add(CLASSES.CHAIN));
    ids(card.dataset.spiffyRequires).forEach((cmid) => {
        const source = wrap.querySelector(`#module-${CSS.escape(cmid)}`);
        if (source && isShown(source)) {
            source.classList.add(CLASSES.SOURCE);
            source.dataset.spiffyHintLabel = root.dataset.spiffyHintLabel || '';
            if (!linked.has(cmid)) {
                // A step of the chain is not on the page (or not shown): point straight at the card.
                draw(source, card);
            }
        }
    });
    card.classList.add(CLASSES.TARGET);
};

/**
 * Set up the hints for a Spiffy course page.
 *
 * @param {string} rootselector selector of the format's content root
 */
export const init = (rootselector) => {
    const root = document.querySelector(rootselector);
    if (!root || root.dataset.spiffyHints) {
        return;
    }
    root.dataset.spiffyHints = '1';
    let current = null;

    const enter = (event) => {
        const card = event.target.closest(SELECTORS.LOCKED);
        if (card && card !== current && root.contains(card)) {
            current = card;
            showHints(root, card);
        }
    };
    const leave = (event) => {
        if (current && !current.contains(event.relatedTarget)) {
            current = null;
            clearHints(root);
        }
    };
    root.addEventListener('mouseover', enter);
    root.addEventListener('mouseout', leave);
    root.addEventListener('focusin', enter);
    root.addEventListener('focusout', leave);
    const reset = () => {
        current = null;
        clearHints(root);
    };
    window.addEventListener('resize', reset);
    // Cards are replaced after an inline action or a refresh: drop hints that point at old ones.
    root.addEventListener('format_spiffy:sectionsupdated', reset);
    // Spotlight mode moves cards between the stage and the side strip.
    root.addEventListener('format_spiffy:spotlightchanged', reset);
};
