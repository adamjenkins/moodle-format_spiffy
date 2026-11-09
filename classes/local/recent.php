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

use cm_info;
use core\url as moodle_url;
use core_courseformat\base as course_format;

/**
 * "Recently viewed": what the current user was just looking at, so a refresh doesn't lose their place.
 *
 * Merges two sources by time. Putting a card on stage writes nothing to Moodle's log, so those views
 * are kept in a per-course user preference (format_spiffy_card_viewed). Activity pages the user
 * opened come from the standard log.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recent {
    /** @var string User preference, followed by the course id: JSON of {cmid: time viewed}. */
    public const PREFERENCE = 'format_spiffy_recent_';

    /** @var int The most links shown. */
    public const MAX_ITEMS = 5;

    /** @var int Longest link text, in characters, the ellipsis included. */
    public const NAME_LENGTH = 20;

    /** @var int Card views kept, more than are shown because some may be hidden later. */
    private const MAX_STORED = 10;

    /** @var int Activities read from the log, more than are shown for the same reason. */
    private const MAX_LOGGED = 20;

    /**
     * Remember that the current user put a card on stage.
     *
     * @param cm_info $cm
     * @param int|null $time when, or now
     * @return void
     */
    public static function record(cm_info $cm, ?int $time = null): void {
        $views = self::get_card_views((int)$cm->course);
        unset($views[(int)$cm->id]);
        $views = [(int)$cm->id => $time ?? time()] + $views;
        arsort($views);
        set_user_preference(self::PREFERENCE . $cm->course, json_encode(array_slice($views, 0, self::MAX_STORED, true)));
    }

    /**
     * Template context of the links.
     *
     * @param course_format $format
     * @return array|false ['items' => [...]], or false when there is nothing to show
     */
    public static function export(course_format $format) {
        if (isguestuser() || $format->show_editor() || !$format->feature_enabled('recent')) {
            return false;
        }
        $courseid = (int)$format->get_courseid();
        $views = self::get_card_views($courseid);
        foreach (self::get_logged_views($courseid) as $cmid => $time) {
            $views[$cmid] = max($views[$cmid] ?? 0, $time);
        }
        arsort($views);

        $modinfo = $format->get_modinfo();
        $cms = $modinfo->get_cms();
        $items = [];
        foreach (array_keys($views) as $cmid) {
            $cm = $cms[$cmid] ?? null;
            if (
                !$cm || !$cm->uservisible || !$cm->is_visible_on_course_page() || !$cm->has_view()
                    || $cm->deletioninprogress || $cm->get_delegated_section_info()
            ) {
                continue;
            }
            $items[] = self::export_item($cm, $courseid);
            if (count($items) === self::MAX_ITEMS) {
                break;
            }
        }
        return $items ? ['items' => $items] : false;
    }

    /**
     * Context of one link.
     *
     * @param cm_info $cm
     * @param int $courseid
     * @return array
     */
    private static function export_item(cm_info $cm, int $courseid): array {
        $fullname = strip_tags($cm->get_formatted_name(['escape' => false]));
        $name = $fullname;
        if (\core_text::strlen($name) > self::NAME_LENGTH) {
            $name = rtrim(\core_text::substr($name, 0, self::NAME_LENGTH - 1)) . "\u{2026}";
        }
        return [
            'cmid' => (int)$cm->id,
            'name' => $name,
            'fullname' => $fullname,
            'url' => (new moodle_url('/course/view.php', ['id' => $courseid], 'card-' . $cm->id))->out(false),
        ];
    }

    /**
     * The cards the current user put on stage in a course.
     *
     * @param int $courseid
     * @return int[] time viewed, by course module id
     */
    private static function get_card_views(int $courseid): array {
        $views = json_decode((string)get_user_preferences(self::PREFERENCE . $courseid, ''), true);
        return is_array($views) ? array_map('intval', $views) : [];
    }

    /**
     * The activities the current user last opened in a course, from the standard log.
     *
     * @param int $courseid
     * @return int[] time viewed, by course module id; empty when the standard log store is not enabled
     */
    private static function get_logged_views(int $courseid): array {
        global $DB, $USER;
        $readers = get_log_manager()->get_readers('\\core\\log\\sql_internal_table_reader');
        if (!isset($readers['logstore_standard'])) {
            return [];
        }
        $table = $readers['logstore_standard']->get_internal_log_table_name();
        $rows = $DB->get_records_sql_menu(
            "SELECT contextinstanceid, MAX(timecreated) AS lastviewed
               FROM {" . $table . "}
              WHERE courseid = :courseid AND userid = :userid AND contextlevel = :contextlevel AND crud = :crud
           GROUP BY contextinstanceid
           ORDER BY lastviewed DESC",
            ['courseid' => $courseid, 'userid' => $USER->id, 'contextlevel' => CONTEXT_MODULE, 'crud' => 'r'],
            0,
            self::MAX_LOGGED
        );
        return array_map('intval', $rows);
    }
}
