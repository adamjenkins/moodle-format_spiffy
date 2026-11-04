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
 * Assignment on a card: write a short online text answer, save or submit it, then see the grade
 * and feedback once they are released.
 *
 * Inline only for individual assignments whose one submission type is online text, without a
 * time limit, while submissions are open for the user (dates, cut-off, extensions and overrides,
 * as the assign class works them out). Writes call the assign class's save_submission() and
 * submit_for_grading(), as mod_assign_external does, so its capability, date, lock, word limit
 * and submission statement checks apply unchanged and its notices reach the user. Nothing here
 * calls an external function: that resets the page output, so it cannot run while the page
 * renders. The grade and feedback visibility rules follow
 * assign::get_assign_feedback_status_renderable().
 *
 * @package    format_spiffy
 * @copyright  2012 NetSpot {@link http://www.netspot.com.au}
 * @copyright  2012 Paul Charsley
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_assign extends handler {
    /** @var string File area of online text submissions (ASSIGNSUBMISSION_ONLINETEXT_FILEAREA). */
    protected const FILEAREA = 'submissions_onlinetext';

    /** @var \assign|null The assignment, loaded once. */
    protected ?\assign $assign = null;

    /** @var stdClass|false|null The user's latest submission: null until loaded, false when there is none. */
    protected stdClass|false|null $submission = null;

    /**
     * The assignment, with the current user's overrides applied.
     *
     * @return \assign
     */
    protected function get_assign(): \assign {
        global $CFG, $USER;
        if ($this->assign === null) {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $this->assign = new \assign($this->cm->context, $this->cm, $this->cm->get_course());
            $this->assign->update_effective_access($USER->id);
        }
        return $this->assign;
    }

    /**
     * The assignment settings for the current user.
     *
     * @return stdClass
     */
    protected function get_instance(): stdClass {
        global $USER;
        return $this->get_assign()->get_instance($USER->id);
    }

    /**
     * The current user's latest submission (their group's for team assignments).
     *
     * @return stdClass|false
     */
    protected function get_submission(): stdClass|false {
        global $USER;
        if ($this->submission === null) {
            $assign = $this->get_assign();
            if (!empty($this->get_instance()->teamsubmission)) {
                $submission = $assign->get_group_submission($USER->id, 0, false);
            } else {
                $submission = $assign->get_user_submission($USER->id, false);
            }
            $this->submission = $submission ?: false;
        }
        return $this->submission;
    }

    /**
     * The online text submission plugin, when it is enabled.
     *
     * @return \assign_submission_plugin|null
     */
    protected function get_onlinetext(): ?\assign_submission_plugin {
        $plugin = $this->get_assign()->get_submission_plugin_by_type('onlinetext');
        return $plugin && $plugin->is_enabled() && $plugin->is_visible() ? $plugin : null;
    }

    /**
     * Whether online text is the only submission type the assignment asks for.
     *
     * @return bool
     */
    protected function is_onlinetext_only(): bool {
        $types = [];
        foreach ($this->get_assign()->get_submission_plugins() as $plugin) {
            if ($plugin->is_enabled() && $plugin->is_visible() && $plugin->allow_submissions()) {
                $types[] = $plugin->get_type();
            }
        }
        return $types === ['onlinetext'];
    }

    /**
     * The saved online text of a submission.
     *
     * @param stdClass|false $submission
     * @return array [text, format]; ['', FORMAT_PLAIN] when there is none
     */
    protected function get_text(stdClass|false $submission): array {
        $plugin = $this->get_onlinetext();
        if (!$submission || !$plugin) {
            return ['', FORMAT_PLAIN];
        }
        $text = (string)$plugin->get_editor_text('onlinetext', $submission->id);
        $format = $text === '' ? FORMAT_PLAIN : (int)$plugin->get_editor_format('onlinetext', $submission->id);
        return [$text, $format];
    }

    /**
     * Whether a saved text can be edited in a plain text box without losing anything: text
     * written in the full editor (HTML, images) is edited on the assignment page instead.
     *
     * @param string $text
     * @param int $format
     * @return bool
     */
    protected static function is_plain(string $text, int $format): bool {
        // FORMAT_ constants are strings.
        return $text === '' || in_array((string)$format, [FORMAT_PLAIN, FORMAT_MOODLE], true);
    }

    /**
     * The submission statement the user must accept, if any (as assign::get_submissionstatement()
     * for individual submissions).
     *
     * @return string formatted HTML, or '' for none
     */
    protected function get_statement(): string {
        $instance = $this->get_instance();
        if (empty($instance->requiresubmissionstatement) || !empty($instance->teamsubmission)) {
            return '';
        }
        $config = $this->get_assign()->get_admin_config();
        if (empty($config->submissionstatement)) {
            return '';
        }
        return format_text($config->submissionstatement, FORMAT_MOODLE, ['context' => $this->cm->context, 'para' => false]);
    }

    /**
     * Whether the user can write their submission on the card now.
     *
     * @return bool
     */
    protected function can_write(): bool {
        global $USER;
        if (!has_capability('mod/assign:submit', $this->cm->context)) {
            return false;
        }
        $instance = $this->get_instance();
        if (!empty($instance->teamsubmission) || !empty($instance->timelimit) || !$this->is_onlinetext_only()) {
            return false;
        }
        if (!$this->get_assign()->submissions_open($USER->id, false, $this->get_submission())) {
            return false;
        }
        [$text, $format] = $this->get_text($this->get_submission());
        return self::is_plain($text, $format);
    }

    #[\Override]
    protected function supports_inline(): bool {
        return $this->can_write();
    }

    #[\Override]
    public function is_done(): bool {
        $submission = $this->get_submission();
        return parent::is_done() || ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED);
    }

    /**
     * The context of the submission form, shared by the available view and the edit box of the
     * done view.
     *
     * @return array
     */
    protected function export_form(): array {
        $instance = $this->get_instance();
        $submission = $this->get_submission();
        [$text] = $this->get_text($submission);
        $drafts = !empty($instance->submissiondrafts);
        $plugin = $this->get_onlinetext();
        $wordlimit = $plugin && $plugin->get_config('wordlimitenabled') ? (int)$plugin->get_config('wordlimit') : 0;
        $statement = $this->get_statement();
        return [
            'text' => $text,
            'drafts' => $drafts,
            'draftnotice' => $submission && $submission->status === ASSIGN_SUBMISSION_STATUS_DRAFT && $text !== ''
                ? get_string(
                    'assigndraftnotice',
                    'format_spiffy',
                    userdate($submission->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'))
                )
                : '',
            'haswordlimit' => $wordlimit > 0,
            'wordlimit' => $wordlimit,
            'hasstatement' => $statement !== '',
            'statement' => $statement,
            'statementrequired' => $statement !== '' && !$drafts,
            'name' => format_string($this->cm->name, true, ['context' => $this->cm->context]),
        ];
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return ['template' => 'format_spiffy/inline/assign', 'context' => $this->export_form()];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        $assign = $this->get_assign();
        $instance = $this->get_instance();
        $context = $this->cm->context;
        $submission = $this->get_submission();

        // The user's text, as the assignment shows it.
        [$text, $format] = $this->get_text($submission);
        $html = '';
        if ($text !== '') {
            $text = file_rewrite_pluginfile_urls(
                $text,
                'pluginfile.php',
                $context->id,
                'assignsubmission_onlinetext',
                self::FILEAREA,
                $submission->id
            );
            $html = format_text($text, $format, ['context' => $context]);
        }

        $status = '';
        if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            $status = get_string(
                'assignsubmittedon',
                'format_spiffy',
                userdate($submission->timemodified, get_string('strftimedatetimeshort', 'core_langconfig'))
            );
        } else if ($submission && $submission->status === ASSIGN_SUBMISSION_STATUS_DRAFT) {
            $status = get_string('submissionstatus_draft', 'assign');
        }

        // The grade and feedback, once released: as assign::get_assign_feedback_status_renderable().
        $released = empty($instance->markingworkflow)
            || $assign->get_grading_status($USER->id) === ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        $grade = '';
        $feedback = '';
        if ($released) {
            $gradinginfo = grade_get_grades($this->cm->course, 'mod', 'assign', $instance->id, $USER->id);
            $item = $gradinginfo->items[0] ?? null;
            $gradebookgrade = $item->grades[$USER->id] ?? null;
            $visible = $gradebookgrade && empty($gradebookgrade->hidden) && empty($item->hidden);
            if ($visible && (int)$instance->grade !== GRADE_TYPE_NONE && $gradebookgrade->grade !== null) {
                $grade = $gradebookgrade->str_long_grade;
            }
            $usergrade = $assign->get_user_grade($USER->id, false);
            $comments = $assign->get_feedback_plugin_by_type('comments');
            $gradevisible = (int)$instance->grade === GRADE_TYPE_NONE || $visible;
            if (
                $usergrade && $gradevisible && $comments && $comments->is_enabled() && $comments->is_visible()
                    && !$comments->is_empty($usergrade)
            ) {
                $showviewlink = false;
                $feedback = $comments->view_summary($usergrade, $showviewlink);
            }
        }

        $canedit = $this->can_write();
        $form = $canedit ? $this->export_form() : [];
        return ['template' => 'format_spiffy/inline/assign_done', 'context' => $form + [
            'hastext' => $html !== '',
            'submittedtext' => $html,
            'status' => $status,
            'hasgrade' => $grade !== '',
            'grade' => $grade !== '' ? get_string('badgegrade', 'format_spiffy', $grade) : '',
            'hasfeedback' => $feedback !== '',
            'feedback' => $feedback,
            'canedit' => $canedit,
        ]];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        if (!in_array($action, ['save', 'submit'], true) || !$this->can_write()) {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $assign = $this->get_assign();
        $drafts = !empty($this->get_instance()->submissiondrafts);
        $submit = $action === 'submit' || !empty($data['submit']);
        $accepted = !empty($data['statement']);
        if ($this->get_statement() !== '' && !$accepted && (!$drafts || $submit)) {
            throw new \moodle_exception('submissionstatementrequired', 'mod_assign');
        }

        // Mirrors save_submission in mod_assign_external, keeping the assign class's notices for the user.
        $submission = (object)[
            'onlinetext_editor' => [
                'text' => is_string($data['text'] ?? null) ? trim($data['text']) : '',
                'format' => FORMAT_PLAIN,
                'itemid' => file_get_unused_draft_itemid(),
            ],
        ];
        if ($accepted && !$drafts) {
            $submission->submissionstatement = 1;
        }
        $notices = [];
        if (!$assign->save_submission($submission, $notices)) {
            self::throw_notices($notices, 'assignnotsaved');
        }
        if (!$drafts) {
            return get_string('assignsubmitted', 'format_spiffy');
        }
        if (!$submit) {
            return get_string('assigndraftsaved', 'format_spiffy');
        }
        // Mirrors submit_for_grading in mod_assign_external.
        if (!$assign->submit_for_grading((object)['submissionstatement' => $accepted ? 1 : 0], [])) {
            self::throw_notices([], 'assignnotsubmitted');
        }
        return get_string('assignsubmitted', 'format_spiffy');
    }

    /**
     * Stop an action with the assign class's notices.
     *
     * @param string[] $notices
     * @param string $identifier the format_spiffy string to show, with the notices as {$a}
     * @throws \moodle_exception always
     */
    protected static function throw_notices(array $notices, string $identifier): void {
        $messages = array_filter(array_map(fn($notice) => trim(strip_tags((string)$notice)), $notices));
        throw new \moodle_exception($identifier, 'format_spiffy', '', implode(' ', $messages));
    }
}
