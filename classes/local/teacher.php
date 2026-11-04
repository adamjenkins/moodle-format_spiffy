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
use completion_info;
use core\context\course as context_course;
use core\url as moodle_url;
use core_courseformat\base as course_format;
use renderer_base;
use stdClass;

/**
 * The teacher side of the card view: the teacher spotlight, quick actions and the pencil menu.
 *
 * Everything here is for users who manage or grade the course; students get nothing. The counts
 * for the whole course (work to grade, entries to approve, new questions, students who have not
 * finished or are stuck) are loaded once per request with a handful of grouped queries, because
 * export_card() runs for every card on every page view. Nothing here calls an external function:
 * it runs while the page renders.
 *
 * "Stuck" is kept cheap and explainable: a student is stuck on a step when they finished every
 * step it requires (its "activity completion" restrictions) more than STUCK_DAYS days ago and
 * still have not finished it. Steps without such restrictions never count anyone as stuck.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher {
    /** @var int Days after finishing a step's prerequisites before a student counts as stuck. */
    public const STUCK_DAYS = 7;

    /** @var int How long before the same teacher can message the same activity's students again, in seconds. */
    public const MESSAGE_REPEAT_SECONDS = 60;

    /** @var int Most items listed in the teacher spotlight. */
    public const SPOTLIGHT_LIMIT = 12;

    /** @var int Longest message to the students who have not finished, in characters. */
    public const MESSAGE_MAXLENGTH = 4000;

    /** @var string[] The face shown for each confidence value, as on the student's card. */
    protected const CONFIDENCE_FACES = ['1' => "\u{1F61F}", '2' => "\u{1F610}", '3' => "\u{1F600}"];

    /** @var array Per "courseid:userid": the counts of every activity, from load(). */
    protected static array $cache = [];

    /**
     * Template context of the teacher parts of one card, rendered by format_spiffy/local/card_teacher.
     *
     * @param cm_info $cm the activity
     * @param renderer_base $output
     * @return array context; empty for users who cannot manage or grade the activity
     */
    public static function export_card(cm_info $cm, renderer_base $output): array {
        global $CFG;
        $course = $cm->get_course();
        $coursecontext = context_course::instance($course->id);
        if (!self::is_teacher($coursecontext) || !course_get_format($course)->feature_enabled('teachertools')) {
            return [];
        }
        $cmid = (int)$cm->id;
        $counts = self::load($course)[$cmid] ?? self::empty_counts();
        $name = $cm->get_formatted_name(['escape' => false]);

        $badges = [];
        if ($counts['grading'] > 0) {
            $badges[] = ['label' => get_string('teacherneedsgrading', 'format_spiffy', $counts['grading']),
                'kind' => 'grading', 'url' => self::grading_url($cm)?->out(false)];
        }
        if ($counts['approvals'] > 0) {
            $badges[] = ['label' => get_string('teacherapprovals', 'format_spiffy', $counts['approvals']),
                'kind' => 'approval', 'url' => self::grading_url($cm)?->out(false)];
        }
        if ($counts['stuck'] > 0) {
            $badges[] = ['label' => get_string(
                'teacherstuck',
                'format_spiffy',
                ['count' => $counts['stuck'], 'days' => self::STUCK_DAYS]
            ), 'kind' => 'stuck', 'url' => null];
        }
        if ($counts['notfinished'] !== null) {
            $badges[] = ['label' => get_string('teachernotfinished', 'format_spiffy', $counts['notfinished']),
                'kind' => 'notfinished', 'url' => null];
        }
        foreach ($badges as $i => $badge) {
            $badges[$i]['hasurl'] = !empty($badge['url']);
        }

        $askers = [];
        foreach ($counts['askers'] as $userid => $fullname) {
            $askers[] = [
                'fullname' => $fullname,
                'url' => (new moodle_url('/message/index.php', ['id' => $userid]))->out(false),
            ];
        }

        $confidence = null;
        if (method_exists(tools::class, 'confidence_summary')) {
            $summary = tools::confidence_summary($cmid, self::restricted_groups($cm));
            if (!empty($summary['total'])) {
                $items = [];
                foreach (self::CONFIDENCE_FACES as $value => $face) {
                    $items[] = [
                        'face' => $face,
                        'count' => (int)($summary[$value] ?? 0),
                        'label' => get_string('confidence' . $value, 'format_spiffy'),
                    ];
                }
                $confidence = [
                    'items' => $items,
                    'label' => get_string('teacherconfidence', 'format_spiffy', (int)$summary['total']),
                ];
            }
        }

        $canmessage = !empty($CFG->messaging) && $counts['notfinished'] > 0 && $cm->visible
            && has_capability('moodle/course:bulkmessaging', $coursecontext);

        $actions = self::quick_actions($cm, $counts);
        $menu = self::pencil_menu($cm);

        $haspanel = $badges || $askers || $confidence || $actions || $canmessage;
        if (!$haspanel && !$menu) {
            return [];
        }
        return [
            'cmid' => $cmid,
            'activityname' => $name,
            'haspanel' => (bool)$haspanel,
            'hasbadges' => !empty($badges),
            'badges' => $badges,
            'hasquestions' => $counts['questions'] > 0,
            'questionslabel' => get_string('teacherquestions', 'format_spiffy', $counts['questions']),
            'askers' => $askers,
            'hasconfidence' => $confidence !== null,
            'confidence' => $confidence,
            'hasactions' => !empty($actions) || $canmessage,
            'actions' => $actions,
            'canmessage' => $canmessage,
            'notfinished' => (int)$counts['notfinished'],
            'messagelabel' => get_string('teachermessagelabel', 'format_spiffy', (int)$counts['notfinished']),
            'messagemaxlength' => self::MESSAGE_MAXLENGTH,
            'hasmenu' => !empty($menu),
            'menu' => $menu,
        ];
    }

    /**
     * Template context of the teacher spotlight at the top of the page: what needs the teacher's
     * attention, and the "Preview as student" switch. While the teacher previews as a student, only
     * the way back to their normal role.
     *
     * @param course_format $format
     * @param stdClass $data the page export so far (not needed; the counts come from load())
     * @param renderer_base $output
     * @return array context; empty for users who are not teachers
     */
    public static function export_page(course_format $format, stdClass $data, renderer_base $output): array {
        global $DB, $USER;
        $course = $format->get_course();
        $context = context_course::instance($course->id);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

        if (is_role_switched($course->id)) {
            $roleid = (int)($USER->access['rsw'][$context->path] ?? 0);
            $role = $roleid ? $DB->get_record('role', ['id' => $roleid]) : null;
            return [
                'courseid' => (int)$course->id,
                'isswitched' => true,
                'rolename' => $role ? role_get_name($role, $context) : '',
                'returnurl' => (new moodle_url('/course/switchrole.php', [
                    'id' => $course->id,
                    'switchrole' => 0,
                    'sesskey' => sesskey(),
                    'returnurl' => $courseurl->out_as_local_url(false),
                ]))->out(false),
            ];
        }
        if ($format->show_editor() || !self::is_teacher($context) || !$format->feature_enabled('teachertools')) {
            return [];
        }

        $counts = self::load($course);
        $modinfo = get_fast_modinfo($course);
        $items = [];
        $totals = ['grading' => 0, 'approvals' => 0, 'questions' => 0, 'stuck' => 0];
        foreach ($modinfo->get_cms() as $cm) {
            $cmcounts = $counts[(int)$cm->id] ?? null;
            if (!$cmcounts || !$cm->uservisible) {
                continue;
            }
            $name = $cm->get_formatted_name(['escape' => false]);
            $base = ['cmid' => (int)$cm->id, 'name' => $name, 'focusurl' => '#module-' . $cm->id];
            $gradingurl = self::grading_url($cm);
            if ($cmcounts['grading'] > 0) {
                $totals['grading'] += $cmcounts['grading'];
                $items[] = $base + ['kind' => 'grading', 'order' => 1,
                    'label' => get_string('teacherneedsgrading', 'format_spiffy', $cmcounts['grading']),
                    'actionurl' => $gradingurl?->out(false), 'actionlabel' => get_string('teachergrade', 'format_spiffy')];
            }
            if ($cmcounts['approvals'] > 0) {
                $totals['approvals'] += $cmcounts['approvals'];
                $items[] = $base + ['kind' => 'approval', 'order' => 2,
                    'label' => get_string('teacherapprovals', 'format_spiffy', $cmcounts['approvals']),
                    'actionurl' => $gradingurl?->out(false), 'actionlabel' => get_string('teacherapprove', 'format_spiffy')];
            }
            if ($cmcounts['questions'] > 0) {
                $totals['questions'] += $cmcounts['questions'];
                $items[] = $base + ['kind' => 'questions', 'order' => 0,
                    'label' => get_string('teacherquestions', 'format_spiffy', $cmcounts['questions']),
                    'actionurl' => null, 'actionlabel' => ''];
            }
            if ($cmcounts['stuck'] > 0) {
                $totals['stuck'] += $cmcounts['stuck'];
                $items[] = $base + ['kind' => 'stuck', 'order' => 3,
                    'label' => get_string(
                        'teacherstuck',
                        'format_spiffy',
                        ['count' => $cmcounts['stuck'], 'days' => self::STUCK_DAYS]
                    ),
                    'actionurl' => null, 'actionlabel' => ''];
            }
        }
        // Questions first, then work to grade, approvals and stuck students; course order within each.
        $position = array_flip(array_keys($items));
        uksort($items, fn($a, $b) => [$items[$a]['order'], $position[$a]] <=> [$items[$b]['order'], $position[$b]]);
        $items = array_values($items);
        $more = max(0, count($items) - self::SPOTLIGHT_LIMIT);
        $items = array_slice($items, 0, self::SPOTLIGHT_LIMIT);
        foreach ($items as $i => $item) {
            $items[$i]['hasaction'] = !empty($item['actionurl']);
            unset($items[$i]['order']);
        }

        $summary = [];
        $summarystrings = ['questions' => 'teacherquestions', 'grading' => 'teacherneedsgrading',
            'approvals' => 'teacherapprovals'];
        foreach ($summarystrings as $kind => $identifier) {
            if ($totals[$kind] > 0) {
                $summary[] = ['kind' => $kind, 'label' => get_string($identifier, 'format_spiffy', $totals[$kind])];
            }
        }
        if ($totals['stuck'] > 0) {
            $summary[] = ['kind' => 'stuck', 'label' => get_string(
                'teacherstuck',
                'format_spiffy',
                ['count' => $totals['stuck'], 'days' => self::STUCK_DAYS]
            )];
        }

        return [
            'courseid' => (int)$course->id,
            'isswitched' => false,
            'hasitems' => !empty($items),
            'items' => $items,
            'hasmore' => $more > 0,
            'morelabel' => get_string('teachermore', 'format_spiffy', $more),
            'hassummary' => !empty($summary),
            'summary' => $summary,
            'preview' => self::preview_link($course, $courseurl),
        ];
    }

    /**
     * Whether the current user gets the teacher side in a course: they can manage activities or
     * see everyone's grades (editing and non-editing teachers).
     *
     * @param context_course $context
     * @return bool
     */
    public static function is_teacher(context_course $context): bool {
        return has_any_capability(['moodle/course:manageactivities', 'moodle/grade:viewall'], $context);
    }

    /**
     * Forget the counts loaded for this request, e.g. after questions were marked as seen.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$cache = [];
    }

    /**
     * The counts of one activity before anything is known.
     *
     * @return array
     */
    protected static function empty_counts(): array {
        return ['grading' => 0, 'approvals' => 0, 'questions' => 0, 'askers' => [], 'notfinished' => null, 'stuck' => 0];
    }

    /**
     * The counts of every activity of a course for the current teacher, loaded once per request.
     *
     * @param stdClass $course
     * @return array[] course module id => ['grading', 'approvals', 'questions', 'askers' => [userid => name],
     *     'notfinished' => int|null (null when completion is not tracked or nobody is tracked), 'stuck']
     */
    public static function load(stdClass $course): array {
        global $USER;
        $key = $course->id . ':' . $USER->id;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $modinfo = get_fast_modinfo($course);
        $counts = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->deletioninprogress && $cm->uservisible) {
                $counts[(int)$cm->id] = self::empty_counts();
            }
        }
        if ($counts) {
            self::load_grading($course, $modinfo, $counts);
            self::load_approvals($course, $modinfo, $counts);
            self::load_questions($course, $counts);
            self::load_completion($course, $modinfo, $counts);
        }
        self::$cache[$key] = $counts;
        return $counts;
    }

    /**
     * Assignment submissions and quiz attempts that need grading.
     *
     * @param stdClass $course
     * @param \course_modinfo $modinfo
     * @param array[] $counts updated in place
     * @return void
     */
    protected static function load_grading(stdClass $course, \course_modinfo $modinfo, array &$counts): void {
        global $CFG, $DB;
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (!isset($counts[(int)$cm->id]) || !has_capability('mod/assign:grade', $cm->context)) {
                continue;
            }
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new \assign($cm->context, $cm, $course);
            $group = (int)groups_get_activity_group($cm, false);
            $counts[(int)$cm->id]['grading'] = $assign->get_instance()->teamsubmission ?
                $assign->count_submissions_need_grading_with_groups($group ? [$group] : []) :
                $assign->count_submissions_need_grading($group);
        }

        $quizzes = array_filter(
            $modinfo->get_instances_of('quiz'),
            fn($cm) => isset($counts[(int)$cm->id]) && has_capability('mod/quiz:grade', $cm->context)
        );
        if ($quizzes) {
            // A finished attempt has no total mark until every question that needs a person is graded.
            $rows = $DB->get_records_sql_menu(
                "SELECT qa.quiz, COUNT(1)
                                                 FROM {quiz_attempts} qa
                                                 JOIN {quiz} q ON q.id = qa.quiz
                                                WHERE q.course = :courseid AND qa.state = :finished
                                                      AND qa.sumgrades IS NULL AND qa.preview = 0
                                             GROUP BY qa.quiz",
                ['courseid' => $course->id, 'finished' => 'finished']
            );
            foreach ($quizzes as $instance => $cm) {
                $counts[(int)$cm->id]['grading'] = (int)($rows[$instance] ?? 0);
                $groups = self::restricted_groups($cm);
                if ($groups !== null) {
                    [$membersql, $memberparams] = self::member_sql('qa.userid', $groups);
                    $counts[(int)$cm->id]['grading'] = $DB->count_records_sql(
                        "SELECT COUNT(1)
                           FROM {quiz_attempts} qa
                          WHERE qa.quiz = :quiz AND qa.state = :finished AND qa.sumgrades IS NULL AND qa.preview = 0
                                AND $membersql",
                        ['quiz' => $instance, 'finished' => 'finished'] + $memberparams
                    );
                }
            }
        }
    }

    /**
     * Glossary and database entries waiting for approval.
     *
     * @param stdClass $course
     * @param \course_modinfo $modinfo
     * @param array[] $counts updated in place
     * @return void
     */
    protected static function load_approvals(stdClass $course, \course_modinfo $modinfo, array &$counts): void {
        global $DB;
        $queries = [
            'glossary' => ['mod/glossary:approve', "SELECT e.glossaryid, COUNT(1)
                                                      FROM {glossary_entries} e
                                                      JOIN {glossary} g ON g.id = e.glossaryid
                                                     WHERE g.course = :courseid AND e.approved = 0
                                                  GROUP BY e.glossaryid"],
            'data' => ['mod/data:approve', "SELECT r.dataid, COUNT(1)
                                              FROM {data_records} r
                                              JOIN {data} d ON d.id = r.dataid
                                             WHERE d.course = :courseid AND d.approval = 1 AND r.approved = 0
                                          GROUP BY r.dataid"],
        ];
        foreach ($queries as $modname => [$capability, $sql]) {
            $cms = array_filter(
                $modinfo->get_instances_of($modname),
                fn($cm) => isset($counts[(int)$cm->id]) && has_capability($capability, $cm->context)
            );
            if (!$cms) {
                continue;
            }
            $rows = $DB->get_records_sql_menu($sql, ['courseid' => $course->id]);
            foreach ($cms as $instance => $cm) {
                $counts[(int)$cm->id]['approvals'] = (int)($rows[$instance] ?? 0);
                // Glossaries have no groups.
                $groups = $modname === 'data' ? self::restricted_groups($cm) : null;
                if ($groups !== null) {
                    [$membersql, $memberparams] = self::member_sql('r.userid', $groups);
                    $counts[(int)$cm->id]['approvals'] = $DB->count_records_sql(
                        "SELECT COUNT(1) FROM {data_records} r WHERE r.dataid = :dataid AND r.approved = 0 AND $membersql",
                        ['dataid' => $instance] + $memberparams
                    );
                }
            }
        }
    }

    /**
     * Questions students sent the current teacher from a card that they have not marked as seen.
     *
     * @param stdClass $course
     * @param array[] $counts updated in place
     * @return void
     */
    protected static function load_questions(stdClass $course, array &$counts): void {
        global $DB, $USER;
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $rows = $DB->get_records_sql(
            "SELECT q.id, q.cmid, q.userid, $fields
                                        FROM {format_spiffy_question} q
                                        JOIN {user} u ON u.id = q.userid AND u.deleted = 0
                                       WHERE q.courseid = :courseid AND q.teacherid = :teacherid AND q.timeseen = 0
                                    ORDER BY q.timecreated DESC, q.id DESC",
            ['courseid' => $course->id, 'teacherid' => $USER->id]
        );
        foreach ($rows as $row) {
            $cmid = (int)$row->cmid;
            if (!isset($counts[$cmid])) {
                continue;
            }
            $counts[$cmid]['questions']++;
            $counts[$cmid]['askers'][(int)$row->userid] = fullname($row);
        }
    }

    /**
     * How many tracked students have not finished each activity, and how many are stuck on it.
     *
     * @param stdClass $course
     * @param \course_modinfo $modinfo
     * @param array[] $counts updated in place
     * @return void
     */
    protected static function load_completion(stdClass $course, \course_modinfo $modinfo, array &$counts): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            return;
        }
        $tracked = [];
        foreach ($counts as $cmid => $unused) {
            $cm = $modinfo->get_cm($cmid);
            if ($cm->visible && $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE) {
                $tracked[$cmid] = $cm;
            }
        }
        $context = context_course::instance($course->id);
        $groupids = self::group_filter($course, $context);
        if (!$tracked || $groupids === []) {
            return;
        }
        [$esql, $eparams] = get_enrolled_sql($context, 'moodle/course:isincompletionreports', $groupids ?? 0, true);
        $total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM ($esql) t", $eparams);
        if (!$total) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($tracked), SQL_PARAMS_NAMED, 'cm');
        $done = $DB->get_records_sql_menu(
            "SELECT cmc.coursemoduleid, COUNT(1)
                                             FROM {course_modules_completion} cmc
                                             JOIN ($esql) e ON e.id = cmc.userid
                                            WHERE cmc.coursemoduleid $insql AND cmc.completionstate IN (:c1, :c2)
                                         GROUP BY cmc.coursemoduleid",
            $inparams + $eparams + ['c1' => COMPLETION_COMPLETE, 'c2' => COMPLETION_COMPLETE_PASS]
        );
        foreach ($tracked as $cmid => $cm) {
            $counts[$cmid]['notfinished'] = max(0, $total - (int)($done[$cmid] ?? 0));
        }

        $cutoff = time() - self::STUCK_DAYS * DAYSECS;
        foreach (self::get_prerequisites($course, $tracked) as $cmid => $required) {
            if (!$required || !$counts[$cmid]['notfinished']) {
                continue;
            }
            [$rsql, $rparams] = $DB->get_in_or_equal($required, SQL_PARAMS_NAMED, 'req');
            $counts[$cmid]['stuck'] = (int)$DB->count_records_sql("SELECT COUNT(1) FROM (
                    SELECT p.userid
                      FROM {course_modules_completion} p
                      JOIN ($esql) e ON e.id = p.userid
                     WHERE p.coursemoduleid $rsql AND p.completionstate IN (:c1, :c2)
                           AND NOT EXISTS (
                               SELECT 1
                                 FROM {course_modules_completion} s
                                WHERE s.coursemoduleid = :stepid AND s.userid = p.userid
                                      AND s.completionstate IN (:c3, :c4)
                           )
                  GROUP BY p.userid
                    HAVING COUNT(DISTINCT p.coursemoduleid) = :nrequired AND MAX(p.timemodified) < :cutoff
                ) stuck", $rparams + $eparams + [
                    'c1' => COMPLETION_COMPLETE, 'c2' => COMPLETION_COMPLETE_PASS,
                    'c3' => COMPLETION_COMPLETE, 'c4' => COMPLETION_COMPLETE_PASS,
                    'stepid' => $cmid, 'nrequired' => count($required), 'cutoff' => $cutoff,
                ]);
        }
    }

    /**
     * For each tracked activity, the tracked activities its "activity completion" restrictions
     * require to be finished first (as the card numbering reads them).
     *
     * @param stdClass $course
     * @param cm_info[] $tracked by course module id
     * @return int[][] course module id => required course module ids
     */
    protected static function get_prerequisites(stdClass $course, array $tracked): array {
        global $CFG;
        $result = [];
        if (empty($CFG->enableavailability)) {
            return $result;
        }
        foreach ($tracked as $cmid => $cm) {
            if (empty($cm->availability)) {
                continue;
            }
            $required = [];
            try {
                $tree = (new \core_availability\info_module($cm))->get_availability_tree();
                foreach ($tree->get_all_children(\availability_completion\condition::class) as $condition) {
                    $requiredcmid = (int)$condition->get_cmid($course, $cmid, null);
                    $expected = $condition->save()->e ?? COMPLETION_COMPLETE;
                    if (
                        $requiredcmid && $requiredcmid !== $cmid && $expected != COMPLETION_INCOMPLETE
                            && isset($tracked[$requiredcmid])
                    ) {
                        $required[] = $requiredcmid;
                    }
                }
            } catch (\coding_exception $e) {
                continue;
            }
            $result[$cmid] = array_values(array_unique($required));
        }
        return $result;
    }

    /**
     * The groups the current user is limited to in an activity with separate groups.
     *
     * @param cm_info $cm
     * @return int[]|null null for everyone; an array of group ids (possibly empty) otherwise
     */
    public static function restricted_groups(cm_info $cm): ?array {
        global $USER;
        if (
            (int)groups_get_activity_groupmode($cm) !== SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $cm->context)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)));
    }

    /**
     * SQL limiting a user id column to members of some groups.
     *
     * @param string $column
     * @param int[] $groups
     * @return array [sql, named params]
     */
    public static function member_sql(string $column, array $groups): array {
        global $DB;
        if (!$groups) {
            return ['1 = 0', []];
        }
        [$insql, $params] = $DB->get_in_or_equal($groups, SQL_PARAMS_NAMED, 'spiffygroup');
        return ["EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = $column AND gm.groupid $insql)", $params];
    }

    /**
     * The groups whose students the current user may see, for the counts and the messages.
     *
     * @param stdClass $course
     * @param context_course $context
     * @return int[]|null null for everyone; an array of group ids (possibly empty) otherwise
     */
    protected static function group_filter(stdClass $course, context_course $context): ?array {
        global $USER;
        if (
            (int)groups_get_course_groupmode($course) !== SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $context)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($course->id, $USER->id)));
    }

    /**
     * Where the teacher grades or approves an activity's work, if they may.
     *
     * @param cm_info $cm
     * @return moodle_url|null
     */
    protected static function grading_url(cm_info $cm): ?moodle_url {
        $context = $cm->context;
        return match (true) {
            $cm->modname === 'assign' && has_capability('mod/assign:grade', $context) =>
                new moodle_url('/mod/assign/view.php', ['id' => $cm->id, 'action' => 'grader']),
            $cm->modname === 'quiz' && has_capability('mod/quiz:grade', $context) =>
                new moodle_url('/mod/quiz/report.php', ['id' => $cm->id, 'mode' => 'grading']),
            $cm->modname === 'glossary' && has_capability('mod/glossary:approve', $context) =>
                new moodle_url('/mod/glossary/view.php', ['id' => $cm->id, 'mode' => 'approval']),
            $cm->modname === 'data' && has_capability('mod/data:approve', $context) =>
                new moodle_url('/mod/data/view.php', ['id' => $cm->id]),
            default => null,
        };
    }

    /**
     * The quick actions of a card: grade (or approve), and extend the deadline.
     *
     * @param cm_info $cm
     * @param array $counts the activity's counts
     * @return array[] each ['url', 'label', 'icon']
     */
    protected static function quick_actions(cm_info $cm, array $counts): array {
        $actions = [];
        $gradingurl = self::grading_url($cm);
        if ($gradingurl && ($cm->modname === 'assign' || $counts['grading'] > 0)) {
            $actions[] = ['url' => $gradingurl->out(false), 'label' => get_string('teacherquickgrade', 'format_spiffy'),
                'icon' => 'i/grades'];
        } else if ($gradingurl && $counts['approvals'] > 0) {
            $actions[] = ['url' => $gradingurl->out(false), 'label' => get_string('teacherapprove', 'format_spiffy'),
                'icon' => 'i/checked'];
        }

        // Activities with a deadline that user overrides can move.
        $deadlines = [
            'assign' => ['duedate', 'cutoffdate'],
            'quiz' => ['timeclose'],
            'lesson' => ['deadline'],
        ];
        if (isset($deadlines[$cm->modname]) && has_capability("mod/{$cm->modname}:manageoverrides", $cm->context)) {
            $customdata = (array)$cm->customdata;
            $hasdeadline = false;
            foreach ($deadlines[$cm->modname] as $field) {
                $hasdeadline = $hasdeadline || !empty($customdata[$field]);
            }
            if ($hasdeadline) {
                $actions[] = [
                    'url' => (new moodle_url("/mod/{$cm->modname}/overrides.php", ['cmid' => $cm->id, 'mode' => 'user']))
                        ->out(false),
                    'label' => get_string('teacherextend', 'format_spiffy'),
                    'icon' => 'i/calendar',
                ];
            }
        }
        return $actions;
    }

    /**
     * The pencil menu: everyday edit actions that work without editing mode. The links are the
     * non-JavaScript ones core uses (course/modedit.php and course/format/update.php, which shows
     * its own confirmation before deleting), each behind the capability core checks for it.
     *
     * @param cm_info $cm
     * @return array ['id', 'label', 'groups' => [['items' => [['url', 'label', 'icon', 'danger']]]]], or []
     */
    protected static function pencil_menu(cm_info $cm): array {
        global $CFG;
        $course = $cm->get_course();
        $coursecontext = context_course::instance($course->id);
        $context = $cm->context;
        $canmanage = has_capability('moodle/course:manageactivities', $context);
        // What course/format/update.php requires for every action.
        $canupdate = has_all_capabilities(['moodle/course:update', 'moodle/course:sectionvisibility',
            'moodle/course:activityvisibility'], $coursecontext);
        $format = course_get_format($course);
        $returnurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $update = fn(string $action) => $format->get_update_url(action: $action, ids: [(int)$cm->id], returnurl: $returnurl)
            ->out(false);

        $settings = [];
        if ($canmanage) {
            $modedit = new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 0]);
            $settings[] = ['url' => $modedit->out(false), 'label' => get_string('editsettings'), 'icon' => 'i/settings'];
            if (!empty($CFG->enableavailability)) {
                $settings[] = ['url' => $modedit->out(false) . '#id_availabilityconditionsheader',
                    'label' => get_string('restrictaccess', 'core_availability'), 'icon' => 't/locked'];
            }
            $settings[] = ['url' => $modedit->out(false) . '#id_format_spiffy_header',
                'label' => get_string('teacherspiffysettings', 'format_spiffy'), 'icon' => 'i/customfield'];
        }

        $changes = [];
        if ($canupdate && has_capability('moodle/course:activityvisibility', $context)) {
            if ($cm->visible) {
                $changes[] = ['url' => $update('cm_hide'), 'label' => get_string('modhide'), 'icon' => 't/hide'];
            } else if ($cm->get_section_info()->visible || $cm->has_view()) {
                $changes[] = ['url' => $update('cm_show'), 'label' => get_string('modshow'), 'icon' => 't/show'];
            }
        }
        if (
            $canupdate && $canmanage
                && has_all_capabilities(
                    ['moodle/backup:backuptargetimport', 'moodle/restore:restoretargetimport'],
                    $coursecontext
                )
                && plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)
                && course_allowed_module($course, $cm->modname)
        ) {
            $changes[] = ['url' => $update('cm_duplicate'), 'label' => get_string('duplicate'), 'icon' => 't/copy'];
        }
        if (has_capability('moodle/role:assign', $context)) {
            $changes[] = ['url' => (new moodle_url('/admin/roles/assign.php', ['contextid' => $context->id]))->out(false),
                'label' => get_string('assignroles', 'role'), 'icon' => 't/assignroles'];
        }

        $danger = [];
        if ($canupdate && $canmanage) {
            $danger[] = ['url' => $update('cm_delete'), 'label' => get_string('delete'), 'icon' => 't/delete', 'danger' => true];
        }

        $groups = [];
        foreach ([$settings, $changes, $danger] as $items) {
            if ($items) {
                $groups[] = ['items' => array_map(fn($item) => $item + ['danger' => false], $items),
                    'divider' => !empty($groups)];
            }
        }
        if (!$groups) {
            return [];
        }
        return [
            'id' => 'spiffy-pencil-' . $cm->id,
            'label' => get_string('teacheredit', 'format_spiffy', $cm->get_formatted_name(['escape' => false])),
            'groups' => $groups,
        ];
    }

    /**
     * The "Preview as student" link: core's switch role to the student role and back to this page.
     *
     * @param stdClass $course
     * @param moodle_url $courseurl where to come back to
     * @return array|null ['url', 'rolename'], or null when the user cannot switch to a student role
     */
    protected static function preview_link(stdClass $course, moodle_url $courseurl): ?array {
        $context = context_course::instance($course->id);
        if (!has_capability('moodle/role:switchroles', $context)) {
            return null;
        }
        $switchable = get_switchable_roles($context);
        foreach (get_archetype_roles('student') as $role) {
            if (!isset($switchable[$role->id])) {
                continue;
            }
            return [
                'url' => (new moodle_url('/course/switchrole.php', [
                    'id' => $course->id,
                    'switchrole' => $role->id,
                    'sesskey' => sesskey(),
                    'returnurl' => $courseurl->out_as_local_url(false),
                ]))->out(false),
                'rolename' => role_get_name($role, $context),
            ];
        }
        return null;
    }

    /**
     * The students who can open an activity and have not finished it: tracked students with an
     * active enrolment, in the current user's groups when the course uses separate groups, who
     * meet the activity's and its section's access restrictions. Not the current user.
     *
     * @param cm_info $cm
     * @return stdClass[] user records by id
     */
    public static function get_unfinished_recipients(cm_info $cm): array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/completionlib.php');
        $course = $cm->get_course();
        $completion = new completion_info($course);
        $section = $cm->get_section_info();
        if (!$completion->is_enabled($cm) || !$cm->visible || !$section->visible) {
            return [];
        }
        $context = context_course::instance($course->id);
        $groupids = self::group_filter($course, $context);
        if ($groupids === []) {
            return [];
        }
        $users = get_enrolled_users(
            $context,
            'moodle/course:isincompletionreports',
            $groupids ?? 0,
            'u.*',
            'u.id',
            0,
            0,
            true
        );
        unset($users[$USER->id]);
        $users = array_filter($users, fn($user) => empty($user->suspended) && empty($user->deleted));
        if (!$users) {
            return [];
        }
        $users = (new \core_availability\info_section($section))->filter_user_list($users);
        $users = (new \core_availability\info_module($cm))->filter_user_list($users);
        if (!$users) {
            return [];
        }
        $done = $DB->get_fieldset_sql(
            "SELECT userid
                                         FROM {course_modules_completion}
                                        WHERE coursemoduleid = :cmid AND completionstate IN (:c1, :c2)",
            ['cmid' => $cm->id, 'c1' => COMPLETION_COMPLETE, 'c2' => COMPLETION_COMPLETE_PASS]
        );
        return array_diff_key($users, array_flip($done));
    }

    /**
     * Message the students who have not finished an activity, as a personal message from the
     * current user to each of them, with the activity's name and link at the top.
     *
     * The caller checks moodle/course:bulkmessaging and that messaging is enabled.
     *
     * @param cm_info $cm
     * @param string $message plain text
     * @param bool $dryrun only count the recipients, for the confirmation step
     * @return array ['count' => int, 'message' => string to show]
     */
    public static function message_unfinished(cm_info $cm, string $message, bool $dryrun = false): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/message/lib.php');
        $message = trim(clean_param($message, PARAM_TEXT));
        if (\core_text::strlen($message) > self::MESSAGE_MAXLENGTH) {
            throw new \moodle_exception('teachermessagetoolong', 'format_spiffy', '', self::MESSAGE_MAXLENGTH);
        }
        if (!$dryrun && $message === '') {
            throw new \moodle_exception('teachermessageempty', 'format_spiffy');
        }
        $recipients = array_filter(
            self::get_unfinished_recipients($cm),
            fn($user) => \core_message\api::can_send_message((int)$user->id, (int)$USER->id)
        );
        if ($dryrun) {
            return [
                'count' => count($recipients),
                'message' => $recipients ?
                    get_string('teachermessageconfirm', 'format_spiffy', count($recipients)) :
                    get_string('teachermessagenobody', 'format_spiffy'),
            ];
        }

        // A retry after a timeout must not message everybody twice.
        $lock = \cache::make('format_spiffy', 'messagelock');
        $lockkey = $USER->id . '_' . $cm->id;
        if ((int)$lock->get($lockkey) > time() - self::MESSAGE_REPEAT_SECONDS) {
            throw new \moodle_exception('teachermessagetoorecent', 'format_spiffy');
        }
        $lock->set($lockkey, time());

        $url = $cm->url ? $cm->url->out(false) : (new moodle_url('/course/view.php', ['id' => $cm->course]))->out(false);
        $sent = 0;
        foreach ($recipients as $user) {
            $about = get_string_manager()->get_string(
                'teachermessageabout',
                'format_spiffy',
                (object)['name' => $cm->get_formatted_name(['escape' => false]), 'url' => $url],
                $user->lang ?: null
            );
            if (message_post_message($USER, $user, $about . "\n\n" . $message, FORMAT_MOODLE)) {
                $sent++;
            }
        }
        return ['count' => $sent, 'message' => get_string('teachermessagesent', 'format_spiffy', $sent)];
    }

    /**
     * Mark the questions students sent the current user about an activity as seen.
     *
     * @param cm_info $cm
     * @return int how many were marked
     */
    public static function mark_questions_seen(cm_info $cm): int {
        global $DB, $USER;
        $conditions = ['cmid' => $cm->id, 'teacherid' => $USER->id, 'timeseen' => 0];
        $count = $DB->count_records('format_spiffy_question', $conditions);
        if ($count) {
            $DB->set_field('format_spiffy_question', 'timeseen', time(), $conditions);
        }
        self::reset_caches();
        return $count;
    }
}
