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

namespace format_spiffy\output\courseformat\content;

use core_courseformat\output\local\content\cm as cm_base;
use renderer_base;
use stdClass;

/**
 * Activity output for the Spiffy format.
 *
 * Adds the flags the card layout needs to the core export; everything else is core's.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cm extends cm_base {
    #[\Override]
    public function export_for_template(renderer_base $output): stdClass {
        $data = parent::export_for_template($output);
        $mod = $this->mod;

        // Activities with a page are shown as buttons, the rest (text and media) inline.
        $data->spiffybutton = !empty($mod->url);
        $data->spiffyavailable = (bool)$mod->uservisible;
        $data->spiffyonclick = $this->get_onclick_code();
        // Shown to the user but hidden or restricted for students: dim it, as core does.
        $data->spiffydimmed = !empty($data->modhiddenfromstudents) || !empty($data->modstealth)
            || str_contains($this->get_text_classes(), 'conditionalhidden');
        return $data;
    }
}
