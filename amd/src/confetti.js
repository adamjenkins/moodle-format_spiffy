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
 * Confetti for a finished section, with canvas-confetti (format_spiffy/local/canvas_confetti).
 *
 * A burst from the element that was finished, in the theme's colours, then two short cannons from
 * the bottom corners. The burst is canvas-confetti's "realistic" demo and the cannons its "school
 * pride" demo, as local_confetti uses them, tuned shorter and aimed at the element. Nothing happens
 * for users who ask for reduced motion.
 *
 * @module     format_spiffy/confetti
 * @copyright  2020 Kiril Vatev
 * @copyright  2025 Odei Alba <odeialba@odeialba.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import confetti from 'format_spiffy/local/canvas_confetti';

/** @var {string[]} Moodle design system colours (green, yellow, red, blue, purple) used with the theme's. */
const PALETTE = ['#6a9f2a', '#d9a326', '#e06c5a', '#0f6cbf', '#8e5ad6'];

/** @var {number} Above Boost's navbar and drawers. */
const ZINDEX = 1100;

/** @var {number} How long the side cannons fire, in milliseconds. */
const CANNONS = 900;

/**
 * A CSS colour as #rrggbb (canvas-confetti takes hex only), or null.
 *
 * @param {string} value any CSS colour
 * @returns {string|null}
 */
const toHex = (value) => {
    const text = (value || '').trim();
    if (!text) {
        return null;
    }
    const context = document.createElement('canvas').getContext('2d');
    if (!context) {
        return null;
    }
    context.fillStyle = '#000001';
    context.fillStyle = text;
    // The canvas gives #rrggbb for an opaque colour it understood, rgba() otherwise.
    return /^#[0-9a-f]{6}$/i.test(context.fillStyle) && context.fillStyle !== '#000001' ? context.fillStyle : null;
};

/**
 * The theme's primary and success colours, then the palette.
 *
 * @param {HTMLElement} element
 * @returns {string[]}
 */
const colours = (element) => {
    const style = getComputedStyle(element);
    const theme = ['--bs-primary', '--bs-success'].map((name) => toHex(style.getPropertyValue(name)));
    return [...new Set([...theme.filter(Boolean), ...PALETTE])];
};

/**
 * Where on the screen (0 to 1) the burst starts: the middle of the element's top part.
 *
 * @param {HTMLElement} element
 * @returns {{x: number, y: number}}
 */
const originOf = (element) => {
    const box = element.getBoundingClientRect();
    const clamp = (value) => Math.min(0.9, Math.max(0.1, value));
    if (!box.width || box.bottom < 0 || box.top > window.innerHeight) {
        return {x: 0.5, y: 0.6};
    }
    const top = Math.max(box.top, 0);
    const bottom = Math.min(box.bottom, window.innerHeight);
    return {
        x: clamp((box.left + box.width / 2) / window.innerWidth),
        y: clamp((top + Math.min(bottom - top, 240) / 2) / window.innerHeight),
    };
};

/**
 * Celebrate an element: a burst from it, then cannons from the bottom corners.
 *
 * @param {HTMLElement} element the finished section (or anything else to celebrate)
 * @returns {Promise} resolved when the confetti has fallen
 */
export const celebrate = (element) => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return Promise.resolve();
    }
    const colors = colours(element);
    const origin = originOf(element);
    const base = {origin, colors, zIndex: ZINDEX, disableForReducedMotion: true};
    const fire = (ratio, options) => confetti({...base, ...options, particleCount: Math.floor(220 * ratio)});
    const fired = [
        fire(0.25, {spread: 26, startVelocity: 55, shapes: ['square']}),
        fire(0.2, {spread: 60, shapes: ['circle']}),
        fire(0.35, {spread: 100, decay: 0.91, scalar: 0.8}),
        fire(0.1, {spread: 120, startVelocity: 25, decay: 0.92, scalar: 1.2, shapes: ['star']}),
        fire(0.1, {spread: 120, startVelocity: 45}),
    ];

    const end = Date.now() + CANNONS;
    const cannons = new Promise((resolve) => {
        const frame = () => {
            const side = {particleCount: 3, spread: 55, startVelocity: 60, colors, zIndex: ZINDEX, disableForReducedMotion: true};
            confetti({...side, angle: 60, origin: {x: 0, y: 0.9}});
            confetti({...side, angle: 120, origin: {x: 1, y: 0.9}});
            if (Date.now() < end) {
                window.requestAnimationFrame(frame);
            } else {
                resolve();
            }
        };
        window.setTimeout(frame, 250);
    });
    return Promise.all([...fired.map((promise) => promise ?? null), cannons]).catch(() => null);
};
