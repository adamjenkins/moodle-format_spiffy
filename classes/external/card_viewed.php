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
use format_spiffy\local\recent;

/**
 * Remember that the current user put an activity card on stage, for "Recently viewed".
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class card_viewed extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Remember the view.
     *
     * @param int $cmid
     * @return null
     */
    public static function execute(int $cmid) {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        self::validate_context(\core\context\module::instance($cm->id));
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }
        $cm = get_fast_modinfo($course)->get_cm($cm->id);
        if ($cm->uservisible && !isguestuser() && course_get_format($course)->feature_enabled('recent')) {
            recent::record($cm);
        }
        return null;
    }

    /**
     * Return structure.
     *
     * @return null
     */
    public static function execute_returns() {
        return null;
    }
}
