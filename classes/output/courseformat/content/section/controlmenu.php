<?php
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

namespace format_spiffy\output\courseformat\content\section;

use format_spiffy\local\sequential;

/**
 * Section edit menu with the "Force sequential" and "Remove sequential" tools.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class controlmenu extends \core_courseformat\output\local\content\section\controlmenu {
    #[\Override]
    public function section_control_items() {
        $controls = parent::section_control_items();
        if (!$this->section->is_orphan()) {
            foreach (sequential::menu_items($this->format, $this->section) as $key => $item) {
                $controls = self::insert_before($controls, 'delete', $key, $item);
            }
        }
        return $controls;
    }

    /**
     * Insert an item before another in a keyed list (at the end if that key is missing).
     *
     * @param array $controls
     * @param string $before
     * @param string $key
     * @param mixed $item
     * @return array
     */
    public static function insert_before(array $controls, string $before, string $key, $item): array {
        $result = [];
        foreach ($controls as $name => $control) {
            if ($name === $before) {
                $result[$key] = $item;
            }
            $result[$name] = $control;
        }
        // At the end if the other key is missing (assigning an existing key keeps its place).
        $result[$key] = $item;
        return $result;
    }
}
