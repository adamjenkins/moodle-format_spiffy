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
use core\context\course as context_course;
use format_spiffy\local\inline\handler;
use format_spiffy\local\inline\registry;
use renderer_base;
use stdClass;

/**
 * Student tools on every card: "Ask my teacher", "My notes" and the confidence check.
 *
 * Notes are private plain text, one per activity and student. Questions go to a teacher through
 * Moodle messaging as a personal message; only the fact that a question was sent is logged here
 * (for the teacher spotlight), never its text. The confidence check is one of three values, given
 * once the activity is done; teachers see the totals per card.
 *
 * The card export runs for every card on every page view, so the current user's notes, confidence
 * checks and teachers are loaded once per course and kept for the request.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tools {
    /** @var int Longest note, in characters. */
    public const NOTE_MAXLENGTH = 10000;

    /** @var int How many questions a student can send in an hour, across the site. */
    public const QUESTIONS_PER_HOUR = 30;

    /** @var int Longest question, in characters. */
    public const QUESTION_MAXLENGTH = 4000;

    /** @var int Confidence: not confident. */
    public const CONFIDENCE_LOW = 1;

    /** @var int Confidence: unsure. */
    public const CONFIDENCE_UNSURE = 2;

    /** @var int Confidence: confident. */
    public const CONFIDENCE_HIGH = 3;

    /** @var string[] The face shown for each confidence value. */
    protected const CONFIDENCE_FACES = [
        self::CONFIDENCE_LOW => "\u{1F61F}",
        self::CONFIDENCE_UNSURE => "\u{1F610}",
        self::CONFIDENCE_HIGH => "\u{1F600}",
    ];

    /** @var array Per "courseid:userid": ['canuse' => bool, 'notes' => [cmid => note], 'confidence' => [cmid => int]]. */
    protected static array $cache = [];

    /** @var array Per "courseid:userid": the teachers the user may ask, keyed by user id. */
    protected static array $teachercache = [];

    /**
     * Template context of the tools on one card, rendered by format_spiffy/local/card_tools.
     *
     * @param cm_info $cm the activity
     * @param string $state the card state (handler::STATE_*)
     * @param renderer_base $output
     * @return array context; empty for no tools
     */
    public static function export_card(cm_info $cm, string $state, renderer_base $output): array {
        if ($state === handler::STATE_LOCKED || !$cm->uservisible || !self::can_use($cm)) {
            return [];
        }
        // The course settings "My notes", "Ask my teacher" and "Confidence check".
        $format = course_get_format($cm->get_course());
        $cannote = $format->feature_enabled('notes');
        $canaskfeature = $format->feature_enabled('askteacher');
        $canconfidence = $format->feature_enabled('confidence');
        if (!$cannote && !$canaskfeature && !$canconfidence) {
            return [];
        }
        $data = self::load((int)$cm->course);
        $cmid = (int)$cm->id;
        $name = $cm->get_formatted_name();
        $note = $data['notes'][$cmid] ?? '';

        $teachers = [];
        foreach ($canaskfeature ? self::get_teachers($cm) : [] as $teacher) {
            $teachers[] = [
                'id' => (int)$teacher->id,
                'fullname' => $teacher->spiffyfullname,
                'pictureurl' => $teacher->spiffypictureurl,
                'selected' => !$teachers,
            ];
        }

        $confidence = [];
        $current = (int)($data['confidence'][$cmid] ?? 0);
        if ($state === handler::STATE_DONE && $canconfidence) {
            foreach (self::CONFIDENCE_FACES as $value => $face) {
                $confidence[] = [
                    'value' => $value,
                    'face' => $face,
                    'label' => get_string('confidence' . $value, 'format_spiffy'),
                    'pressed' => $current === $value,
                ];
            }
        }

        if (!$cannote && !$teachers && !$confidence) {
            return [];
        }
        return [
            'cmid' => $cmid,
            'activityname' => $name,
            'spiffyplainname' => $cm->get_formatted_name(['escape' => false]),
            'cannote' => $cannote,
            'note' => $note,
            'hasnote' => $note !== '',
            'notemaxlength' => self::NOTE_MAXLENGTH,
            'canask' => !empty($teachers),
            'teachers' => $teachers,
            'teacher' => $teachers[0] ?? null,
            'multipleteachers' => count($teachers) > 1,
            'questionmaxlength' => self::QUESTION_MAXLENGTH,
            'showconfidence' => !empty($confidence),
            'confidence' => $confidence,
            'hasconfidence' => $current > 0,
        ];
    }

    /**
     * Whether the current user gets the student tools in this course: enrolled (active), not a
     * guest, and not someone who manages the activity (they get the teacher parts instead).
     *
     * @param cm_info $cm
     * @return bool
     */
    public static function can_use(cm_info $cm): bool {
        if (!isloggedin() || isguestuser()) {
            return false;
        }
        $data = self::load((int)$cm->course);
        return $data['canuse'] && !has_capability('moodle/course:manageactivities', $cm->context);
    }

    /**
     * For the tools' web services: the activity of a card the current user may use the tools on.
     * Validates the activity context (login, enrolment, visibility and access restrictions), so it
     * must not be called while a page renders.
     *
     * @param int $cmid
     * @param string $feature the tool's course setting (format_spiffy::FEATURES): notes, askteacher or confidence
     * @return cm_info
     * @throws \moodle_exception when the tools are not available on this card
     */
    public static function validate_card(int $cmid, string $feature): cm_info {
        [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        \core_external\external_api::validate_context(\core\context\module::instance($cm->id));
        if ($course->format !== 'spiffy') {
            throw new \moodle_exception('invalidcourseformat', 'format_spiffy');
        }
        $cm = get_fast_modinfo($course)->get_cm($cm->id);
        if (
            !$cm->uservisible || !$cm->available || !self::can_use($cm)
            || !course_get_format($course)->feature_enabled($feature)
        ) {
            throw new \moodle_exception('toolsnotavailable', 'format_spiffy');
        }
        return $cm;
    }

    /**
     * The current user's notes, confidence checks and enrolment for a course, loaded once.
     *
     * @param int $courseid
     * @return array ['canuse' => bool, 'notes' => [cmid => string], 'confidence' => [cmid => int]]
     */
    protected static function load(int $courseid): array {
        global $DB, $USER;
        $key = $courseid . ':' . $USER->id;
        if (!isset(self::$cache[$key])) {
            $canuse = !empty($USER->id) && !isguestuser()
                && is_enrolled(context_course::instance($courseid), $USER->id, '', true);
            $notes = [];
            $confidence = [];
            if ($canuse) {
                $notes = $DB->get_records_menu(
                    'format_spiffy_note',
                    ['courseid' => $courseid, 'userid' => $USER->id],
                    '',
                    'cmid, note'
                );
                $confidence = $DB->get_records_menu(
                    'format_spiffy_confidence',
                    ['courseid' => $courseid, 'userid' => $USER->id],
                    '',
                    'cmid, confidence'
                );
            }
            self::$cache[$key] = [
                'canuse' => $canuse,
                'notes' => array_map('strval', $notes),
                'confidence' => array_map('intval', $confidence),
            ];
        }
        return self::$cache[$key];
    }

    /**
     * Forget what was loaded for this request, e.g. after the user or the data changed in a test.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$cache = [];
        self::$teachercache = [];
    }

    /**
     * The teachers the current user can ask about an activity: the course contacts the user may
     * send a personal message to and, in a separate-groups course, who share a group with the
     * user (or see all groups and belong to none).
     *
     * @param cm_info $cm
     * @return stdClass[] user records with picture fields, spiffyfullname and spiffypictureurl, by id
     */
    public static function get_teachers(cm_info $cm): array {
        global $CFG, $DB, $PAGE, $USER;
        $courseid = (int)$cm->course;
        $key = $courseid . ':' . $USER->id;
        if (isset(self::$teachercache[$key])) {
            return self::$teachercache[$key];
        }
        $teachers = [];
        if (!empty($CFG->messaging) && isloggedin() && !isguestuser()) {
            $course = $cm->get_course();
            $contacts = (new \core_course_list_element($course))->get_course_contacts();
            unset($contacts[$USER->id]);
            if ($contacts) {
                [$insql, $params] = $DB->get_in_or_equal(array_keys($contacts), SQL_PARAMS_NAMED);
                $fields = \core_user\fields::for_userpic()->get_sql('u', false, '', '', false)->selects;
                $users = $DB->get_records_sql("SELECT $fields FROM {user} u
                                                WHERE u.id $insql AND u.deleted = 0 AND u.suspended = 0", $params);
                $separate = (int)groups_get_course_groupmode($course) === SEPARATEGROUPS;
                $mygroups = $separate ? array_keys(groups_get_all_groups($courseid, $USER->id)) : [];
                $context = context_course::instance($courseid);
                foreach ($contacts as $id => $contact) {
                    $user = $users[$id] ?? null;
                    if (!$user || !\core_message\api::can_send_message((int)$id, (int)$USER->id)) {
                        continue;
                    }
                    if ($separate) {
                        $theirs = array_keys(groups_get_all_groups($courseid, (int)$id));
                        $shared = (bool)array_intersect($mygroups, $theirs);
                        $overseer = !$theirs && has_capability('moodle/site:accessallgroups', $context, (int)$id);
                        if (!$shared && !$overseer) {
                            continue;
                        }
                    }
                    $picture = new \user_picture($user);
                    $picture->size = 64;
                    $user->spiffyfullname = $contact['username'];
                    $user->spiffypictureurl = $picture->get_url($PAGE)->out(false);
                    $teachers[(int)$id] = $user;
                }
            }
        }
        self::$teachercache[$key] = $teachers;
        return $teachers;
    }

    /**
     * Save the current user's note on an activity; an empty note deletes it.
     *
     * @param cm_info $cm
     * @param string $note plain text
     * @return string the message to show
     */
    public static function save_note(cm_info $cm, string $note): string {
        global $DB, $USER;
        $note = trim(clean_param($note, PARAM_TEXT));
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH) {
            throw new \moodle_exception('notetoolong', 'format_spiffy', '', self::NOTE_MAXLENGTH);
        }
        $conditions = ['cmid' => $cm->id, 'userid' => $USER->id];
        $existing = $DB->get_record('format_spiffy_note', $conditions, 'id');
        $key = $cm->course . ':' . $USER->id;
        if ($note === '') {
            if ($existing) {
                $DB->delete_records('format_spiffy_note', ['id' => $existing->id]);
            }
            unset(self::$cache[$key]['notes'][(int)$cm->id]);
            return get_string('noteremoved', 'format_spiffy');
        }
        $record = (object)[
            'courseid' => $cm->course,
            'cmid' => $cm->id,
            'userid' => $USER->id,
            'note' => $note,
            'timemodified' => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('format_spiffy_note', $record);
        } else {
            $DB->insert_record('format_spiffy_note', $record);
        }
        if (isset(self::$cache[$key])) {
            self::$cache[$key]['notes'][(int)$cm->id] = $note;
        }
        return get_string('notesaved', 'format_spiffy');
    }

    /**
     * Record how confident the current user feels after finishing an activity.
     *
     * @param cm_info $cm
     * @param int $confidence one of the CONFIDENCE_ constants
     * @return string the message to show
     */
    public static function set_confidence(cm_info $cm, int $confidence): string {
        global $DB, $USER;
        if (!isset(self::CONFIDENCE_FACES[$confidence])) {
            throw new \invalid_parameter_exception('confidence must be 1, 2 or 3');
        }
        if (!self::is_done($cm)) {
            throw new \moodle_exception('confidencenotdone', 'format_spiffy');
        }
        $record = (object)[
            'courseid' => $cm->course,
            'cmid' => $cm->id,
            'userid' => $USER->id,
            'confidence' => $confidence,
            'timemodified' => time(),
        ];
        if ($existing = $DB->get_record('format_spiffy_confidence', ['cmid' => $cm->id, 'userid' => $USER->id], 'id')) {
            $record->id = $existing->id;
            $DB->update_record('format_spiffy_confidence', $record);
        } else {
            $DB->insert_record('format_spiffy_confidence', $record);
        }
        $key = $cm->course . ':' . $USER->id;
        if (isset(self::$cache[$key])) {
            self::$cache[$key]['confidence'][(int)$cm->id] = $confidence;
        }
        return get_string('confidencesaved', 'format_spiffy', get_string('confidence' . $confidence, 'format_spiffy'));
    }

    /**
     * Whether the current user has done an activity, as its card says.
     *
     * @param cm_info $cm
     * @return bool
     */
    protected static function is_done(cm_info $cm): bool {
        if (!$cm->uservisible || !$cm->available) {
            return false;
        }
        $handler = registry::get_handler($cm, cm_settings::get((int)$cm->id));
        return $handler ? $handler->get_state() === handler::STATE_DONE : handler::is_complete($cm);
    }

    /**
     * Send a teacher a question about an activity, as a personal message from the current user,
     * with the activity's name and link at the top. Only the fact that it was sent is logged.
     *
     * @param cm_info $cm
     * @param int $teacherid
     * @param string $question plain text
     * @return string the message to show, "Sent to <name>"
     */
    public static function ask_teacher(cm_info $cm, int $teacherid, string $question): string {
        global $DB, $USER;
        $question = trim(clean_param($question, PARAM_TEXT));
        if ($question === '') {
            throw new \moodle_exception('questionempty', 'format_spiffy');
        }
        if (\core_text::strlen($question) > self::QUESTION_MAXLENGTH) {
            throw new \moodle_exception('questiontoolong', 'format_spiffy', '', self::QUESTION_MAXLENGTH);
        }
        $teachers = self::get_teachers($cm);
        if (!isset($teachers[$teacherid])) {
            throw new \moodle_exception('cannotaskteacher', 'format_spiffy');
        }
        $recent = $DB->count_records_select(
            'format_spiffy_question',
            'userid = :userid AND timecreated > :since',
            ['userid' => $USER->id, 'since' => time() - HOURSECS]
        );
        if ($recent >= self::QUESTIONS_PER_HOUR) {
            throw new \moodle_exception('questiontoomany', 'format_spiffy');
        }
        $teacher = \core_user::get_user($teacherid, '*', MUST_EXIST);

        $about = get_string_manager()->get_string('questionabout', 'format_spiffy', (object)[
            'name' => $cm->get_formatted_name(),
            'url' => $cm->url ? $cm->url->out(false) :
                (new \moodle_url('/course/view.php', ['id' => $cm->course]))->out(false),
        ], $teacher->lang ?: null);
        $text = $about . "\n\n" . $question;

        $type = \core_message\api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL;
        $conversationid = \core_message\api::get_conversation_between_users([$USER->id, $teacherid]);
        if (!$conversationid) {
            $conversationid = \core_message\api::create_conversation($type, [$USER->id, $teacherid])->id;
        }
        \core_message\api::send_message_to_conversation((int)$USER->id, (int)$conversationid, $text, FORMAT_MOODLE);

        $DB->insert_record('format_spiffy_question', (object)[
            'courseid' => $cm->course,
            'cmid' => $cm->id,
            'userid' => $USER->id,
            'teacherid' => $teacherid,
            'timecreated' => time(),
            'timeseen' => 0,
        ]);
        return get_string('questionsent', 'format_spiffy', $teachers[$teacherid]->spiffyfullname);
    }

    /**
     * How confident the students felt after finishing an activity, for the teacher parts of a card.
     *
     * @param int $cmid
     * @param int[]|null $groups only students in these groups; null for everybody
     * @return array ['1' => int, '2' => int, '3' => int, 'total' => int]
     */
    public static function confidence_summary(int $cmid, ?array $groups = null): array {
        global $DB;
        $summary = ['1' => 0, '2' => 0, '3' => 0, 'total' => 0];
        [$membersql, $memberparams] = $groups === null ? ['1 = 1', []] : teacher::member_sql('c.userid', $groups);
        $rows = $DB->get_records_sql("SELECT c.confidence, COUNT(1) AS n
                                        FROM {format_spiffy_confidence} c
                                       WHERE c.cmid = :cmid AND $membersql
                                    GROUP BY c.confidence", ['cmid' => $cmid] + $memberparams);
        foreach ($rows as $row) {
            $value = (string)(int)$row->confidence;
            if (isset($summary[$value])) {
                $summary[$value] = (int)$row->n;
                $summary['total'] += (int)$row->n;
            }
        }
        return $summary;
    }
}
