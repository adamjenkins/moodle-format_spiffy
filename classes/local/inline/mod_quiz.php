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

use mod_quiz\access_manager;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use question_attempt;
use question_display_options;
use renderer_base;
use stdClass;

/**
 * Quiz on a card: a one-question quiz answered inside the card, with no start, submit or confirm pages.
 *
 * Only a quiz with exactly one multiple choice (one answer), true/false, short answer or numerical
 * question, and no access rule beyond dates, attempt limits and delays, is answered inline; any other
 * quiz opens its own page. The answer starts, submits and finishes an attempt through
 * mod_quiz_external::start_attempt() and process_attempt(), so the quiz's capability, access rule,
 * event, grade and completion handling apply unchanged. The views read the quiz with its own classes
 * and lib functions (an external function resets the page output, so it cannot be called while the
 * page renders) and show only what the quiz's review options allow, as mod/quiz/view.php and the
 * question type renderers do.
 *
 * @package    format_spiffy
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2009 The Open University
 * @copyright  2016 Juan Leyva <juan@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quiz extends handler {
    /** @var string[] Question types answered on the card. */
    protected const QTYPES = ['multichoice', 'numerical', 'shortanswer', 'truefalse'];

    /** @var string[] Access rules that need nothing beyond what the card shows. */
    protected const PLAIN_RULES = [
        'quizaccess_delaybetweenattempts', 'quizaccess_numattempts', 'quizaccess_offlineattempts', 'quizaccess_openclosedate',
    ];

    /** @var quiz_settings|null The quiz, with the current user's overrides, loaded once. */
    protected ?quiz_settings $quizobj = null;

    /** @var access_manager|null The quiz's access rules now, made once. */
    protected ?access_manager $accessmanager = null;

    /** @var stdClass[]|null The current user's attempts (no previews), oldest first, loaded once. */
    protected ?array $attempts = null;

    /** @var stdClass|false|null The one question, fully loaded: null before it is looked up, false when there is none. */
    protected stdClass|false|null $question = null;

    /**
     * The quiz.
     *
     * @return quiz_settings
     */
    protected function get_quizobj(): quiz_settings {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if ($this->quizobj === null) {
            $this->quizobj = quiz_settings::create($this->cm->instance, $USER->id);
        }
        return $this->quizobj;
    }

    /**
     * The quiz's access rules now.
     *
     * @return access_manager
     */
    protected function get_access_manager(): access_manager {
        if ($this->accessmanager === null) {
            $this->accessmanager = $this->get_quizobj()->get_access_manager(time());
        }
        return $this->accessmanager;
    }

    /**
     * The current user's attempts.
     *
     * @return stdClass[] quiz_attempts records, oldest first
     */
    protected function get_attempts(): array {
        global $USER;
        if ($this->attempts === null) {
            $this->attempts = array_values(quiz_get_user_attempts($this->get_quizobj()->get_quizid(), $USER->id, 'all'));
        }
        return $this->attempts;
    }

    /**
     * The current user's attempts in some states.
     *
     * @param string[] $states quiz_attempt state constants
     * @return stdClass[] oldest first
     */
    protected function get_attempts_in(array $states): array {
        return array_values(array_filter($this->get_attempts(), fn($attempt) => in_array($attempt->state, $states, true)));
    }

    /**
     * The quiz's one question, when it has exactly one of a kind the card can show.
     *
     * @return stdClass|null the question data with its options and slot number
     */
    protected function get_question(): ?stdClass {
        if ($this->question !== null) {
            return $this->question ?: null;
        }
        $this->question = false;
        $quizobj = $this->get_quizobj();
        if (!$quizobj->has_questions()) {
            return null;
        }
        $questions = $quizobj->get_questions(null, false);
        if (count($questions) !== 1) {
            return null;
        }
        $question = reset($questions);
        if (!empty($question->random) || !in_array($question->qtype, self::QTYPES, true)) {
            return null;
        }
        $quizobj->load_questions();
        $question = $quizobj->get_question($question->questionid);
        if (!$this->is_simple($question)) {
            return null;
        }
        $this->question = $question;
        return $question;
    }

    /**
     * Whether the card can show a question: one answer field, no files (their URLs belong to an attempt).
     *
     * @param stdClass $question the question data with its options
     * @return bool
     */
    protected function is_simple(stdClass $question): bool {
        if (empty($question->options) || str_contains((string)$question->questiontext, '@@PLUGINFILE@@')) {
            return false;
        }
        if ($question->qtype === 'multichoice') {
            if (empty($question->options->single) || empty($question->options->answers)) {
                return false;
            }
            foreach ($question->options->answers as $answer) {
                if (str_contains((string)$answer->answer, '@@PLUGINFILE@@')) {
                    return false;
                }
            }
        } else if ($question->qtype === 'numerical') {
            \question_bank::get_qtype('numerical');
            $showunits = (int)($question->options->showunits ?? \qtype_numerical::UNITNONE);
            if (in_array($showunits, [\qtype_numerical::UNITRADIO, \qtype_numerical::UNITSELECT], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the current user can start a new attempt from the card now.
     *
     * @return bool
     */
    protected function can_start_attempt(): bool {
        if (!has_capability('mod/quiz:attempt', $this->cm->context) || !$this->get_question()) {
            return false;
        }
        $accessmanager = $this->get_access_manager();
        if (array_diff($accessmanager->get_active_rule_names(), self::PLAIN_RULES)) {
            return false;
        }
        if ($accessmanager->prevent_access() || $accessmanager->is_preflight_check_required(null)) {
            return false;
        }
        if ($this->get_attempts_in([quiz_attempt::IN_PROGRESS, quiz_attempt::OVERDUE])) {
            // An attempt started on the quiz page is finished there.
            return false;
        }
        $previous = $this->get_attempts_in([quiz_attempt::FINISHED, quiz_attempt::ABANDONED, quiz_attempt::SUBMITTED]);
        return !$accessmanager->prevent_new_attempt(count($previous), end($previous) ?: false);
    }

    #[\Override]
    protected function supports_inline(): bool {
        return $this->can_start_attempt();
    }

    #[\Override]
    public function is_done(): bool {
        return parent::is_done() || (bool)$this->get_attempts_in([quiz_attempt::FINISHED, quiz_attempt::SUBMITTED]);
    }

    #[\Override]
    public function get_badges(): array {
        global $CFG, $USER;
        if (!has_capability('mod/quiz:attempt', $this->cm->context)) {
            return [];
        }
        $finished = $this->get_attempts_in([quiz_attempt::FINISHED]);
        if (!$finished) {
            return [];
        }
        $quiz = $this->get_quizobj()->get_quiz();
        // As mod/quiz/view.php: the best grade shows only when some attempt's review options show marks.
        [$someoptions] = quiz_get_combined_reviewoptions($quiz, $finished);
        if ($someoptions->marks < question_display_options::MARK_AND_MAX || !quiz_has_grades($quiz)) {
            return [];
        }
        $best = quiz_get_best_grade($quiz, $USER->id);
        if ($best === null) {
            return [];
        }
        // Respect a grade hidden in the gradebook.
        require_once($CFG->libdir . '/gradelib.php');
        $grades = grade_get_grades($this->cm->course, 'mod', 'quiz', $this->cm->instance, $USER->id);
        foreach ($grades->items ?? [] as $item) {
            $grade = $item->grades[$USER->id] ?? null;
            if (!empty($item->hidden) || ($grade && !empty($grade->hidden))) {
                return [];
            }
        }
        $text = get_string('outofshort', 'quiz', (object)[
            'grade' => quiz_format_grade($quiz, $best),
            'maxgrade' => quiz_format_grade($quiz, $quiz->grade),
        ]);
        return [['text' => get_string('badgebestgrade', 'format_spiffy', $text), 'type' => 'success']];
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        $question = $this->export_question();
        return $question ? ['template' => 'format_spiffy/inline/quiz', 'context' => $question] : null;
    }

    /**
     * The question for the answer form.
     *
     * @return array|null template context of format_spiffy/inline/quiz
     */
    protected function export_question(): ?array {
        $question = $this->get_question();
        if (!$question) {
            return null;
        }
        $context = $this->cm->context;
        $fieldid = 'spiffy-quiz-' . $this->cm->id;
        $data = [
            'fieldid' => $fieldid,
            'name' => format_string($this->cm->name, true, ['context' => $context]),
            'questiontext' => format_text($question->questiontext, $question->questiontextformat, ['context' => $context]),
            'is' . $question->qtype => true,
            'ischoice' => in_array($question->qtype, ['multichoice', 'truefalse'], true),
            'options' => [],
        ];
        if ($question->qtype === 'multichoice') {
            $answers = $question->options->answers;
            if (!empty($this->get_quizobj()->get_quiz()->shuffleanswers) && !empty($question->options->shuffleanswers)) {
                // The attempt starts only when the answer is sent; a card re-renders often, so keep one order per user.
                global $USER;
                uasort($answers, fn($a, $b) => strcmp(sha1($USER->id . '/' . $a->id), sha1($USER->id . '/' . $b->id)));
            }
            foreach ($answers as $answer) {
                $data['options'][] = [
                    'value' => (int)$answer->id,
                    'id' => $fieldid . '-' . $answer->id,
                    'text' => format_text($answer->answer, $answer->answerformat, ['context' => $context, 'para' => false]),
                ];
            }
        } else if ($question->qtype === 'truefalse') {
            foreach ([1 => get_string('true', 'qtype_truefalse'), 0 => get_string('false', 'qtype_truefalse')] as $value => $text) {
                $data['options'][] = ['value' => $value, 'id' => $fieldid . '-' . $value, 'text' => $text];
            }
        }
        $allowed = (int)$this->get_quizobj()->get_num_attempts_allowed();
        if ($allowed > 0) {
            $used = count($this->get_attempts_in([quiz_attempt::FINISHED, quiz_attempt::ABANDONED, quiz_attempt::SUBMITTED]));
            $data['attemptsleft'] = get_string('quizattemptsleft', 'format_spiffy', max(0, $allowed - $used));
        }
        return $data;
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->get_question()) {
            // Other quizzes show their result on their own pages; the card keeps the best grade badge.
            return null;
        }
        $data = ['hasresult' => false, 'pending' => false];
        $done = $this->get_attempts_in([quiz_attempt::FINISHED, quiz_attempt::SUBMITTED]);
        $last = end($done);
        if ($last && $last->state === quiz_attempt::SUBMITTED) {
            $data['pending'] = true;
        } else if ($last && has_capability('mod/quiz:reviewmyattempts', $this->cm->context)) {
            $data = $this->export_result($last) + $data;
        }
        if ($this->can_start_attempt()) {
            $data['again'] = $this->export_question();
        }
        if (!$data['hasresult'] && !$data['pending'] && empty($data['again'])) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/quiz_done', 'context' => $data];
    }

    /**
     * What the quiz's review options let the user see of a finished attempt now.
     *
     * @param stdClass $attempt the quiz_attempts record
     * @return array template context of format_spiffy/inline/quiz_done
     */
    protected function export_result(stdClass $attempt): array {
        $quizobj = $this->get_quizobj();
        $quiz = $quizobj->get_quiz();
        $context = $this->cm->context;
        // The options of mod/quiz/review.php for this attempt now (immediately after, later while open, after close).
        $options = quiz_get_review_options($quiz, $attempt, $context);
        $grade = quiz_rescale_grade($attempt->sumgrades, $quiz, false);
        $data = ['hasresult' => true];

        if ($options->marks >= question_display_options::MARK_AND_MAX && quiz_has_grades($quiz)) {
            $data['grade'] = get_string('quizyourgrade', 'format_spiffy', get_string('outofshort', 'quiz', (object)[
                'grade' => quiz_format_grade($quiz, $grade),
                'maxgrade' => quiz_format_grade($quiz, $quiz->grade),
            ]));
        }
        if ($options->overallfeedback) {
            $data['overallfeedback'] = quiz_feedback_for_grade($grade, $quiz, $context);
        }

        $attemptobj = $quizobj->create_attempt_object($attempt);
        $slots = $attemptobj->get_slots();
        if ($slots) {
            $qa = $attemptobj->get_question_attempt(reset($slots));
            if ($options->attempt) {
                $data['response'] = (string)$qa->get_response_summary();
            }
            if ($options->correctness) {
                $data['state'] = $qa->get_state_string(true);
                $data['stateclass'] = $qa->get_state_class(true);
                $data['badgetype'] = match ($data['stateclass']) {
                    'correct' => 'success',
                    'partiallycorrect' => 'warning',
                    'incorrect' => 'danger',
                    default => 'info',
                };
            }
            if ($options->feedback) {
                $data['specificfeedback'] = $this->specific_feedback($qa);
            }
            if ($options->generalfeedback) {
                $data['generalfeedback'] = $qa->get_question()->format_generalfeedback($qa);
            }
            if ($options->rightanswer) {
                $rightanswer = (string)$qa->get_right_answer_summary();
                $data['rightanswer'] = $rightanswer === '' ? '' : get_string('quizrightanswer', 'format_spiffy', $rightanswer);
            }
        }
        $data['hasfeedback'] = !empty($data['overallfeedback']) || !empty($data['specificfeedback'])
            || !empty($data['generalfeedback']);
        return $data;
    }

    /**
     * The feedback on the chosen answer, as the question type renderers' specific_feedback() give it.
     *
     * @param question_attempt $qa
     * @return string HTML, or '' for none
     */
    protected function specific_feedback(question_attempt $qa): string {
        $question = $qa->get_question();
        $answer = null;
        switch ($question->get_type_name()) {
            case 'multichoice':
                // As qtype_multichoice_single_renderer: the feedback of the chosen option.
                $response = $qa->get_last_qt_var('answer', -1);
                $order = $question->get_order($qa);
                if (isset($order[$response])) {
                    $answer = $question->answers[$order[$response]] ?? null;
                }
                break;
            case 'truefalse':
                // Mirrors specific_feedback in qtype_truefalse_renderer.
                $response = $qa->get_last_qt_var('answer', '');
                if ($response !== '' && $response !== null) {
                    $true = (bool)$response;
                    return (string)$question->format_text(
                        $true ? $question->truefeedback : $question->falsefeedback,
                        $true ? $question->truefeedbackformat : $question->falsefeedbackformat,
                        $qa,
                        'question',
                        'answerfeedback',
                        $true ? $question->trueanswerid : $question->falseanswerid
                    );
                }
                return '';
            case 'shortanswer':
                // Mirrors specific_feedback in qtype_shortanswer_renderer.
                $answer = $question->get_matching_answer(['answer' => $qa->get_last_qt_var('answer')]);
                break;
            case 'numerical':
                // Mirrors specific_feedback in qtype_numerical_renderer, for questions without a separate unit field.
                [$value, , $multiplier] = $question->ap->apply_units($qa->get_last_qt_var('answer'), null);
                $answer = $question->get_matching_answer($value, $multiplier);
                break;
        }
        if (!$answer || empty($answer->feedback)) {
            return '';
        }
        return (string)$question->format_text(
            $answer->feedback,
            $answer->feedbackformat,
            $qa,
            'question',
            'answerfeedback',
            $answer->id
        );
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        if ($action !== 'answer' || !$this->can_start_attempt()) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $question = $this->get_question();
        $answer = $data['answer'] ?? '';
        $answer = trim(is_array($answer) ? ($answer ? (string)end($answer) : '') : (string)$answer);
        $isoption = $question->qtype !== 'multichoice' || isset($question->options->answers[(int)$answer]);
        if ($answer === '' || !$isoption) {
            // Checked before the attempt starts, so a bad request leaves no attempt behind.
            throw new \moodle_exception('quizanswerrequired', 'format_spiffy');
        }

        $started = \mod_quiz_external::start_attempt($this->get_quizobj()->get_quizid());
        // Not cleaned by the external API here, so the attempt is a record, not an array.
        $attempt = (array)($started['attempt'] ?? []);
        if (empty($attempt['id'])) {
            $warning = reset($started['warnings']);
            throw new \moodle_exception('quiznotstarted', 'format_spiffy', '', $warning ? $warning['message'] : '');
        }
        $attemptid = (int)$attempt['id'];
        $attemptobj = quiz_attempt::create($attemptid);
        $slots = $attemptobj->get_slots();
        $slot = (int)reset($slots);
        $qa = $attemptobj->get_question_attempt($slot);

        if ($question->qtype === 'multichoice') {
            // The form sends the answer id; the attempt expects its position in this attempt's (shuffled) order.
            $position = array_search((int)$answer, $qa->get_question()->get_order($qa));
            if ($position === false) {
                throw new \moodle_exception('quizanswerrequired', 'format_spiffy');
            }
            $value = (string)$position;
        } else if ($question->qtype === 'truefalse') {
            $value = $answer === '1' ? '1' : '0';
        } else {
            $value = \core_text::substr($answer, 0, 255);
        }

        // The fields of the question on mod/quiz/attempt.php, submitted and finished in one go.
        $postdata = [
            ['name' => 'slots', 'value' => (string)$slot],
            ['name' => $qa->get_control_field_name('sequencecheck'), 'value' => (string)$qa->get_sequence_check_count()],
            ['name' => $qa->get_qt_field_name('answer'), 'value' => $value],
        ];
        $result = \mod_quiz_external::process_attempt($attemptid, $postdata, true);
        if (($result['state'] ?? '') !== quiz_attempt::FINISHED) {
            throw new \moodle_exception('quiznotfinished', 'format_spiffy');
        }
        return get_string('quizanswersaved', 'format_spiffy');
    }
}
