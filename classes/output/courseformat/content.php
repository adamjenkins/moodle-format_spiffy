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

namespace format_spiffy\output\courseformat;

use cm_info;
use completion_info;
use core_courseformat\output\local\content as content_base;
use format_spiffy;
use format_spiffy\local\cm_settings;
use format_spiffy\local\inline\handler;
use format_spiffy\local\inline\registry;
use format_spiffy\local\spotlight;
use format_spiffy\local\teacher;
use format_spiffy\local\tools;
use renderer_base;
use section_info;
use stdClass;

/**
 * Course content for the Spiffy format.
 *
 * Outside editing mode each section is shown as a block: an optional label, the section name and
 * summary, then its activities. Activities with a page are step cards; text and media items are
 * notes. Steps that unlock one another are numbered, so the numbers show the order they must be
 * done in. Each step is locked, available or done; activity types with an inline handler are done
 * inside the card. Subsections (mod_subsection) are smaller islands inside their section, with
 * their own steps. All activity and section details still come from the core output classes, so dates,
 * completion, restrictions and visibility badges are the ones core shows. In editing mode, and on
 * a single-section page, the core layout and its editor are used unchanged.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content extends content_base {
    /** @var int How many accents "auto" cycles through (green, amber, coral). */
    private const AUTO_ACCENTS = 3;

    /** @var completion_info|null The course's completion info, once needed. */
    private ?completion_info $completion = null;

    /** @var bool Whether completion is tracked for the current user in this course. */
    private bool $trackeduser = false;

    /**
     * Whether the card layout is used for this page: outside editing mode, on the course page
     * and on a section's own page (course/section.php) alike.
     *
     * @return bool
     */
    public function is_card_view(): bool {
        return !$this->format->show_editor();
    }

    #[\Override]
    public function get_template_name(\renderer_base $renderer): string {
        if ($this->is_card_view()) {
            return 'format_spiffy/local/content';
        }
        return parent::get_template_name($renderer);
    }

    #[\Override]
    public function export_for_template(renderer_base $output) {
        $data = parent::export_for_template($output);
        if (!$this->is_card_view()) {
            return $data;
        }

        /** @var format_spiffy $format */
        $format = $this->format;
        $course = $format->get_course();
        $modinfo = $format->get_modinfo();
        $columns = $format->get_columns();
        $settings = cm_settings::get_for_course($course->id);

        // On a section's page the core export moves the one section to singlesection.
        $exported = isset($data->singlesection) ? [$data->singlesection] : $data->sections;
        $sections = [];
        foreach ($exported as $section) {
            $sectioninfo = $modinfo->get_section_info_by_id($section->id);
            $this->add_section_data($section, $sectioninfo, $settings, $columns, $output);
            $section->hasspiffycontent = $section->spiffytitle !== '' || $section->spiffyowneyebrow
                || !empty($section->summary->summarytext) || $section->hasspiffyitems
                || !empty($section->hasavailability) || !empty($section->ishidden);
            if ($section->hasspiffycontent) {
                $sections[] = $section;
            }
        }

        $data->spiffysections = $sections;
        $data->columns = $columns;
        $data->courseid = (int)$course->id;
        $data->spiffysectionid = (int)($format->get_sectionid() ?? 0);
        $data->spiffycardstyle = $format->get_display_option('cardstyle');
        $data->spiffyarrows = $format->get_display_option('arrows');
        $data->spiffycelebrate = $format->feature_enabled('celebrate');
        $data->spiffyspotlight = spotlight::export_page($format, $data, $output);
        $data->spiffyteacher = teacher::export_page($format, $data, $output);

        // Each section is rendered once here, so the page and the web services that refresh it
        // (format_spiffy\local\sections) produce the same HTML, and the page knows its hashes.
        $hashes = [];
        foreach ($data->spiffysections as $section) {
            $section->spiffyhtml = $output->render_from_template('format_spiffy/local/section', $section);
            $section->spiffyhash = \format_spiffy\local\sections::hash($section->spiffyhtml);
            $hashes[$section->id] = $section->spiffyhash;
        }
        $data->spiffyhashes = json_encode((object)$hashes);
        return $data;
    }

    /**
     * Add the Spiffy fields to an exported section (or subsection): label, title, its items and
     * progress.
     *
     * @param stdClass $section the core section export, changed in place
     * @param section_info $sectioninfo
     * @param stdClass[] $settings activity settings keyed by course module id
     * @param int $columns number of columns
     * @param renderer_base $output
     * @param bool $nested whether this is a subsection inside another section
     * @return void
     */
    protected function add_section_data(
        stdClass $section,
        section_info $sectioninfo,
        array $settings,
        int $columns,
        renderer_base $output,
        bool $nested = false
    ): void {
        $context = \core\context\course::instance($this->format->get_courseid());
        $options = $this->format->get_format_options($sectioninfo);

        // The course's section label ("Section 1"; none for General and subsections), then the
        // section's own label: "Section 1 · Before we begin".
        $own = format_string($options['eyebrow'] ?? '', true, ['context' => $context]);
        $auto = (!$nested && $sectioninfo->section > 0 && !$sectioninfo->is_delegated()) ?
            $this->format->get_section_label((int)$sectioninfo->section) : '';
        $section->spiffyowneyebrow = $own !== '';
        $section->spiffyeyebrow = implode(' · ', array_filter([$auto, $own], fn($label) => $label !== ''));
        $section->spiffytitle = (!$nested && $sectioninfo->section == 0 && (string)$sectioninfo->name === '') ?
            '' : $section->sectionname;
        $section->spiffylocked = !$sectioninfo->uservisible;
        $section->spiffyitems = $this->export_items($section, $options['numbers'] ?? '', $settings, $columns, $output, $nested);
        $section->spiffyprogress = $this->export_progress($section->spiffyitems);
        $section->columns = $columns;
        $section->hasspiffyitems = !empty($section->spiffyitems);
        // The course setting "General section always open": no hide button, never compact.
        $section->spiffyalwaysopen = !$nested && $sectioninfo->section == 0
            && !empty($this->format->get_format_options()['generalopen']);
    }

    /**
     * Export a subsection (a mod_subsection delegated section) as a smaller island inside its
     * parent section, with its own steps, numbering and progress.
     *
     * The item gets a context of its own instead of core's activity export, so the templates of
     * the cards inside cannot pick up the subsection's fields (such as its rendered content).
     *
     * @param cm_info $cm the subsection activity
     * @param section_info $delegated its section
     * @param stdClass $cmformat core's export of the subsection activity
     * @param stdClass[] $settings activity settings keyed by course module id
     * @param int $columns number of columns
     * @param renderer_base $output
     * @return stdClass the item's cmformat
     */
    protected function export_subsection(
        cm_info $cm,
        section_info $delegated,
        stdClass $cmformat,
        array $settings,
        int $columns,
        renderer_base $output
    ): stdClass {
        $class = $this->format->get_output_classname('content\\delegatedsection');
        /** @var \core_courseformat\output\local\content\delegatedsection $sectionoutput */
        $sectionoutput = new $class($this->format, $delegated);
        $sub = $sectionoutput->export_for_template($output);
        $this->add_section_data($sub, $delegated, $settings, $columns, $output, true);
        return (object)[
            'cmid' => (int)$cm->id,
            'activityname' => $cmformat->activityname ?? '',
            'spiffystep' => false,
            'spiffynote' => false,
            'spiffysubsection' => true,
            'spiffysub' => $sub,
            'spiffyfull' => true,
            'spiffylocked' => !$cm->uservisible,
            'spiffydimmed' => !empty($cmformat->spiffydimmed),
            'spiffytracked' => false,
            'spiffyrequires' => '',
            'spiffyprereqs' => '',
        ];
    }

    /**
     * Export the activities of one section as steps and notes.
     *
     * @param stdClass $section the exported section
     * @param string $numbers how steps are numbered (format_spiffy::NUMBERS_*)
     * @param stdClass[] $settings activity settings keyed by course module id
     * @param int $columns number of columns
     * @param renderer_base $output
     * @param bool $nested whether the section is a subsection (subsections are not nested further)
     * @return stdClass[] the items, each the core cm export plus the spiffy* fields
     */
    protected function export_items(
        stdClass $section,
        string $numbers,
        array $settings,
        int $columns,
        renderer_base $output,
        bool $nested = false
    ): array {
        $modinfo = $this->format->get_modinfo();
        $items = [];
        $steps = [];
        foreach ($section->cmlist->cms ?? [] as $position => $cm) {
            $item = $cm->cmitem;
            $cminfo = $modinfo->get_cm($item->id);
            $item->spiffycm = $cminfo;
            $item->spiffysettings = $settings[(int)$item->id] ?? cm_settings::defaults();
            $item->spiffydelegated = $nested ? null : $cminfo->get_delegated_section_info();
            $item->spiffystep = !$item->spiffydelegated && !empty($item->cmformat->spiffybutton);
            // The course setting "Activities on the card" off: every card opens the activity page.
            $item->spiffyhandler = $item->spiffystep && $this->format->feature_enabled('inline') ?
                registry::get_handler($cminfo, $item->spiffysettings) : null;
            $items[$position] = $item;
            if ($item->spiffystep) {
                $steps[(int)$item->id] = $item;
            }
        }

        $prerequisites = $this->get_prerequisites($steps);
        $sequenced = [];
        foreach ($prerequisites as $cmid => $required) {
            if ($required) {
                $sequenced[$cmid] = true;
                $sequenced += array_fill_keys($required, true);
            }
        }

        $number = 0;
        $stepindex = 0;
        foreach ($steps as $cmid => $item) {
            $isnumbered = $numbers === format_spiffy::NUMBERS_ALWAYS
                || ($numbers !== format_spiffy::NUMBERS_NEVER && isset($sequenced[$cmid]));
            $item->spiffynumber = $isnumbered ? ++$number : null;
            $item->spiffyaccent = $item->spiffysettings->accent !== 'auto' ?
                $item->spiffysettings->accent : format_spiffy::ACCENTS[$stepindex % self::AUTO_ACCENTS];
            $stepindex++;
        }

        $actionable = $this->get_actionable_prerequisites($steps, $prerequisites);
        $allcomplete = $this->all_steps_complete($steps);
        $laststep = $steps ? max(array_keys(array_filter($items, fn($item) => $item->spiffystep))) : -1;

        $result = [];
        foreach ($items as $position => $item) {
            if ($item->spiffydelegated) {
                $item->cmformat = $this->export_subsection(
                    $item->spiffycm,
                    $item->spiffydelegated,
                    $item->cmformat,
                    $settings,
                    $columns,
                    $output
                );
                unset($item->spiffycm, $item->spiffysettings, $item->spiffyhandler, $item->spiffydelegated);
                $result[] = $item;
                continue;
            }
            $format = $item->cmformat;
            $cminfo = $item->spiffycm;
            // Every item sets these, so a card inside a subsection never reads its parent's values.
            $format->spiffystep = false;
            $format->spiffynote = false;
            $format->spiffysub = false;
            $format->spiffytracked = false;
            $format->spiffyrequires = '';
            $format->spiffyprereqs = '';
            $width = $item->spiffysettings->width;
            if ($width === format_spiffy::WIDTH_AUTO) {
                $width = $item->spiffystep ? format_spiffy::WIDTH_COLUMN : format_spiffy::WIDTH_FULL;
            }
            $format->spiffyfull = $columns === 1 || $width === format_spiffy::WIDTH_FULL;
            $format->spiffylocked = !$cminfo->uservisible;

            if ($item->spiffystep) {
                $format->spiffystep = true;
                // The activity type's icon beside the number or tick, with its name for everyone, not only editors.
                $iconclass = $this->format->get_output_classname('content\\cm\\cmicon');
                $format->spiffyicon = ['showtooltip' => 1] + (new $iconclass($this->format, $cminfo))->export_for_template($output);
                $format->spiffyheadinglevel = $nested ? 4 : 3;
                $format->spiffynumber = $item->spiffynumber;
                $format->spiffyaccent = $item->spiffyaccent;
                $format->spiffytitle = $item->spiffynumber ?
                    get_string('steptitle', 'format_spiffy', (object)['number' => $item->spiffynumber,
                        'name' => $format->activityname]) :
                    $format->activityname;
                $format->spiffybuttonlabel = $item->spiffysettings->buttonlabel !== '' ?
                    format_string($item->spiffysettings->buttonlabel, true, ['context' => $cminfo->context]) :
                    get_string('startactivity', 'format_spiffy', $format->activityname);
                $format->spiffyunlockhint = $this->format->feature_enabled('unlockhints') ?
                    $this->unlock_hint((int)$item->id, $steps, $prerequisites) : '';
                // The steps to do first, for the pointing hint on a locked card: following the chain
                // to the steps this user can open, not a prerequisite that is itself locked.
                $first = $cminfo->available ? [] : $actionable[(int)$item->id];
                $format->spiffyrequires = implode(' ', $first);
                // Its own prerequisites, so the page can draw the whole chain back to those steps.
                $format->spiffyprereqs = $cminfo->available ? '' : implode(' ', $prerequisites[(int)$item->id] ?? []);
                $format->spiffyhinttext = $first ? get_string('dofirst', 'format_spiffy', implode(
                    ', ',
                    array_map(fn($id) => $steps[$id]->cmformat->spiffytitle ?? $steps[$id]->cmformat->activityname, $first)
                )) : '';
                $this->export_state($item, $output);
            } else {
                // Notes after the last step are for while the steps are under way.
                if ($allcomplete && $position > $laststep) {
                    continue;
                }
                $format->spiffynote = true;
            }
            unset($item->spiffycm, $item->spiffysettings, $item->spiffyhandler, $item->spiffydelegated);
            $result[] = $item;
        }
        return $result;
    }

    /**
     * Add the card state, the inline view, status badges and the card tools to a step.
     *
     * @param stdClass $item the exported item, with spiffycm and spiffyhandler
     * @param renderer_base $output
     * @return void
     */
    protected function export_state(stdClass $item, renderer_base $output): void {
        /** @var cm_info $cminfo */
        $cminfo = $item->spiffycm;
        /** @var handler|null $handler */
        $handler = $item->spiffyhandler;
        $format = $item->cmformat;

        if ($handler) {
            $state = $handler->get_state();
            $format->spiffyinlinehtml = $handler->render($output);
            $badges = $handler->get_badges();
        } else {
            $state = !$cminfo->uservisible || !$cminfo->available ? handler::STATE_LOCKED :
                (handler::is_complete($cminfo) ? handler::STATE_DONE : handler::STATE_AVAILABLE);
            $format->spiffyinlinehtml = '';
            $badges = $state === handler::STATE_LOCKED ? [] : handler::grade_badges($cminfo);
        }
        $format->spiffystate = $state;
        $format->spiffydone = $state === handler::STATE_DONE;
        $format->spiffytracked = $this->is_tracked($cminfo);
        $format->spiffyhasinline = $format->spiffyinlinehtml !== '';
        $format->spiffystatusbadges = $badges;
        // The course setting "Mark as done on cards" off: students mark it on the activity page.
        if (!empty($format->completion) && !$this->format->feature_enabled('markdone')) {
            $format->completion->showmanualcompletion = false;
        }
        // Plain text, for language strings printed with {{ }}: activityname is already escaped.
        $format->spiffyplainname = $cminfo->get_formatted_name(['escape' => false]);
        $format->spiffytools = tools::export_card($cminfo, $state, $output);
        $format->spiffyteacher = teacher::export_card($cminfo, $output);
    }

    /**
     * Whether completion of an activity is tracked for the current user.
     *
     * @param cm_info $cm
     * @return bool
     */
    protected function is_tracked(cm_info $cm): bool {
        global $USER;
        if ($this->completion === null) {
            $this->completion = new completion_info($this->format->get_course());
            $this->trackeduser = $this->completion->is_enabled() && $this->completion->is_tracked_user($USER->id);
        }
        return $this->trackeduser && $this->completion->is_enabled($cm) != COMPLETION_TRACKING_NONE;
    }

    /**
     * Progress of the current user through the steps of a section, its subsections included.
     *
     * @param stdClass[] $items the exported items
     * @return array ['done', 'total', 'percent', 'complete', 'hasprogress', 'label']
     */
    protected function export_progress(array $items): array {
        $total = 0;
        $done = 0;
        foreach ($items as $item) {
            $format = $item->cmformat;
            if (!empty($format->spiffysub)) {
                $total += $format->spiffysub->spiffyprogress['total'];
                $done += $format->spiffysub->spiffyprogress['done'];
                continue;
            }
            // Only what the current user's completion tracks: anything else could never be finished.
            if (empty($format->spiffystep) || !isset($format->spiffystate) || empty($format->spiffytracked)) {
                continue;
            }
            $total++;
            $done += $format->spiffydone ? 1 : 0;
        }
        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total ? (int)round($done / $total * 100) : 0,
            'complete' => $total > 0 && $done === $total,
            'hasprogress' => $total > 0,
            'label' => get_string('progresslabel', 'format_spiffy', ['done' => $done, 'total' => $total]),
        ];
    }

    /**
     * For each step, the steps to do first: its prerequisites, and for a prerequisite that is
     * locked too, that prerequisite's own, until steps the user can open are reached.
     *
     * @param stdClass[] $steps the steps keyed by course module id
     * @param int[][] $prerequisites from get_prerequisites()
     * @return int[][] course module id => course module ids the user can open
     */
    protected function get_actionable_prerequisites(array $steps, array $prerequisites): array {
        $result = [];
        foreach ($prerequisites as $cmid => $required) {
            $found = [];
            $seen = [$cmid => true];
            $queue = $required;
            while ($queue) {
                $id = array_shift($queue);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if ($steps[$id]->spiffycm->available || !$prerequisites[$id]) {
                    $found[] = $id;
                } else {
                    array_push($queue, ...$prerequisites[$id]);
                }
            }
            $result[$cmid] = $found;
        }
        return $result;
    }

    /**
     * For each step, the steps of the same section it requires to be completed first.
     *
     * @param stdClass[] $steps the steps keyed by course module id
     * @return int[][] course module id => required course module ids
     */
    protected function get_prerequisites(array $steps): array {
        $course = $this->format->get_course();
        $result = [];
        foreach ($steps as $cmid => $item) {
            $required = [];
            if (!empty($item->spiffycm->availability)) {
                $info = new \core_availability\info_module($item->spiffycm);
                $conditions = $info->get_availability_tree()->get_all_children(\availability_completion\condition::class);
                foreach ($conditions as $condition) {
                    // Resolves "previous activity with completion" too.
                    $requiredcmid = $condition->get_cmid($course, $cmid, null);
                    $expected = $condition->save()->e ?? COMPLETION_COMPLETE;
                    if (
                        $requiredcmid && $expected != COMPLETION_INCOMPLETE && $requiredcmid != $cmid
                            && isset($steps[$requiredcmid])
                    ) {
                        $required[] = (int)$requiredcmid;
                    }
                }
            }
            $result[$cmid] = array_values(array_unique($required));
        }
        return $result;
    }

    /**
     * The "complete this step to unlock" hint of a step, if a later step still waits for it.
     *
     * @param int $cmid the step
     * @param stdClass[] $steps the steps keyed by course module id, in page order
     * @param int[][] $prerequisites from get_prerequisites()
     * @return string the hint, or '' for none
     */
    protected function unlock_hint(int $cmid, array $steps, array $prerequisites): string {
        foreach ($prerequisites as $nextid => $required) {
            if (!in_array($cmid, $required, true) || $steps[$nextid]->spiffycm->available) {
                continue;
            }
            $next = $steps[$nextid];
            if ($next->spiffynumber) {
                return get_string('unlocknext', 'format_spiffy', $next->spiffynumber);
            }
            return get_string('unlocknextname', 'format_spiffy', $next->cmformat->activityname);
        }
        return '';
    }

    /**
     * Whether the current user has completed every step that tracks completion.
     *
     * @param stdClass[] $steps the steps keyed by course module id
     * @return bool false when no step tracks completion or the user is not tracked
     */
    protected function all_steps_complete(array $steps): bool {
        global $USER;
        $course = $this->format->get_course();
        $completion = new completion_info($course);
        if (!$completion->is_enabled() || !$completion->is_tracked_user($USER->id)) {
            return false;
        }
        $tracked = 0;
        foreach ($steps as $item) {
            /** @var cm_info $cm */
            $cm = $item->spiffycm;
            if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
                continue;
            }
            $tracked++;
            $state = $completion->get_data($cm, false, $USER->id)->completionstate;
            if (!in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return false;
            }
        }
        return $tracked > 0;
    }
}
