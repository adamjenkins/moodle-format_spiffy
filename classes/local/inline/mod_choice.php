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

/**
 * Choice on a card: answer with one click, see the results, change the answer if allowed.
 *
 * Writes go through mod_choice's own external functions, so its capability, date, limit and
 * "allow update" checks apply unchanged. The views use mod_choice's lib functions instead: an
 * external function resets the page output, so it cannot be called while the page renders.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_choice extends handler {
    /** @var \stdClass|null The choice record, loaded once. */
    protected ?\stdClass $choice = null;

    /**
     * The choice record.
     *
     * @return \stdClass
     */
    protected function get_choice(): \stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        if ($this->choice === null) {
            $this->choice = choice_get_choice($this->cm->instance);
        }
        return $this->choice;
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!has_capability('mod/choice:choose', $this->cm->context)) {
            return false;
        }
        [$open] = choice_get_availability_status($this->get_choice());
        return $open || $this->has_answer();
    }

    /**
     * Whether the current user has answered.
     *
     * @return bool
     */
    protected function has_answer(): bool {
        return (bool)choice_get_my_response($this->get_choice());
    }

    #[\Override]
    public function is_done(): bool {
        return parent::is_done() || $this->has_answer();
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        global $USER;
        $choice = $this->get_choice();
        $allresponses = choice_get_response_data(
            $choice,
            $this->cm,
            groups_get_activity_groupmode($this->cm),
            empty($choice->includeinactive)
        );
        $prepared = choice_prepare_options($choice, $USER, $this->cm, $allresponses);
        $options = [];
        foreach ($prepared['options'] as $option) {
            $options[] = [
                'id' => (int)$option->attributes->value,
                'text' => format_string($option->text, true, ['context' => $this->cm->context]),
                'disabled' => !empty($option->attributes->disabled),
                'checked' => !empty($option->attributes->checked),
            ];
        }
        if (!$options) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/choice', 'context' => [
            'options' => $options,
            'multiple' => !empty($choice->allowmultiple),
            'name' => format_string($choice->name, true, ['context' => $this->cm->context]),
        ]];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        $choice = $this->get_choice();
        $context = $this->cm->context;
        $current = choice_get_my_response($choice);
        $mine = [];
        foreach ($current as $answer) {
            $mine[(int)$answer->optionid] = format_string(
                choice_get_option_text($choice, $answer->optionid),
                true,
                ['context' => $context]
            );
        }

        $options = [];
        if (has_capability('mod/choice:readresponses', $context) || choice_can_view_results($choice, $current)) {
            // As mod_choice_external::get_choice_results(), which cannot be called while the page renders.
            $groupmode = groups_get_activity_groupmode($this->cm);
            $groupid = $groupmode ? groups_get_activity_group($this->cm) : 0;
            $users = choice_get_response_data($choice, $this->cm, $groupmode, empty($choice->includeinactive), $groupid);
            $results = prepare_choice_show_results($choice, $this->cm->get_course(), $this->cm, $users);
            foreach ($results->options as $optionid => $option) {
                if (!$optionid) {
                    continue;
                }
                $count = property_exists($option, 'user') ? count($option->user) : 0;
                $options[] = [
                    'id' => (int)$optionid,
                    'text' => format_string($option->text, true, ['context' => $context]),
                    'mine' => isset($mine[(int)$optionid]),
                    'count' => $count,
                    'percent' => $results->numberofuser ? round($count / $results->numberofuser * 100) : 0,
                ];
            }
        }
        [$open] = choice_get_availability_status($choice);
        $canchange = !empty($choice->allowupdate) && has_capability('mod/choice:choose', $context)
            && (empty($choice->timeclose) || $choice->timeclose > time());
        return ['template' => 'format_spiffy/inline/choice_done', 'context' => [
            'answers' => array_values($mine),
            'hasanswers' => !empty($mine),
            'showresults' => !empty($options),
            'options' => $options,
            'canchange' => $canchange && !empty($mine),
        ]];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        $choice = $this->get_choice();
        switch ($action) {
            case 'choose':
                $responses = array_map('intval', (array)($data['option'] ?? []));
                $responses = array_values(array_filter($responses));
                if (!$responses) {
                    throw new \moodle_exception('atleastoneoption', 'choice');
                }
                \mod_choice_external::submit_choice_response($choice->id, $responses);
                return get_string('choicesaved', 'choice');
            case 'remove':
                \mod_choice_external::delete_choice_responses($choice->id, []);
                return get_string('choiceremoved', 'format_spiffy');
        }
        throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
    }
}
