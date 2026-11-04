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

namespace format_spiffy\local;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use stdClass;

/**
 * Renders the card-view sections of a course for the current user, for the web services that
 * update the page without a reload.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sections {
    /**
     * Render the sections.
     *
     * The caller has validated the course context, so $PAGE is set up for the course.
     *
     * @param stdClass $course
     * @param int $sectionid the section whose page (course/section.php) to render, 0 for the course page
     * @return array[] each ['id' => section id, 'hash' => string, 'html' => string], in page order
     */
    public static function render(stdClass $course, int $sectionid = 0): array {
        global $PAGE;
        \core_courseformat\base::reset_course_cache($course->id);
        $format = course_get_format($course->id);
        if ($sectionid) {
            $sectioninfo = $format->get_modinfo()->get_section_info_by_id($sectionid);
            if (!$sectioninfo || !$sectioninfo->uservisible) {
                return [];
            }
            $format->set_sectionid($sectionid);
        } else {
            $format->set_sectionid(null);
        }
        if (!$PAGE->has_set_url()) {
            // From a web service: render the HTML as the page it is for.
            $PAGE->set_url($sectionid ? new \moodle_url('/course/section.php', ['id' => $sectionid])
                : new \moodle_url('/course/view.php', ['id' => $course->id]));
        }
        $renderer = $format->get_renderer($PAGE);
        $class = $format->get_output_classname('content');
        /** @var \format_spiffy\output\courseformat\content $content */
        $content = new $class($format);
        if (!$content->is_card_view()) {
            return [];
        }
        $data = $content->export_for_template($renderer);
        $result = [];
        foreach ($data->spiffysections as $section) {
            $result[] = ['id' => (int)$section->id, 'hash' => $section->spiffyhash, 'html' => $section->spiffyhtml];
        }
        return $result;
    }

    /**
     * A hash of section HTML that ignores the random ids templates generate, so an unchanged
     * section hashes the same on every render.
     *
     * @param string $html
     * @return string
     */
    public static function hash(string $html): string {
        return sha1(preg_replace('/(random)?[0-9a-f]{13,}/', '', $html));
    }

    /**
     * The web service return structure of render().
     *
     * @return external_multiple_structure
     */
    public static function returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section id'),
            'hash' => new external_value(PARAM_ALPHANUM, 'Hash of the section HTML'),
            'html' => new external_value(PARAM_RAW, 'Section HTML'),
        ]));
    }
}
