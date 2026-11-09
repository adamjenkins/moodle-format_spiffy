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

/**
 * Web services of the Spiffy course format.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'format_spiffy_inline_action' => [
        'classname' => \format_spiffy\external\inline_action::class,
        'description' => 'Do an activity inside its card (vote, reply, submit…) and return the updated sections.',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_get_sections' => [
        'classname' => \format_spiffy\external\get_sections::class,
        'description' => 'The card-view sections of a course, to refresh the page while it is open.',
        'type' => 'read',
        'ajax' => true,
    ],
    'format_spiffy_card_viewed' => [
        'classname' => \format_spiffy\external\card_viewed::class,
        'description' => 'Remember that the current user put an activity card on stage, for "Recently viewed".',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_save_note' => [
        'classname' => \format_spiffy\external\save_note::class,
        'description' => 'Save the current user\'s private note on an activity card.',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_ask_teacher' => [
        'classname' => \format_spiffy\external\ask_teacher::class,
        'description' => 'Send a teacher a question about an activity through Moodle messaging.',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_set_confidence' => [
        'classname' => \format_spiffy\external\set_confidence::class,
        'description' => 'Record how confident the current user feels after finishing an activity.',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_message_unfinished' => [
        'classname' => \format_spiffy\external\message_unfinished::class,
        'description' => 'Message the students who have not finished an activity yet.',
        'type' => 'write',
        'ajax' => true,
    ],
    'format_spiffy_mark_questions_seen' => [
        'classname' => \format_spiffy\external\mark_questions_seen::class,
        'description' => 'Mark the questions students sent about an activity as seen by the current teacher.',
        'type' => 'write',
        'ajax' => true,
    ],
];
