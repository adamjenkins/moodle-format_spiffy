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

namespace format_spiffy\local\inline;

use renderer_base;
use stdClass;

/**
 * Lesson on a card: step through the lesson one page at a time without leaving the course page.
 *
 * Inline only for simple lessons: no password, time limit or dependency on another lesson, the
 * normal page order after a correct answer, and only content pages and single-answer multiple
 * choice, true/false, short answer and numerical question pages, whose answers jump to this
 * page, the next page, a named page or the end of the lesson. Lessons with clusters, branches
 * that end, essays, matching or random jumps open their own page.
 *
 * Writes go through mod_lesson's external functions (launch_attempt, process_page and
 * finish_attempt), as the mobile app uses them, so the lesson's own checks, grading and
 * completion apply unchanged. Where the user is comes from the lesson's own records
 * (lesson::get_last_page_seen(), as view.php uses it). The views read the lesson class
 * directly: an external function resets the page output, so it cannot be called while the
 * page renders, and the lesson page classes need $PAGE->cm, which the course page has not got.
 *
 * @package    format_spiffy
 * @copyright  2009 Sam Hemelryk
 * @copyright  2017 Juan Leyva <juan@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_lesson extends handler {
    /** @var int Page type: short answer (LESSON_PAGE_SHORTANSWER). */
    protected const QTYPE_SHORTANSWER = 1;

    /** @var int Page type: true/false (LESSON_PAGE_TRUEFALSE). */
    protected const QTYPE_TRUEFALSE = 2;

    /** @var int Page type: multiple choice (LESSON_PAGE_MULTICHOICE). */
    protected const QTYPE_MULTICHOICE = 3;

    /** @var int Page type: numerical (LESSON_PAGE_NUMERICAL). */
    protected const QTYPE_NUMERICAL = 8;

    /** @var int Page type: content page (LESSON_PAGE_BRANCHTABLE). */
    protected const QTYPE_CONTENT = 20;

    /** @var string[] The answer form of each question page type, as process_page expects its name. */
    protected const FORMS = [
        self::QTYPE_SHORTANSWER => 'lesson_display_answer_form_shortanswer',
        self::QTYPE_TRUEFALSE => 'lesson_display_answer_form_truefalse',
        self::QTYPE_MULTICHOICE => 'lesson_display_answer_form_multichoice_singleanswer',
        self::QTYPE_NUMERICAL => 'lesson_display_answer_form_numerical',
    ];

    /** @var int Jump: this page (LESSON_THISPAGE). */
    protected const JUMP_THISPAGE = 0;

    /** @var int Jump: the next page (LESSON_NEXTPAGE). */
    protected const JUMP_NEXTPAGE = -1;

    /** @var int Jump: the end of the lesson (LESSON_EOL). */
    protected const JUMP_EOL = -9;

    /** @var array[] Feedback on the answer just given, by course module id, for the re-render in the same request. */
    protected static array $feedback = [];

    /** @var \lesson|null The lesson, loaded once. */
    protected ?\lesson $lesson = null;

    /** @var stdClass[]|null The lesson pages without their contents, by id. */
    protected ?array $pages = null;

    /** @var stdClass|false|null The user's latest lesson timer: null until loaded, false when there is none. */
    protected stdClass|false|null $timer = null;

    /** @var int|null The page the user is on. */
    protected ?int $pageid = null;

    /** @var bool|null Whether the lesson is simple enough for the card. */
    protected ?bool $simple = null;

    /**
     * The lesson, with the current user's overrides applied.
     *
     * @return \lesson
     */
    protected function get_lesson(): \lesson {
        global $CFG, $DB, $USER;
        if ($this->lesson === null) {
            require_once($CFG->dirroot . '/mod/lesson/locallib.php');
            $record = $DB->get_record('lesson', ['id' => $this->cm->instance], '*', MUST_EXIST);
            $this->lesson = new \lesson($record, $this->cm, $this->cm->get_course());
            $this->lesson->update_effective_access($USER->id);
        }
        return $this->lesson;
    }

    /**
     * The lesson settings for the current user.
     *
     * @return stdClass
     */
    protected function get_settings(): stdClass {
        return $this->get_lesson()->properties();
    }

    /**
     * The lesson pages in lesson order, without their contents.
     *
     * @return stdClass[] by id
     */
    protected function get_pages(): array {
        global $DB;
        if ($this->pages === null) {
            $records = $DB->get_records(
                'lesson_pages',
                ['lessonid' => $this->cm->instance],
                '',
                'id, prevpageid, nextpageid, qtype, qoption'
            );
            $ordered = [];
            $next = 0;
            foreach ($records as $record) {
                if ((int)$record->prevpageid === 0) {
                    $next = (int)$record->id;
                    break;
                }
            }
            while ($next && isset($records[$next]) && !isset($ordered[$next])) {
                $ordered[$next] = $records[$next];
                $next = (int)$records[$next]->nextpageid;
            }
            $this->pages = $ordered;
        }
        return $this->pages;
    }

    /**
     * Whether every page and jump of the lesson works on the card.
     *
     * @return bool
     */
    protected function is_simple(): bool {
        global $DB;
        if ($this->simple === null) {
            $settings = $this->get_settings();
            $pages = $this->get_pages();
            $simple = $pages && empty($settings->usepassword) && empty($settings->timelimit)
                && empty($settings->dependency) && empty($settings->nextpagedefault);
            foreach ($pages as $page) {
                $qtype = (int)$page->qtype;
                if (
                    ($qtype !== self::QTYPE_CONTENT && !isset(self::FORMS[$qtype]))
                        || ($qtype === self::QTYPE_MULTICHOICE && !empty($page->qoption))
                ) {
                    $simple = false;
                }
            }
            if ($simple) {
                $jumps = $DB->get_fieldset_sql(
                    'SELECT DISTINCT jumpto FROM {lesson_answers} WHERE lessonid = ?',
                    [$this->cm->instance]
                );
                foreach ($jumps as $jump) {
                    $jump = (int)$jump;
                    if ($jump < 0 && $jump !== self::JUMP_NEXTPAGE && $jump !== self::JUMP_EOL) {
                        $simple = false;
                    }
                }
            }
            $this->simple = (bool)$simple;
        }
        return $this->simple;
    }

    /**
     * The user's latest lesson timer: one is started with each attempt and completed at its end.
     *
     * @return stdClass|false
     */
    protected function get_timer(): stdClass|false {
        global $USER;
        if ($this->timer === null) {
            $timers = $this->get_lesson()->get_user_timers($USER->id, 'starttime DESC, id DESC', '*', 0, 1);
            $this->timer = $timers ? reset($timers) : false;
        }
        return $this->timer;
    }

    /**
     * Whether the user has started an attempt and not finished it.
     *
     * @return bool
     */
    protected function in_progress(): bool {
        $timer = $this->get_timer();
        return $timer && empty($timer->completed);
    }

    /**
     * Whether the user has finished an attempt.
     *
     * @return bool
     */
    protected function has_finished(): bool {
        global $DB, $USER;
        $timer = $this->get_timer();
        if ($timer && !empty($timer->completed)) {
            return true;
        }
        return $timer && $DB->record_exists('lesson_grades', ['lessonid' => $this->cm->instance, 'userid' => $USER->id]);
    }

    /**
     * The page the user is on: where the lesson would continue, or its first page.
     *
     * @return int page id, 0 when the lesson has no pages
     */
    protected function get_current_pageid(): int {
        global $USER;
        if ($this->pageid === null) {
            $pages = $this->get_pages();
            $first = $pages ? (int)array_key_first($pages) : 0;
            $this->pageid = $first;
            if ($first && $this->in_progress()) {
                $lesson = $this->get_lesson();
                $seen = $lesson->get_last_page_seen($lesson->count_user_retries($USER->id));
                if ($seen !== false && (int)$seen > 0 && isset($pages[(int)$seen])) {
                    $this->pageid = (int)$seen;
                }
            }
        }
        return $this->pageid;
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!has_capability('mod/lesson:view', $this->cm->context) || has_capability('mod/lesson:manage', $this->cm->context)) {
            return false;
        }
        if (!$this->is_simple()) {
            return false;
        }
        return !$this->get_lesson()->get_time_restriction_status();
    }

    #[\Override]
    public function is_done(): bool {
        if ($this->in_progress()) {
            return false;
        }
        return parent::is_done() || $this->has_finished();
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        global $USER;
        $pageid = $this->get_current_pageid();
        if (!$pageid) {
            return null;
        }
        $context = $this->cm->context;
        $page = $this->get_lesson()->load_page($pageid);
        $properties = $page->properties();
        $qtype = (int)$properties->qtype;

        // Mirrors get_contents in lesson_page, which needs $PAGE->cm.
        $contents = '';
        if (!empty($properties->contents)) {
            $contents = file_rewrite_pluginfile_urls(
                $properties->contents,
                'pluginfile.php',
                $context->id,
                'mod_lesson',
                'page_contents',
                $properties->id
            );
            $contents = format_text(
                $contents,
                $properties->contentsformat ?? FORMAT_HTML,
                ['context' => $context, 'noclean' => true, 'overflowdiv' => true]
            );
        }

        $options = [];
        $buttons = [];
        foreach ($page->get_answers() as $answer) {
            $answer = $answer->properties();
            if ($qtype === self::QTYPE_CONTENT) {
                // Mirrors display in lesson_page_type_branchtable.
                if ((string)$answer->answer === '') {
                    continue;
                }
                $buttons[] = [
                    'jumpto' => (int)$answer->jumpto,
                    'label' => trim(strip_tags(format_text(
                        $answer->answer,
                        FORMAT_MOODLE,
                        ['context' => $context, 'para' => false, 'noclean' => true]
                    ))),
                ];
            } else if ($qtype === self::QTYPE_TRUEFALSE || $qtype === self::QTYPE_MULTICHOICE) {
                if ((string)$answer->answer === '') {
                    continue;
                }
                $text = file_rewrite_pluginfile_urls(
                    $answer->answer,
                    'pluginfile.php',
                    $context->id,
                    'mod_lesson',
                    'page_answers',
                    $answer->id
                );
                $options[] = [
                    'id' => (int)$answer->id,
                    'text' => format_text(
                        $text,
                        $answer->answerformat,
                        ['context' => $context, 'para' => false, 'noclean' => true]
                    ),
                    'order' => sha1($USER->id . '/' . $answer->id),
                ];
            }
        }
        if ($qtype === self::QTYPE_CONTENT && !$buttons) {
            $buttons[] = ['jumpto' => self::JUMP_NEXTPAGE, 'label' => get_string('continue', 'lesson')];
        }
        // The lesson shuffles answers on each view; a card re-renders often, so keep one order per user.
        usort($options, fn($a, $b) => strcmp($a['order'], $b['order']));

        $pages = $this->get_pages();
        return ['template' => 'format_spiffy/inline/lesson', 'context' => [
            'pageid' => $pageid,
            'title' => format_string($properties->title, true, ['context' => $context]),
            'contents' => $contents,
            'position' => get_string('lessonposition', 'format_spiffy', [
                'page' => array_search($pageid, array_keys($pages)) + 1,
                'total' => count($pages),
            ]),
            'iscontent' => $qtype === self::QTYPE_CONTENT,
            'buttons' => $buttons,
            'ischoice' => $qtype === self::QTYPE_TRUEFALSE || $qtype === self::QTYPE_MULTICHOICE,
            'options' => array_map(fn($option) => array_diff_key($option, ['order' => 0]), $options),
            'istext' => $qtype === self::QTYPE_SHORTANSWER,
            'isnumber' => $qtype === self::QTYPE_NUMERICAL,
        ] + $this->export_feedback()];
    }

    /**
     * The feedback on the answer the user just gave, if this request processed one.
     *
     * @return array template context
     */
    protected function export_feedback(): array {
        $feedback = self::$feedback[$this->cm->id] ?? null;
        if (!$feedback) {
            return ['hasfeedback' => false];
        }
        $context = $this->cm->context;
        $response = '';
        if ($feedback['noanswer']) {
            $response = s(get_string('noanswer', 'lesson'));
        } else if ($feedback['response'] !== '') {
            $response = format_text($feedback['response'], FORMAT_HTML, ['context' => $context, 'noclean' => true]);
        } else if ($feedback['nodefault']) {
            return ['hasfeedback' => false];
        }
        $remaining = $feedback['attemptsremaining'];
        return [
            'hasfeedback' => true,
            'correct' => $feedback['correct'],
            'verdict' => get_string($feedback['correct'] ? 'lessoncorrect' : 'lessonincorrect', 'format_spiffy'),
            'response' => $response,
            'attemptsremaining' => $remaining ? get_string('attemptsremaining', 'lesson', $remaining) : '',
        ];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        $settings = $this->get_settings();
        $finished = $this->has_finished();

        // The grade as the gradebook shows it to the user.
        $grade = '';
        if ($finished && (int)$settings->grade !== 0) {
            $gradinginfo = grade_get_grades($this->cm->course, 'mod', 'lesson', $this->cm->instance, $USER->id);
            $item = $gradinginfo->items[0] ?? null;
            $usergrade = $item->grades[$USER->id] ?? null;
            if ($usergrade && $usergrade->grade !== null && empty($usergrade->hidden) && empty($item->hidden)) {
                $grade = get_string('badgegrade', 'format_spiffy', $usergrade->str_long_grade);
            }
        }

        // Review, as the lesson's end page offers it: only straight after finishing, while the lesson remembers it.
        $reviewurl = '';
        if ($finished && !empty($settings->modattempts) && isset($USER->modattempts[$settings->id])) {
            $params = ['id' => $this->cm->id];
            $retries = $this->get_lesson()->count_user_retries($USER->id);
            $attempts = $retries ? $this->get_lesson()->get_attempts($retries - 1) : [];
            if ($attempts) {
                $params['pageid'] = reset($attempts)->pageid;
            }
            $reviewurl = (new \moodle_url('/mod/lesson/view.php', $params))->out(false);
        }

        $canstart = $this->supports_inline() && (!$finished || !empty($settings->retake));
        return ['template' => 'format_spiffy/inline/lesson_done', 'context' => [
            'finished' => $finished,
            'hasgrade' => $grade !== '',
            'grade' => $grade,
            'hasreview' => $reviewurl !== '',
            'reviewurl' => $reviewurl,
            'canstart' => $canstart,
            'startlabel' => get_string($finished ? 'lessonretake' : 'lessonstart', 'format_spiffy'),
        ] + $this->export_feedback()];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        global $USER;
        if (!in_array($action, ['start', 'answer', 'continue'], true) || !$this->supports_inline()) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $lessonid = (int)$this->cm->instance;

        if ($action === 'start') {
            if (!$this->in_progress()) {
                $this->launch();
            }
            return null;
        }

        $pageid = (int)self::field($data, 'pageid');
        if (!$pageid || $pageid !== $this->get_current_pageid()) {
            // The card is out of date, e.g. the lesson moved on in another tab.
            throw new \moodle_exception('lessonpagemoved', 'format_spiffy');
        }
        $fields = $this->get_page_fields($action, $pageid, $data);
        if (!$this->in_progress()) {
            $this->launch();
        }

        // Process_page() turns off the session key check for the rest of the session; keep it on.
        $ignoredsesskey = !empty($USER->ignoresesskey);
        try {
            $result = \mod_lesson_external::process_page($lessonid, $pageid, $fields);
        } finally {
            if (!$ignoredsesskey) {
                unset($USER->ignoresesskey);
            }
        }

        if ($action === 'answer') {
            self::$feedback[$this->cm->id] = [
                'correct' => !empty($result['correctanswer']),
                'response' => (string)($result['response'] ?? ''),
                'noanswer' => !empty($result['noanswer']),
                'nodefault' => !empty($result['nodefaultresponse']),
                'attemptsremaining' => (int)($result['attemptsremaining'] ?? 0),
            ];
        }
        if ((int)$result['newpageid'] === self::JUMP_EOL) {
            \mod_lesson_external::finish_attempt($lessonid);
            return get_string('lessonfinished', 'format_spiffy');
        }
        return null;
    }

    /**
     * Start a new attempt, as view.php does when the user opens the lesson without a page.
     */
    protected function launch(): void {
        global $USER;
        // A new attempt is not a review of the last one.
        unset($USER->modattempts[$this->cm->instance]);
        \mod_lesson_external::launch_attempt((int)$this->cm->instance);
    }

    /**
     * The fields process_page() expects for the current page, built from the card's form.
     *
     * @param string $action 'answer' or 'continue'
     * @param int $pageid
     * @param array $data the submitted fields; untrusted
     * @return array[] each ['name' => string, 'value' => string]
     * @throws \moodle_exception when the action or the jump does not fit the page
     */
    protected function get_page_fields(string $action, int $pageid, array $data): array {
        $page = $this->get_lesson()->load_page($pageid);
        $qtype = (int)$page->properties()->qtype;
        $fields = [
            ['name' => 'id', 'value' => (string)$this->cm->id],
            ['name' => 'pageid', 'value' => (string)$pageid],
        ];

        if ($qtype === self::QTYPE_CONTENT) {
            if ($action !== 'continue') {
                throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
            }
            $allowed = [];
            foreach ($page->get_answers() as $answer) {
                if ((string)$answer->properties()->answer !== '') {
                    $allowed[] = (int)$answer->properties()->jumpto;
                }
            }
            $allowed = $allowed ?: [self::JUMP_NEXTPAGE];
            $jumpto = (int)clean_param(self::field($data, 'jumpto'), PARAM_INT);
            if (!in_array($jumpto, $allowed, true)) {
                throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
            }
            $fields[] = ['name' => 'jumpto', 'value' => (string)$jumpto];
            return $fields;
        }

        if ($action !== 'answer' || !isset(self::FORMS[$qtype])) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $fields[] = ['name' => '_qf__' . self::FORMS[$qtype], 'value' => '1'];
        if ($qtype === self::QTYPE_TRUEFALSE || $qtype === self::QTYPE_MULTICHOICE) {
            $fields[] = ['name' => 'answerid', 'value' => (string)(int)clean_param(self::field($data, 'answerid'), PARAM_INT)];
        } else {
            $fields[] = ['name' => 'answer', 'value' => trim(clean_param(self::field($data, 'answer'), PARAM_TEXT))];
        }
        return $fields;
    }

    /**
     * One submitted field as a string.
     *
     * @param array $data the submitted fields; untrusted
     * @param string $name
     * @return string '' when it is missing or a list
     */
    protected static function field(array $data, string $name): string {
        return is_string($data[$name] ?? null) ? $data[$name] : '';
    }
}
