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
 * The course setting "Keep the blocks drawer open on wide screens": on a wide enough window the
 * blocks drawer is opened when the page loads, and again when the window is widened past the
 * limit. The user's own open/closed preference is left as it is, so closing the drawer still works
 * for the page they are on, and narrower windows keep Boost's usual behaviour.
 *
 * @module     format_spiffy/blocks
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Drawers from 'theme_boost/drawers';

/** @var {string} The blocks drawer of Boost-based themes. */
const BLOCKS_DRAWER = '.drawer.drawer-right[data-preference="drawer-open-block"]';

/**
 * Open the blocks drawer if the page has one and it is closed.
 */
const openBlocks = () => {
    const node = document.querySelector(BLOCKS_DRAWER);
    if (!node) {
        return;
    }
    const drawer = Drawers.getDrawerInstanceForNode(node);
    if (drawer && !drawer.isOpen) {
        drawer.openDrawer({focusOnCloseButton: false, setUserPref: false});
    }
};

/**
 * Keep the blocks drawer open from the given window width up.
 *
 * @param {number} minwidth in pixels
 */
export const init = (minwidth) => {
    const wide = window.matchMedia(`(min-width: ${Number(minwidth)}px)`);
    if (wide.matches) {
        openBlocks();
    }
    wide.addEventListener('change', (event) => {
        if (event.matches) {
            openBlocks();
        }
    });
};
