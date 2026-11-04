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
use format_spiffy\local\cm_settings;
use format_spiffy\local\inline\registry;
use format_spiffy\local\sections;

/**
 * Perform an action on a card (vote, reply, submit…) and return the updated sections.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inline_action extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action name'),
            'data' => new external_value(PARAM_RAW, 'The action data, a JSON object of strings and arrays of strings'),
            'sectionid' => new external_value(PARAM_INT, 'Section of the page, 0 for the course page', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Perform the action.
     *
     * @param int $cmid
     * @param string $action
     * @param string $data JSON
     * @param int $sectionid the section whose page this is, 0 for the course page
     * @return array
     */
    public static function execute(int $cmid, string $action, string $data, int $sectionid = 0): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'action' => $action, 'data' => $data, 'sectionid' => $sectionid]
        );

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid']);
        $context = \core\context\module::instance($cm->id);
        // Checks the login, enrolment, visibility and access restrictions of the activity.
        self::validate_context($context);
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }

        $handler = course_get_format($course)->feature_enabled('inline') ?
            registry::get_handler(get_fast_modinfo($course)->get_cm($cm->id), cm_settings::get($cm->id)) : null;
        if (!$handler || !$handler->accepts_actions()) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $params['action']);
        }
        $decoded = json_decode($params['data'], true);
        $message = $handler->process_action($params['action'], self::clean_data(is_array($decoded) ? $decoded : []));

        // Re-render the page the action came from: it can unlock activities in any section.
        self::validate_context(\core\context\course::instance($course->id));
        return [
            'message' => (string)$message,
            'sections' => sections::render(get_course($course->id), $params['sectionid']),
        ];
    }

    /**
     * Reduce submitted data to strings and lists of strings.
     *
     * @param array $data
     * @return array
     */
    protected static function clean_data(array $data): array {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[(string)$key] = array_values(array_map('strval', array_filter($value, 'is_scalar')));
            } else if (is_scalar($value)) {
                $result[(string)$key] = (string)$value;
            }
        }
        return $result;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_TEXT, 'A message to show, or empty'),
            'sections' => sections::returns(),
        ]);
    }
}
