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

use format_spiffy;
use stdClass;

/**
 * Spiffy display settings of activities: button label, width, accent and inline mode.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cm_settings {
    /** @var string The settings table. */
    private const TABLE = 'format_spiffy_cm';

    /** @var string Inline mode: the activity type's default. */
    public const INLINE_AUTO = 'auto';

    /** @var string Inline mode: done inside the card when the activity type allows it. */
    public const INLINE_INLINE = 'inline';

    /** @var string Inline mode: the card opens the activity page. */
    public const INLINE_PAGE = 'page';

    /** @var string[] Tables with one row per activity (and user) that go when the activity goes. */
    private const CM_TABLES = ['format_spiffy_cm', 'format_spiffy_note', 'format_spiffy_confidence', 'format_spiffy_question'];

    /**
     * The default settings.
     *
     * @return stdClass
     */
    public static function defaults(): stdClass {
        return (object)[
            'buttonlabel' => '',
            'width' => format_spiffy::WIDTH_AUTO,
            'accent' => 'auto',
            'inlinemode' => self::INLINE_AUTO,
        ];
    }

    /**
     * Settings of one activity, or the defaults.
     *
     * @param int $cmid course module id, 0 for a new activity
     * @return stdClass
     */
    public static function get(int $cmid): stdClass {
        global $DB;
        $record = $cmid ? $DB->get_record(self::TABLE, ['cmid' => $cmid]) : false;
        return $record ? self::clean($record) : self::defaults();
    }

    /**
     * Settings of every activity in a course that has any, keyed by course module id.
     *
     * @param int $courseid
     * @return stdClass[]
     */
    public static function get_for_course(int $courseid): array {
        global $DB;
        $result = [];
        foreach ($DB->get_records(self::TABLE, ['courseid' => $courseid]) as $record) {
            $result[(int)$record->cmid] = self::clean($record);
        }
        return $result;
    }

    /**
     * Save the settings of one activity.
     *
     * @param int $courseid
     * @param int $cmid
     * @param stdClass $settings with buttonlabel, width, accent and inlinemode
     * @return void
     */
    public static function save(int $courseid, int $cmid, stdClass $settings): void {
        global $DB;
        $record = self::clean($settings);
        $record->courseid = $courseid;
        $record->cmid = $cmid;
        if ($existing = $DB->get_record(self::TABLE, ['cmid' => $cmid], 'id')) {
            $record->id = $existing->id;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }

    /**
     * Normalise settings to known values.
     *
     * @param stdClass $settings
     * @return stdClass
     */
    public static function clean(stdClass $settings): stdClass {
        $widths = [format_spiffy::WIDTH_AUTO, format_spiffy::WIDTH_COLUMN, format_spiffy::WIDTH_FULL];
        $width = in_array($settings->width ?? '', $widths, true) ? $settings->width : format_spiffy::WIDTH_AUTO;
        $accent = in_array($settings->accent ?? '', format_spiffy::ACCENTS, true) ? $settings->accent : 'auto';
        $modes = [self::INLINE_AUTO, self::INLINE_INLINE, self::INLINE_PAGE];
        $inlinemode = in_array($settings->inlinemode ?? '', $modes, true) ? $settings->inlinemode : self::INLINE_AUTO;
        return (object)[
            'buttonlabel' => \core_text::substr(trim(clean_param($settings->buttonlabel ?? '', PARAM_TEXT)), 0, 255),
            'width' => $width,
            'accent' => $accent,
            'inlinemode' => $inlinemode,
        ];
    }

    /**
     * Delete the settings, notes, confidence checks and question log of a deleted activity.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;
        foreach (self::CM_TABLES as $table) {
            $DB->delete_records($table, ['cmid' => $event->objectid]);
        }
    }

    /**
     * Delete everything Spiffy stored for a course whose content was removed, as a restore that
     * deletes existing content does. Core does not fire course_module_deleted there.
     *
     * @param \core\event\course_content_deleted $event
     * @return void
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        global $DB;
        foreach (self::CM_TABLES as $table) {
            $DB->delete_records($table, ['courseid' => $event->objectid]);
        }
    }

    /**
     * Clear students' notes, confidence answers, the question log and everyone's "Recently viewed"
     * when a course reset clears completion or unenrols users. Core has no reset hook for course formats.
     *
     * @param \core\event\course_reset_ended $event
     * @return void
     */
    public static function course_reset_ended(\core\event\course_reset_ended $event): void {
        global $DB;
        $options = $event->other['reset_options'] ?? [];
        if (empty($options['reset_completion']) && empty($options['unenrol_users'])) {
            return;
        }
        foreach (array_diff(self::CM_TABLES, [self::TABLE]) as $table) {
            $DB->delete_records($table, ['courseid' => $event->courseid]);
        }
        $DB->delete_records('user_preferences', ['name' => recent::PREFERENCE . $event->courseid]);
    }

    /**
     * Delete a deleted user's notes and confidence answers, and their name from the question log.
     *
     * @param \core\event\user_deleted $event
     * @return void
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        global $DB;
        $DB->delete_records('format_spiffy_note', ['userid' => $event->objectid]);
        $DB->delete_records('format_spiffy_confidence', ['userid' => $event->objectid]);
        self::anonymise_questions((int)$event->objectid);
    }

    /**
     * Take a user out of the question log. The other person's record of the question stays;
     * a row with nobody left is deleted.
     *
     * @param int $userid
     * @param int|null $cmid one activity; null for all
     * @return void
     */
    public static function anonymise_questions(int $userid, ?int $cmid = null): void {
        global $DB;
        $where = $cmid === null ? [] : ['cmid' => $cmid];
        $DB->set_field('format_spiffy_question', 'userid', 0, ['userid' => $userid] + $where);
        $DB->set_field('format_spiffy_question', 'teacherid', 0, ['teacherid' => $userid] + $where);
        $DB->delete_records('format_spiffy_question', ['userid' => 0, 'teacherid' => 0] + $where);
    }

    /**
     * Delete everything Spiffy stored for a deleted course.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        global $DB;
        foreach (self::CM_TABLES as $table) {
            $DB->delete_records($table, ['courseid' => $event->objectid]);
        }
        $DB->delete_records('user_preferences', ['name' => recent::PREFERENCE . $event->objectid]);
    }
}
