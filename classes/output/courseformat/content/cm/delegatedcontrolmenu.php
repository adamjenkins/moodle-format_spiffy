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

namespace format_spiffy\output\courseformat\content\cm;

use format_spiffy\local\sequential;
use format_spiffy\output\courseformat\content\section\controlmenu;

/**
 * Subsection edit menu with the "Force sequential" and "Remove sequential" tools.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delegatedcontrolmenu extends \core_courseformat\output\local\content\cm\delegatedcontrolmenu {
    #[\Override]
    public function delegated_control_items() {
        $controls = parent::delegated_control_items();
        foreach (sequential::menu_items($this->format, $this->section) as $key => $item) {
            $controls = controlmenu::insert_before($controls, 'delete', $key, $item);
        }
        return $controls;
    }
}
