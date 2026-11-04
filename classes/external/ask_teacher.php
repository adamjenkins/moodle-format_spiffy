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
use format_spiffy\local\tools;

/**
 * Send a teacher a question about an activity through Moodle messaging.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ask_teacher extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'teacherid' => new external_value(PARAM_INT, 'User id of the teacher to ask'),
            'message' => new external_value(PARAM_RAW, 'The question, plain text (tags are removed)'),
        ]);
    }

    /**
     * Send the question.
     *
     * @param int $cmid
     * @param int $teacherid
     * @param string $message
     * @return array
     */
    public static function execute(int $cmid, int $teacherid, string $message): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['cmid' => $cmid, 'teacherid' => $teacherid, 'message' => $message]
        );
        $cm = tools::validate_card($params['cmid'], 'askteacher');
        return ['message' => tools::ask_teacher($cm, $params['teacherid'], $params['message'])];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_TEXT, 'The message to show'),
        ]);
    }
}
