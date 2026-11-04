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
 * Record how confident the current user feels after finishing an activity.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_confidence extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'confidence' => new external_value(PARAM_INT, '1 not confident, 2 unsure, 3 confident'),
        ]);
    }

    /**
     * Record the confidence check.
     *
     * @param int $cmid
     * @param int $confidence
     * @return array
     */
    public static function execute(int $cmid, int $confidence): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'confidence' => $confidence]);
        $cm = tools::validate_card($params['cmid'], 'confidence');
        return ['message' => tools::set_confidence($cm, $params['confidence'])];
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
