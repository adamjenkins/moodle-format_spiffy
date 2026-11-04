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
 * Message the students who have not finished an activity yet.
 *
 * Called twice from the card: first with dryrun set, for the confirmation step that shows how many
 * students will get the message, then to send it.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_unfinished extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'message' => new external_value(PARAM_RAW, 'The message, plain text (tags are removed)'),
            'dryrun' => new external_value(PARAM_BOOL, 'Only count the recipients', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Count the recipients, or send the message.
     *
     * @param int $cmid
     * @param string $message
     * @param bool $dryrun
     * @return array
     */
    public static function execute(int $cmid, string $message, bool $dryrun = false): array {
        global $CFG;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'message' => $message, 'dryrun' => $dryrun]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        self::validate_context(\core\context\module::instance($cm->id));
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }
        if (!course_get_format($course)->feature_enabled('teachertools')) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', 'message_unfinished');
        }
        if (empty($CFG->messaging)) {
            throw new \moodle_exception('disabled', 'message');
        }
        require_capability('moodle/course:bulkmessaging', \core\context\course::instance($course->id));

        $cm = get_fast_modinfo($course)->get_cm($cm->id);
        return teacher::message_unfinished($cm, $params['message'], $params['dryrun']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'How many students will get it (dry run) or got it'),
            'message' => new external_value(PARAM_TEXT, 'The message to show'),
        ]);
    }
}
