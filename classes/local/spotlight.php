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

use core_courseformat\base as course_format;
use completion_info;
use format_spiffy\local\inline\handler;
use format_spiffy\local\inline\registry;
use renderer_base;
use stdClass;

/**
 * Spotlight mode: page-level data for the stage layout.
 *
 * The current actionable card goes on centre stage: the open, unfinished steps of Moodle's
 * highlighted section, or else the first open, unfinished step of the course. Steps whose
 * completion is tracked come first, so an activity that can never be completed (such as the
 * announcements forum) does not hold the stage. The page script (format_spiffy/spotlight) does
 * the layout, and works the same choice out again after sections are replaced.
 *
 * The "What's next" summary lists the student's open steps that are due soon or overdue, then the
 * other unfinished ones, each linking to its card.
 *
 * Everything here reads the page export (card states, dates) and runs while the page renders, so
 * it calls no web service code.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spotlight {
    /** @var string User preference with the view mode. */
    public const PREFERENCE = 'format_spiffy_viewmode';

    /** @var string View mode: the current card on centre stage. */
    public const MODE_SPOTLIGHT = 'spotlight';

    /** @var string View mode: every card in the section grid ("Show me everything"). */
    public const MODE_LIST = 'list';

    /** @var int A date this close (in seconds) counts as due soon. */
    public const DUE_SOON = 7 * 24 * 60 * 60;

    /** @var int How many items "What's next" lists. */
    public const MAX_ITEMS = 5;

    /** @var int "What's next" suggests something rather than show fewer items than this. */
    public const MIN_ITEMS = 2;

    /** @var string[] Activity date ids (core\activity_dates dataid) that mean "be done by then". */
    private const DUE_DATES = [
        'assessmentend', 'cutoffdate', 'deadline', 'duedate', 'submissionend', 'timeavailableto', 'timeclose', 'timedue',
    ];

    /**
     * The current user's view mode.
     *
     * @return string MODE_SPOTLIGHT or MODE_LIST
     */
    public static function get_viewmode(): string {
        $mode = get_user_preferences(self::PREFERENCE, self::MODE_SPOTLIGHT);
        return $mode === self::MODE_LIST ? self::MODE_LIST : self::MODE_SPOTLIGHT;
    }

    /**
     * Template context of the spotlight layout.
     *
     * @param course_format $format
     * @param stdClass $data the page export so far, with spiffysections
     * @param renderer_base $output
     * @return array context
     */
    public static function export_page(course_format $format, stdClass $data, renderer_base $output): array {
        // The course setting "Spotlight view" off: always the full list, without the toggle.
        $canswitch = $format->feature_enabled('spotlight');
        $mode = $canswitch ? self::get_viewmode() : self::MODE_LIST;
        $steps = self::get_steps($data->spiffysections ?? []);
        $context = \core\context\course::instance($format->get_courseid());
        // Teachers have the teacher spotlight; the to-do list is the student's.
        $whatsnext = has_capability('moodle/course:manageactivities', $context) || !$format->feature_enabled('whatsnext') ?
            false : self::export_whats_next($format, $steps, time());
        // A course with nothing open and nothing finished has nothing to say.
        if ($whatsnext && !$whatsnext['hasitems']) {
            $whatsnext = false;
        }
        // The recent links go inside the block that is showing, or else stand alone in the toolbar.
        $inteacherblock = !is_role_switched($format->get_courseid()) && !$format->show_editor()
            && teacher::is_teacher($context) && $format->feature_enabled('teachertools');
        $recent = $inteacherblock ? false : recent::export($format);
        if ($whatsnext) {
            $whatsnext['recent'] = $recent;
            $recent = false;
        }
        return [
            'courseid' => (int)$format->get_courseid(),
            'viewmode' => $mode,
            'canswitch' => $canswitch,
            'hastoolbar' => $canswitch || $whatsnext || $recent,
            'isspotlight' => $mode === self::MODE_SPOTLIGHT,
            'islist' => $mode === self::MODE_LIST,
            'stage' => implode(' ', self::get_stage($steps)),
            'whatsnext' => $whatsnext,
            'recent' => $recent,
        ];
    }

    /**
     * The step cards of the exported sections, subsections included, in page order.
     *
     * @param stdClass[] $sections the exported sections (spiffysections)
     * @return stdClass[] each {cmid, format (the card's cmformat), sectionid, sectionname, iscurrent}
     */
    public static function get_steps(array $sections): array {
        $steps = [];
        foreach ($sections as $section) {
            self::collect_steps($section->spiffyitems ?? [], $section, $steps);
        }
        return $steps;
    }

    /**
     * Add the step cards of a list of items to $steps.
     *
     * @param stdClass[] $items exported items
     * @param stdClass $section the top-level section they are in
     * @param stdClass[] $steps the list to add to
     * @return void
     */
    private static function collect_steps(array $items, stdClass $section, array &$steps): void {
        foreach ($items as $item) {
            $format = $item->cmformat;
            if (!empty($format->spiffysub)) {
                self::collect_steps($format->spiffysub->spiffyitems ?? [], $section, $steps);
                continue;
            }
            if (empty($format->spiffystep) || !isset($format->spiffystate)) {
                continue;
            }
            $steps[] = (object)[
                'cmid' => (int)$item->id,
                'format' => $format,
                'sectionid' => (int)$section->id,
                'sectionname' => (string)($section->sectionname ?? ''),
                'iscurrent' => !empty($section->iscurrent),
            ];
        }
    }

    /**
     * The cards that start on centre stage.
     *
     * Of the open, unfinished steps (those with tracked completion if there are any): all of the
     * highlighted section's, or else the first one.
     *
     * @param stdClass[] $steps from get_steps()
     * @return int[] course module ids, in page order; empty when nothing is left to do
     */
    public static function get_stage(array $steps): array {
        $open = array_filter($steps, fn($step) => $step->format->spiffystate === handler::STATE_AVAILABLE);
        $tracked = array_filter($open, fn($step) => !empty($step->format->spiffytracked));
        $pool = $tracked ?: $open;
        $current = array_filter($pool, fn($step) => $step->iscurrent);
        $chosen = $current ?: array_slice($pool, 0, 1);
        return array_values(array_map(fn($step) => $step->cmid, $chosen));
    }

    /**
     * Template context of "What's next", for the whole course whichever page it is shown on.
     *
     * First the open activities due soon or overdue (soonest first), then the other unfinished ones
     * with tracked completion, those the user has not opened yet first. Then suggestions, so that a
     * student notices what is there: open activities that do not track completion, unopened ones
     * first, and when there would be fewer than two items, finished ones to look at again.
     *
     * @param course_format $format
     * @param stdClass[] $steps from get_steps(): the cards of this page
     * @param int $now the current time
     * @return array context
     */
    public static function export_whats_next(course_format $format, array $steps, int $now): array {
        $seen = self::get_seen_cmids($format);
        $due = [];
        $unfinished = [];
        $suggestions = [];
        $finished = [];
        foreach (self::get_whats_next_entries($format, $steps) as $entry) {
            if ($entry->state === handler::STATE_DONE) {
                $finished[] = $entry;
                continue;
            }
            if ($entry->state !== handler::STATE_AVAILABLE) {
                continue;
            }
            $entry->unseen = !isset($seen[$entry->cmid]);
            $entry->date = self::get_due_date($entry, $format->get_modinfo());
            if ($entry->date && $entry->date['timestamp'] <= $now + self::DUE_SOON) {
                $due[] = $entry;
            } else if ($entry->tracked) {
                $unfinished[] = $entry;
            } else {
                $suggestions[] = $entry;
            }
        }
        usort($due, fn($a, $b) => $a->date['timestamp'] <=> $b->date['timestamp']);
        $unseenfirst = fn($a, $b) => (int)$b->unseen <=> (int)$a->unseen;
        usort($unfinished, $unseenfirst);
        usort($suggestions, $unseenfirst);

        $main = array_merge($due, $unfinished);
        $shown = array_slice($main, 0, self::MAX_ITEMS);
        $room = self::MAX_ITEMS - count($shown);
        // Unopened activities are always worth a mention; the rest only to reach the minimum.
        $unopened = array_filter($suggestions, fn($entry) => $entry->unseen);
        $wanted = max(count($unopened), self::MIN_ITEMS - count($shown));
        $extra = array_slice(array_merge($suggestions, $finished), 0, min($room, max(0, $wanted)));

        $export = fn($entry) => self::export_item($entry, $entry->date ?? null, $now);
        $items = array_map($export, $shown);
        $suggested = array_map($export, $extra);
        $more = count($main) - count($items);
        return [
            'courseid' => (int)$format->get_courseid(),
            'hasitems' => !empty($items) || !empty($suggested),
            'main' => $items ? ['issuggestions' => false, 'items' => $items] : false,
            'suggestions' => $suggested ? ['issuggestions' => true, 'items' => $suggested] : false,
            'total' => count($main),
            'more' => $more > 0 ? get_string('whatsnextmore', 'format_spiffy', $more) : '',
        ];
    }

    /**
     * What "What's next" can choose from: this page's cards, then the course's other activities.
     *
     * @param course_format $format
     * @param stdClass[] $steps from get_steps()
     * @return stdClass[] each {cmid, name, sectionname, state, tracked, onpage, format}
     */
    private static function get_whats_next_entries(course_format $format, array $steps): array {
        global $USER;
        $entries = [];
        foreach ($steps as $step) {
            $entries[$step->cmid] = (object)[
                'courseid' => (int)$format->get_courseid(),
                'cmid' => $step->cmid,
                'name' => (string)($step->format->activityname ?? ''),
                'sectionname' => $step->sectionname,
                'state' => $step->format->spiffystate,
                'tracked' => !empty($step->format->spiffytracked),
                'onpage' => true,
                'format' => $step->format,
            ];
        }
        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $completion = new completion_info($course);
        $istracked = $completion->is_enabled() && $completion->is_tracked_user($USER->id);
        $inline = $format->feature_enabled('inline');
        foreach ($modinfo->get_cms() as $cm) {
            $cmid = (int)$cm->id;
            if (
                isset($entries[$cmid]) || !$cm->uservisible || !$cm->is_visible_on_course_page() || !$cm->has_view()
                    || $cm->deletioninprogress || $cm->get_delegated_section_info()
            ) {
                continue;
            }
            $handler = $inline ? registry::get_handler($cm, cm_settings::get($cmid)) : null;
            $done = $handler ? $handler->is_done() : handler::is_complete($cm);
            $entries[$cmid] = (object)[
                'courseid' => (int)$format->get_courseid(),
                'cmid' => $cmid,
                'name' => $cm->get_formatted_name(),
                'sectionname' => get_section_name($course, $cm->sectionnum),
                'state' => $done ? handler::STATE_DONE : handler::STATE_AVAILABLE,
                'tracked' => $istracked && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE,
                'onpage' => false,
                'format' => null,
            ];
        }
        return $entries;
    }

    /**
     * The activities of this course the current user has opened, from the standard log.
     *
     * @param course_format $format
     * @return array course module ids as keys; empty when the standard log store is not enabled
     */
    private static function get_seen_cmids(course_format $format): array {
        global $DB, $USER;
        $readers = get_log_manager()->get_readers('\\core\\log\\sql_internal_table_reader');
        if (!isset($readers['logstore_standard'])) {
            return [];
        }
        $table = $readers['logstore_standard']->get_internal_log_table_name();
        return $DB->get_records_sql_menu(
            "SELECT DISTINCT contextinstanceid, 1
               FROM {" . $table . "}
              WHERE courseid = :courseid AND userid = :userid AND contextlevel = :contextlevel AND crud = :crud",
            ['courseid' => $format->get_courseid(), 'userid' => $USER->id, 'contextlevel' => CONTEXT_MODULE, 'crud' => 'r']
        );
    }

    /**
     * The date a step is due by, for the current user: the earliest of its "be done by" dates.
     *
     * Uses the dates the card already shows; when the course does not show activity dates on
     * cards, asks core\activity_dates (the same source).
     *
     * @param stdClass $step an entry of get_whats_next_entries()
     * @param \course_modinfo $modinfo
     * @return array|null ['label' => string, 'timestamp' => int], or null for none
     */
    private static function get_due_date(stdClass $step, \course_modinfo $modinfo): ?array {
        global $USER;
        if (isset($step->format->dates->activitydates)) {
            $dates = $step->format->dates->activitydates;
        } else {
            $dates = \core\activity_dates::get_dates_for_module($modinfo->get_cm($step->cmid), (int)$USER->id);
        }
        $best = null;
        foreach ($dates as $date) {
            $date = (array)$date;
            if (!in_array($date['dataid'] ?? '', self::DUE_DATES, true) || empty($date['timestamp'])) {
                continue;
            }
            if ($best === null || $date['timestamp'] < $best['timestamp']) {
                $best = ['label' => (string)($date['label'] ?? ''), 'timestamp' => (int)$date['timestamp']];
            }
        }
        return $best;
    }

    /**
     * Context of one "What's next" item.
     *
     * @param stdClass $step an entry of get_whats_next_entries()
     * @param array|null $date from get_due_date()
     * @param int $now the current time
     * @return array
     */
    private static function export_item(stdClass $step, ?array $date, int $now): array {
        $badge = false;
        $datetext = '';
        if ($date) {
            $datetext = get_string('whatsnextdate', 'format_spiffy', (object)[
                'label' => $date['label'],
                'date' => userdate($date['timestamp'], get_string('strftimedaydatetime', 'core_langconfig')),
            ]);
            if ($date['timestamp'] < $now) {
                $badge = ['text' => get_string('overdue', 'format_spiffy'), 'type' => 'danger'];
            } else if ($date['timestamp'] <= $now + self::DUE_SOON) {
                $badge = ['text' => get_string('duesoon', 'format_spiffy'), 'type' => 'warning'];
            }
        }
        return [
            'cmid' => $step->cmid,
            'name' => $step->name,
            'sectionname' => $step->sectionname,
            'onpage' => $step->onpage,
            'url' => (new \moodle_url('/course/view.php', ['id' => $step->courseid], 'card-' . $step->cmid))->out(false),
            'datetext' => $datetext,
            'badge' => $badge,
            'overdue' => $badge && $badge['type'] === 'danger',
        ];
    }
}
