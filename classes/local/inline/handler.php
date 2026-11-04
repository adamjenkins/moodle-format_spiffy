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

use cm_info;
use completion_info;
use format_spiffy\local\cm_settings;
use renderer_base;
use stdClass;

/**
 * Inline behaviour of one activity type on a Spiffy card.
 *
 * A card moves through three states: locked (the user cannot open it yet), available and done.
 * A handler says whether its activity can be done inside the card for the current user, what the
 * card shows while it is available and once it is done, and performs the actions the card's forms
 * send. Every action goes through format_spiffy_inline_action, which validates the activity
 * context (so require_login and the access restrictions apply) before calling process_action();
 * handlers still check the module capability, dates and settings themselves, preferably by
 * calling the module's own external function or lib function so core's checks apply.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class handler {
    /** @var string Card state: the user cannot open the activity yet. */
    public const STATE_LOCKED = 'locked';

    /** @var string Card state: the user can do the activity. */
    public const STATE_AVAILABLE = 'available';

    /** @var string Card state: the user has done the activity. */
    public const STATE_DONE = 'done';

    /**
     * Constructor.
     *
     * @param cm_info $cm the activity, from the current user's modinfo
     * @param stdClass $settings the activity's Spiffy settings (cm_settings::clean() shape)
     */
    public function __construct(
        /** @var cm_info the activity */
        protected cm_info $cm,
        /** @var stdClass the activity's Spiffy settings */
        protected stdClass $settings,
    ) {
    }

    /**
     * Whether activities of this type are done inline when the teacher leaves the setting on "auto".
     *
     * @return bool
     */
    public static function default_inline(): bool {
        return true;
    }

    /**
     * Whether this activity can be done inside the card for the current user right now: the
     * module settings allow it (e.g. a one-question quiz) and the user has the capability.
     * Called only for activities the user can open.
     *
     * @return bool
     */
    abstract protected function supports_inline(): bool;

    /**
     * The available view: the inline interaction.
     *
     * Forms in the template carry data-spiffy-action="<action>" and data-cmid; their fields
     * are sent as the action's data. Buttons with data-spiffy-action (and optional
     * data-spiffy-value) send one action without a form.
     *
     * @param renderer_base $output
     * @return array|null ['template' => name, 'context' => array|stdClass], or null for none
     */
    abstract protected function export_available(renderer_base $output): ?array;

    /**
     * Perform one action sent by the card.
     *
     * @param string $action the action name from data-spiffy-action
     * @param array $data the submitted fields, as strings or arrays of strings; untrusted
     * @return string|null a short message to show the user, or null
     * @throws \moodle_exception when the action is not allowed
     */
    abstract public function process_action(string $action, array $data): ?string;

    /**
     * The done view: the result (votes, grade, feedback, the user's entry), with edit or undo
     * actions where the activity allows them. Defaults to none, and the card shows only
     * core's completion details.
     *
     * @param renderer_base $output
     * @return array|null ['template' => name, 'context' => array|stdClass], or null for none
     */
    protected function export_done(renderer_base $output): ?array {
        return null;
    }

    /**
     * Whether the current user has done the activity. Defaults to its completion state.
     * Handlers of activities without completion can say done from their own data.
     *
     * @return bool
     */
    public function is_done(): bool {
        return self::is_complete($this->cm);
    }

    /**
     * Status badges for the card, e.g. the grade, the best quiz attempt or unread posts.
     * Defaults to the user's grade from the gradebook, when there is one and it is visible.
     *
     * @return array[] each ['text' => string, 'type' => 'info'|'success'|'warning'|'danger']
     */
    public function get_badges(): array {
        return self::grade_badges($this->cm);
    }

    /**
     * Whether the card shows the inline interaction for the current user.
     *
     * @return bool
     */
    final public function is_inline(): bool {
        return $this->accepts_actions() && $this->supports_inline();
    }

    /**
     * Whether the card may send actions: the user can open the activity, and the teacher has not
     * set it to open its page. The handler's process_action() still checks the rest.
     *
     * @return bool
     */
    final public function accepts_actions(): bool {
        if (!$this->cm->uservisible || !$this->cm->available) {
            return false;
        }
        $mode = $this->settings->inlinemode ?? cm_settings::INLINE_AUTO;
        return $mode === cm_settings::INLINE_INLINE || ($mode === cm_settings::INLINE_AUTO && static::default_inline());
    }

    /**
     * The card state for the current user.
     *
     * @return string one of the STATE_ constants
     */
    final public function get_state(): string {
        if (!$this->cm->uservisible || !$this->cm->available) {
            return self::STATE_LOCKED;
        }
        return $this->is_done() ? self::STATE_DONE : self::STATE_AVAILABLE;
    }

    /**
     * Render the inside of the card for its state: the inline interaction while available, the
     * result once done.
     *
     * @param renderer_base $output
     * @return string HTML, or '' for none
     */
    final public function render(renderer_base $output): string {
        $state = $this->get_state();
        if ($state === self::STATE_LOCKED) {
            return '';
        }
        $view = null;
        if ($state === self::STATE_DONE && $this->accepts_actions()) {
            $view = $this->export_done($output);
        }
        if ($view === null && $state === self::STATE_AVAILABLE && $this->is_inline()) {
            $view = $this->export_available($output);
        }
        if ($view === null) {
            return '';
        }
        $context = (array)$view['context'];
        $context['cmid'] = $this->cm->id;
        return $output->render_from_template($view['template'], $context);
    }

    /**
     * The activity.
     *
     * @return cm_info
     */
    public function get_cm(): cm_info {
        return $this->cm;
    }

    /**
     * Whether the current user has completed an activity (complete or passed).
     *
     * @param cm_info $cm
     * @return bool false when completion is not tracked for it
     */
    public static function is_complete(cm_info $cm): bool {
        global $USER;
        $completion = new completion_info($cm->get_course());
        if (!$completion->is_enabled($cm)) {
            return false;
        }
        $state = (int)$completion->get_data($cm, false, $USER->id)->completionstate;
        return in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
    }

    /**
     * Badges with the current user's visible grade for an activity.
     *
     * @param cm_info $cm
     * @return array[]
     */
    public static function grade_badges(cm_info $cm): array {
        global $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        if (!plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)) {
            return [];
        }
        $grades = grade_get_grades($cm->course, 'mod', $cm->modname, $cm->instance, $USER->id);
        $badges = [];
        foreach ($grades->items ?? [] as $item) {
            $grade = $item->grades[$USER->id] ?? null;
            if (!$grade || $grade->grade === null || !empty($grade->hidden) || !empty($item->hidden)) {
                continue;
            }
            $badges[] = [
                'text' => get_string('badgegrade', 'format_spiffy', $grade->str_long_grade ?? $grade->str_grade),
                'type' => 'success',
            ];
        }
        return $badges;
    }
}
