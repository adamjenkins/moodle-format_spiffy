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
use core_external\external_single_structure;
use core_external\external_value;
use format_spiffy\local\teacher;

/**
 * Mark the questions students sent about an activity as seen by the current teacher.
 *
 * Only the current user's own questions change (format_spiffy_question.teacherid). Returns the
 * teacher spotlight again, so the page can replace it without a reload.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_questions_seen extends external_api {
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
     * Mark the questions as seen.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $PAGE;
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        self::validate_context(\core\context\module::instance($cm->id));
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }

        if (!course_get_format($course)->feature_enabled('teachertools')) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', 'mark_questions_seen');
        }
        $count = teacher::mark_questions_seen(get_fast_modinfo($course)->get_cm($cm->id));

        // The spotlight is outside the sections, so it comes back here.
        self::validate_context(\core\context\course::instance($course->id));
        $format = course_get_format($course->id);
        $renderer = $format->get_renderer($PAGE);
        $spotlight = teacher::export_page($format, new \stdClass(), $renderer);

        return [
            'count' => $count,
            'message' => get_string('teacherquestionsseen', 'format_spiffy', $count),
            'spotlight' => $spotlight ? $renderer->render_from_template('format_spiffy/local/teacher_spotlight', $spotlight) : '',
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'How many questions were marked as seen'),
            'message' => new external_value(PARAM_TEXT, 'The message to show'),
            'spotlight' => new external_value(PARAM_RAW, 'The teacher spotlight HTML, or empty'),
        ]);
    }
}
