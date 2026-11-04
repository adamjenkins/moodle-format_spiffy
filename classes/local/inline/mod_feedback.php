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
 * Feedback on a card: a one-question feedback answered inside the card, then the results.
 *
 * Only a feedback with exactly one question (labels and page breaks aside) is answered inline; any
 * other feedback opens its own page. The answer goes through mod_feedback_external::process_page(),
 * so the feedback's capability, date, "multiple submissions" and validation checks, its events,
 * notifications and completion apply unchanged. The views read the feedback with its own classes
 * (mod_feedback_completion and the item classes): an external function resets the page output, so
 * it cannot be called while the page renders. The option lists and the analysis follow the item
 * classes in mod/feedback/item/ and mod/feedback/analysis.php.
 *
 * @package    format_spiffy
 * @copyright  Andreas Grabs
 * @copyright  2016 Marina Glancy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_feedback extends handler {
    /** @var string[] Question types answered on the card. */
    protected const ITEM_TYPES = ['multichoice', 'multichoicerated', 'numeric', 'textarea', 'textfield'];

    /** @var string[] Item types that hold no answer and do not count as questions. */
    protected const DISPLAY_TYPES = ['label', 'pagebreak'];

    /** @var stdClass|null The feedback record, loaded once. */
    protected ?stdClass $feedback = null;

    /** @var \mod_feedback_completion|null The completion helper for the current user, made once. */
    protected ?\mod_feedback_completion $completion = null;

    /** @var stdClass|false|null The one question item: null before it is looked up, false when there is none. */
    protected stdClass|false|null $item = null;

    /** @var bool|null Whether the current user has submitted the feedback, looked up once. */
    protected ?bool $submitted = null;

    /**
     * The feedback record.
     *
     * @return stdClass
     */
    protected function get_feedback(): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        if ($this->feedback === null) {
            $this->feedback = $DB->get_record('feedback', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->feedback;
    }

    /**
     * The completion helper of the feedback for the current user.
     *
     * @return \mod_feedback_completion
     */
    protected function get_completion(): \mod_feedback_completion {
        if ($this->completion === null) {
            $this->completion = new \mod_feedback_completion($this->get_feedback(), $this->cm, 0);
        }
        return $this->completion;
    }

    /**
     * The feedback's one question, when it has exactly one of a type the card can show.
     *
     * @return stdClass|null the feedback_item record
     */
    protected function get_question_item(): ?stdClass {
        if ($this->item === null) {
            $this->item = false;
            $questions = array_filter(
                $this->get_completion()->get_items(),
                fn($item) => !in_array($item->typ, self::DISPLAY_TYPES, true)
            );
            if (count($questions) === 1) {
                $item = reset($questions);
                if (
                    in_array($item->typ, self::ITEM_TYPES, true) && empty($item->dependitem)
                        && $this->get_subtype($item) !== null
                ) {
                    $this->item = $item;
                }
            }
        }
        return $this->item ?: null;
    }

    /**
     * How a question is answered.
     *
     * @param stdClass $item the feedback_item record
     * @return string|null 'radio', 'checkbox', 'select', 'numeric', 'textfield' or 'textarea'; null when not supported
     */
    protected function get_subtype(stdClass $item): ?string {
        if ($item->typ === 'multichoice' || $item->typ === 'multichoicerated') {
            $info = feedback_get_item_class($item->typ)->get_info($item);
            $subtypes = ['r' => 'radio', 'd' => 'select'];
            if ($item->typ === 'multichoice') {
                $subtypes['c'] = 'checkbox';
            }
            return $subtypes[$info->subtype] ?? null;
        }
        return $item->typ;
    }

    /**
     * Whether the current user has submitted the feedback at least once.
     *
     * @return bool
     */
    protected function has_submitted(): bool {
        if ($this->submitted === null) {
            $this->submitted = isloggedin() && !isguestuser() && $this->get_completion()->is_already_submitted();
        }
        return $this->submitted;
    }

    /**
     * Whether the current user may submit the feedback now.
     *
     * @return bool
     */
    protected function can_submit_now(): bool {
        if (!isloggedin() || isguestuser() || !$this->get_question_item()) {
            return false;
        }
        $completion = $this->get_completion();
        return $completion->can_complete() && $completion->is_open() && $completion->can_submit();
    }

    #[\Override]
    protected function supports_inline(): bool {
        return $this->can_submit_now();
    }

    #[\Override]
    public function is_done(): bool {
        return parent::is_done() || $this->has_submitted();
    }

    #[\Override]
    public function get_badges(): array {
        return [];
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        $question = $this->export_question();
        return $question ? ['template' => 'format_spiffy/inline/feedback', 'context' => $question] : null;
    }

    /**
     * The question for the answer form.
     *
     * @return array|null template context of format_spiffy/inline/feedback
     */
    protected function export_question(): ?array {
        $item = $this->get_question_item();
        if (!$item) {
            return null;
        }
        $context = $this->cm->context;
        $subtype = $this->get_subtype($item);
        $fieldid = 'spiffy-feedback-' . $this->cm->id . '-' . $item->id;
        $data = [
            'itemid' => (int)$item->id,
            'fieldid' => $fieldid,
            'name' => format_text($item->name, FORMAT_HTML, ['context' => $context, 'para' => false]),
            'required' => !empty($item->required),
            'labels' => $this->export_labels(),
            'is' . $subtype => true,
            'ischoice' => in_array($subtype, ['radio', 'checkbox'], true),
            'options' => [],
        ];
        foreach ($this->get_options($item) as $value => $text) {
            $data['options'][] = ['value' => $value, 'text' => $text, 'id' => $fieldid . '-' . $value];
        }
        $presentation = explode('|', (string)$item->presentation);
        if ($subtype === 'numeric') {
            [$from, $to] = $presentation + [0 => '', 1 => ''];
            $range = ['from' => is_numeric($from) ? format_float((float)$from, -1) : '',
                'to' => is_numeric($to) ? format_float((float)$to, -1) : ''];
            if ($range['from'] !== '' && $range['to'] !== '') {
                $data['rangehint'] = get_string('feedbackrange', 'format_spiffy', $range);
            } else if ($range['from'] !== '') {
                $data['rangehint'] = get_string('feedbackrangefrom', 'format_spiffy', $range['from']);
            } else if ($range['to'] !== '') {
                $data['rangehint'] = get_string('feedbackrangeto', 'format_spiffy', $range['to']);
            }
        } else if ($subtype === 'textfield') {
            $data['maxlength'] = max(1, (int)($presentation[1] ?? 255));
        } else if ($subtype === 'textarea') {
            $data['rows'] = min(10, max(2, (int)($presentation[1] ?? 4)));
        }
        return $data;
    }

    /**
     * The feedback's labels, formatted, in their order.
     *
     * @return string[] HTML
     */
    protected function export_labels(): array {
        $labels = [];
        foreach ($this->get_completion()->get_items() as $item) {
            if ($item->typ !== 'label') {
                continue;
            }
            // Mirrors complete_form_element in feedback_item_label.
            $text = file_rewrite_pluginfile_urls(
                $item->presentation,
                'pluginfile.php',
                $this->cm->context->id,
                'mod_feedback',
                'item',
                $item->id
            );
            $labels[] = format_text($text, FORMAT_HTML, ['context' => $this->cm->context, 'overflowdiv' => true]);
        }
        return $labels;
    }

    /**
     * The options of a multiple choice question, as the item classes list them.
     *
     * @param stdClass $item the feedback_item record
     * @return string[] value => formatted text; empty for other question types
     */
    protected function get_options(stdClass $item): array {
        $formatoptions = ['context' => $this->cm->context, 'para' => false];
        $options = [];
        if ($item->typ === 'multichoice') {
            // Mirrors get_options in feedback_item_multichoice, without the "not selected" option.
            $info = feedback_get_item_class('multichoice')->get_info($item);
            foreach (explode(FEEDBACK_MULTICHOICE_LINE_SEP, $info->presentation) as $idx => $text) {
                $options[$idx + 1] = format_text($text, FORMAT_HTML, $formatoptions);
            }
        } else if ($item->typ === 'multichoicerated') {
            // Mirrors get_options in feedback_item_multichoicerated, without the "not selected" option.
            $info = feedback_get_item_class('multichoicerated')->get_info($item);
            foreach (explode(FEEDBACK_MULTICHOICERATED_LINE_SEP, $info->presentation) as $idx => $line) {
                [$weight, $text] = explode(FEEDBACK_MULTICHOICERATED_VALUE_SEP, $line) + [0 => '', 1 => ''];
                $options[$idx + 1] = get_string(
                    'multichoiceoption',
                    'feedback',
                    (object)['weight' => s($weight), 'name' => format_text($text, FORMAT_HTML, $formatoptions)]
                );
            }
        }
        return $options;
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        global $DB;
        $item = $this->get_question_item();
        if (!$item) {
            // Other feedbacks show their results on their own pages.
            return null;
        }
        $completion = $this->get_completion();
        $data = [
            // Not for a card that is done by completion alone: the text is for those who submitted.
            'pageaftersubmit' => $this->has_submitted() ? (string)$completion->page_after_submit() : '',
            'answer' => '',
            'hasanswer' => false,
            'showresults' => false,
        ];

        $mine = [];
        if (!$completion->is_anonymous() && ($completed = $completion->find_last_completed())) {
            $value = $DB->get_record('feedback_value', ['completed' => $completed->id, 'item' => $item->id]);
            if ($value) {
                $printval = (string)feedback_get_item_class($item->typ)->get_printval($item, $value);
                $lines = array_filter(array_map('trim', explode(
                    "\n",
                    html_entity_decode(strip_tags($printval), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                )), 'strlen');
                $data['answer'] = $item->typ === 'textarea' ? implode("\n", $lines) : implode(', ', $lines);
                $data['hasanswer'] = $data['answer'] !== '';
                if (in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
                    $mine = array_map('intval', explode(FEEDBACK_MULTICHOICE_LINE_SEP, (string)$value->value));
                }
            }
        }

        if ($completion->can_view_analysis()) {
            $data = array_merge($data, $this->export_results($item, $mine));
            $data['resultsurl'] = (new \moodle_url('/mod/feedback/analysis.php', ['id' => $this->cm->id]))->out(false);
        }

        if ($this->can_submit_now()) {
            $data['again'] = $this->export_question();
        }
        return ['template' => 'format_spiffy/inline/feedback_done', 'context' => $data];
    }

    /**
     * The results of the question, as mod/feedback/analysis.php shows them.
     *
     * @param stdClass $item the feedback_item record
     * @param int[] $mine the options the current user chose
     * @return array template context: showresults, hasoptions, options, summary
     */
    protected function export_results(stdClass $item, array $mine): array {
        $feedback = $this->get_feedback();
        $groupid = groups_get_activity_groupmode($this->cm) ? groups_get_activity_group($this->cm) : false;
        if (
            $groupid && $feedback->anonymous == FEEDBACK_ANONYMOUS_YES
                && $this->get_completion()->count_completed_responses($groupid) < FEEDBACK_MIN_ANONYMOUS_COUNT_IN_GROUP
        ) {
            return ['showresults' => false];
        }
        $analysed = feedback_get_item_class($item->typ)->get_analysed_for_external($item, $groupid);
        $result = ['showresults' => true, 'options' => [], 'hasoptions' => false, 'summary' => ''];
        if (in_array($item->typ, ['multichoice', 'multichoicerated'], true)) {
            $formatoptions = ['context' => $this->cm->context, 'para' => false];
            foreach (array_values($analysed) as $idx => $json) {
                $answer = json_decode($json);
                if (!$answer) {
                    continue;
                }
                $result['options'][] = [
                    'text' => format_text($answer->answertext, FORMAT_HTML, $formatoptions),
                    'mine' => in_array($idx + 1, $mine, true),
                    'count' => (int)$answer->answercount,
                    'percent' => (int)round($answer->quotient * 100),
                ];
            }
            $result['hasoptions'] = !empty($result['options']);
        } else if ($item->typ === 'numeric') {
            $values = array_filter($analysed, 'is_numeric');
            if ($values) {
                $result['summary'] = get_string('feedbackaverage', 'format_spiffy', (object)[
                    'average' => format_float(array_sum($values) / count($values), 2),
                    'count' => count($values),
                ]);
            }
        } else {
            $result['summary'] = get_string('feedbackresponses', 'format_spiffy', count($analysed));
        }
        return $result;
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        global $USER;
        if ($action !== 'submit') {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $item = $this->get_question_item();
        if (!$item) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }

        // The field names of mod_feedback_complete_form: <typ>_<itemid>, and <typ>_<itemid>[<n>] for checkboxes.
        $name = $item->typ . '_' . $item->id;
        $value = $data['value'] ?? '';
        $responses = [];
        if ($this->get_subtype($item) === 'checkbox') {
            $responses[] = ['name' => $name . '[0]', 'value' => '0'];
            foreach (array_unique(array_map('intval', (array)$value)) as $option) {
                if ($option > 0) {
                    $responses[] = ['name' => $name . '[' . $option . ']', 'value' => (string)$option];
                }
            }
        } else {
            $value = is_array($value) ? ($value ? (string)end($value) : '') : (string)$value;
            if (in_array($this->get_subtype($item), ['radio', 'select'], true)) {
                $value = (string)max(0, (int)$value);
            }
            $responses[] = ['name' => $name, 'value' => $value];
        }

        // The external function switches off the sesskey check deep in the form; put it back afterwards.
        $ignoresesskey = $USER->ignoresesskey ?? null;
        try {
            $result = \mod_feedback_external::process_page($this->get_feedback()->id, 0, $responses, false, 0);
        } finally {
            if ($ignoresesskey === null) {
                unset($USER->ignoresesskey);
            } else {
                $USER->ignoresesskey = $ignoresesskey;
            }
        }
        if (empty($result['completed'])) {
            throw new \moodle_exception('feedbacknotsaved', 'format_spiffy');
        }
        return get_string('entries_saved', 'feedback');
    }
}
