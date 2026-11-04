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

namespace format_spiffy\output;

use core_courseformat\output\local\content\delegatedsection;
use core_courseformat\output\section_renderer;

/**
 * Renderer for the Spiffy course format.
 *
 * @package    format_spiffy
 * @copyright  2012 Dan Poltawski
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends section_renderer {
    /**
     * Generate the section title, wrapped in an inplace editable.
     *
     * @param \section_info|\stdClass $section The course_section entry from DB
     * @param \stdClass $course The course entry from DB
     * @return string HTML to output.
     */
    public function section_title($section, $course) {
        return $this->render(course_get_format($course)->inplace_editable_render_section_name($section));
    }

    /**
     * Generate the section title to be displayed on the section page, without a link.
     *
     * @param \section_info|\stdClass $section The course_section entry from DB
     * @param int|\stdClass $course The course entry from DB
     * @return string HTML to output.
     */
    public function section_title_without_link($section, $course) {
        return $this->render(course_get_format($course)->inplace_editable_render_section_name($section, false));
    }

    /**
     * Render a subsection as the content of its mod_subsection activity (subsection_cm_info_view()).
     *
     * The card layout draws subsections itself and never prints that activity content. Rendering
     * it anyway would queue the JavaScript of its completion dialogs for elements that are never on
     * the page, which throws "Dopdown dialog element not found" in the browser. So on the card
     * layout (the course page and section pages) nothing is rendered; in editing mode core's output
     * is kept.
     *
     * @param delegatedsection $widget
     * @return string HTML
     */
    public function render_delegatedsection(delegatedsection $widget): string {
        $format = course_get_format($this->page->course);
        if (str_starts_with($this->page->pagetype, 'course-view-') && !$format->show_editor()) {
            return '';
        }
        return $this->render_from_template($widget->get_template_name($this), $widget->export_for_template($this));
    }
}
