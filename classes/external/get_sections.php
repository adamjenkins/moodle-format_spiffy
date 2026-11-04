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

namespace format_spiffy\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_multiple_structure;
use format_spiffy\local\sections;

/**
 * The card-view sections of a course, for refreshing the page while it is open.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_sections extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'sectionid' => new external_value(PARAM_INT, 'Section of the page, 0 for the course page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Render the sections.
     *
     * @param int $courseid
     * @param int $sectionid the section whose page this is, 0 for the course page
     * @return array
     */
    public static function execute(int $courseid, int $sectionid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'sectionid' => $sectionid]
        );
        $course = get_course($params['courseid']);
        self::validate_context(\core\context\course::instance($course->id));
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }
        return sections::render($course, $params['sectionid']);
    }

    /**
     * Return structure.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return sections::returns();
    }
}
