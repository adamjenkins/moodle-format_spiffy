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

use availability_completion\condition;
use core_availability\tree;
use section_info;
use stdClass;

/**
 * "Force sequential": give every activity of a section (or subsection) after its first one the
 * access restriction "previous activity with completion must be marked complete", in one go, and
 * "Remove sequential" to take it off again.
 *
 * Text and media (labels) are left alone: they have nothing to open, and core skips activities
 * without completion when it looks for "the previous activity". The first activity is left open
 * too, so the section does not start to wait for the section before it. An activity that already
 * waits for the previous one is not changed; any other restrictions are kept ("all of": the old
 * ones and the new one).
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sequential {
    /**
     * Whether the tool is offered: access restrictions are enabled on the site.
     *
     * @return bool
     */
    public static function is_available(): bool {
        global $CFG;
        return !empty($CFG->enableavailability);
    }

    /**
     * The "Force sequential" and "Remove sequential" items of a section's or subsection's edit
     * menu, for a user who may use them: "Force" while some activity after the first does not wait
     * for the previous one yet, "Remove" while some activity does. Both ask for confirmation first
     * (core/utility's confirmation modal; without JavaScript the endpoint asks).
     *
     * @param \core_courseformat\base $format
     * @param section_info $section
     * @return \core\output\action_menu\link_secondary[] keyed spiffysequential and spiffyunsequential
     */
    public static function menu_items(\core_courseformat\base $format, section_info $section): array {
        $context = \core\context\course::instance($section->course);
        if (!self::is_available() || !has_capability('moodle/course:manageactivities', $context)) {
            return [];
        }
        [$canforce, $canremove] = self::state($section);
        $name = get_section_name($section->course, $section);
        $items = [];
        if ($canforce) {
            $items['spiffysequential'] = self::menu_item($format, $section, 'apply', 'forcesequential', 't/locked', $name);
        }
        if ($canremove) {
            $items['spiffyunsequential'] = self::menu_item($format, $section, 'remove', 'unsequential', 't/unlock', $name);
        }
        return $items;
    }

    /**
     * One of the two menu items.
     *
     * @param \core_courseformat\base $format
     * @param section_info $section
     * @param string $action apply or remove
     * @param string $string the item's string; with "hint" and "confirm" appended, its title and question
     * @param string $icon
     * @param string $sectionname
     * @return \core\output\action_menu\link_secondary
     */
    protected static function menu_item(
        \core_courseformat\base $format,
        section_info $section,
        string $action,
        string $string,
        string $icon,
        string $sectionname
    ): \core\output\action_menu\link_secondary {
        $params = [
            'id' => $section->id,
            'action' => $action,
            'sesskey' => sesskey(),
            'returnsection' => (int)($format->get_sectionid() ?? 0),
        ];
        $url = new \core\url('/course/format/spiffy/sequential.php', $params);
        $confirmed = new \core\url('/course/format/spiffy/sequential.php', $params + ['confirm' => 1]);
        return new \core\output\action_menu\link_secondary(
            url: $url,
            icon: new \core\output\pix_icon($icon, ''),
            text: get_string($string, 'format_spiffy'),
            attributes: [
                'class' => 'spiffy-' . $string,
                'title' => get_string($string . 'hint', 'format_spiffy'),
                'data-modal' => 'confirmation',
                'data-modal-title' => get_string($string, 'format_spiffy'),
                'data-modal-content' => get_string($string . 'confirm', 'format_spiffy', $sectionname),
                'data-modal-yes-button' => get_string($string, 'format_spiffy'),
                'data-modal-destination' => $confirmed->out(false),
            ],
        );
    }

    /**
     * What the tools can do in a section.
     *
     * @param section_info $section
     * @return bool[] whether Force sequential would change something, whether Remove sequential would
     */
    public static function state(section_info $section): array {
        $canforce = false;
        $canremove = false;
        foreach (self::steps($section) as $index => $cm) {
            $has = self::without_previous($cm->availability) !== null;
            $canremove = $canremove || $has;
            $canforce = $canforce || ($index > 0 && !$has);
        }
        return [$canforce, $canremove];
    }

    /**
     * The activities of a section that take part in the sequence, in order.
     *
     * @param section_info $section
     * @return \cm_info[] indexed from 0
     */
    protected static function steps(section_info $section): array {
        $steps = [];
        foreach ($section->get_sequence_cm_infos() as $cm) {
            if (!$cm->deletioninprogress && self::is_step($cm)) {
                $steps[] = $cm;
            }
        }
        return $steps;
    }

    /**
     * Add the restriction to the section's activities.
     *
     * @param section_info $section
     * @return int[] ids of the course modules that were changed
     */
    public static function apply(section_info $section): array {
        $changes = [];
        $tracked = self::tracked_before($section);
        foreach (self::steps($section) as $index => $cm) {
            // Without an earlier activity that tracks completion, the condition could never be met.
            if ($index === 0 || !in_array((int)$cm->id, $tracked, true) || !self::can_edit($cm)) {
                continue;
            }
            $json = self::with_previous($cm->availability);
            if ($json !== null) {
                $changes[$cm->id] = $json;
            }
        }
        return self::save($section, $changes);
    }

    /**
     * Undo Force sequential: take "previous activity with completion" off the section's activities,
     * keeping their other restrictions. It also removes the condition where a teacher added it by hand.
     *
     * @param section_info $section
     * @return int[] ids of the course modules that were changed
     */
    public static function remove(section_info $section): array {
        $changes = [];
        foreach (self::steps($section) as $cm) {
            if (!self::can_edit($cm)) {
                continue;
            }
            $json = self::without_previous($cm->availability);
            if ($json !== null) {
                $changes[$cm->id] = $json === '' ? null : $json;
            }
        }
        return self::save($section, $changes);
    }

    /**
     * Whether the current user may change this activity: a module-level override can forbid it.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected static function can_edit(\cm_info $cm): bool {
        return has_capability('moodle/course:manageactivities', $cm->context);
    }

    /**
     * The section's activities that have an earlier activity with completion, in course order.
     *
     * @param section_info $section
     * @return int[] course module ids
     */
    protected static function tracked_before(section_info $section): array {
        $modinfo = get_fast_modinfo($section->course);
        $completion = new \completion_info($modinfo->get_course());
        $seen = false;
        $result = [];
        foreach ($modinfo->get_section_info_all() as $info) {
            foreach ($info->get_sequence_cm_infos() as $cm) {
                if ((int)$info->id === (int)$section->id && $seen) {
                    $result[] = (int)$cm->id;
                }
                $seen = $seen || $completion->is_enabled($cm) != COMPLETION_TRACKING_NONE;
            }
        }
        return $result;
    }

    /**
     * Store new availability JSON, then rebuild the course cache and log the updates.
     *
     * @param section_info $section
     * @param array $changes availability JSON (null for none) by course module id
     * @return int[] ids of the course modules that were changed
     */
    protected static function save(section_info $section, array $changes): array {
        global $DB;
        foreach ($changes as $cmid => $json) {
            $DB->set_field('course_modules', 'availability', $json, ['id' => $cmid]);
        }
        if ($changes) {
            $courseid = (int)$section->course;
            rebuild_course_cache($courseid, true);
            $modinfo = get_fast_modinfo($courseid);
            foreach (array_keys($changes) as $cmid) {
                \core\event\course_module_updated::create_from_cm($modinfo->get_cm($cmid))->trigger();
            }
        }
        return array_map('intval', array_keys($changes));
    }

    /**
     * Whether an activity takes part in the sequence: anything with something to open, and subsections.
     *
     * @param \cm_info $cm
     * @return bool
     */
    protected static function is_step(\cm_info $cm): bool {
        return $cm->modname === 'subsection' || $cm->has_view();
    }

    /**
     * An activity's availability JSON with "previous activity with completion" added.
     *
     * @param string|null $availability the current JSON, null or empty for none
     * @return string|null the new JSON, or null if it already has the condition
     */
    public static function with_previous(?string $availability): ?string {
        $condition = condition::get_json(condition::OPTION_PREVIOUS, COMPLETION_COMPLETE);
        $tree = $availability ? json_decode($availability) : null;
        if (!$tree instanceof stdClass || empty($tree->c)) {
            return json_encode(tree::get_root_json([$condition]));
        }
        if (($tree->op ?? '') === tree::OP_AND) {
            foreach ($tree->c as $child) {
                if (self::is_previous($child)) {
                    return null;
                }
            }
            $tree->c[] = $condition;
            if (isset($tree->showc)) {
                $tree->showc[] = true;
            }
            return json_encode($tree);
        }
        // Keep an "any of" or negated tree whole, next to the new condition.
        $show = !isset($tree->show) || $tree->show;
        unset($tree->show, $tree->showc);
        return json_encode(tree::get_root_json([$tree, $condition], tree::OP_AND, $show));
    }

    /**
     * An activity's availability JSON with "previous activity with completion" taken off the top
     * level, the reverse of with_previous(): an "all of" left with a single nested tree becomes
     * that tree again.
     *
     * @param string|null $availability the current JSON, null or empty for none
     * @return string|null the new JSON ('' for no restrictions left), or null if it has no such condition
     */
    public static function without_previous(?string $availability): ?string {
        $tree = $availability ? json_decode($availability) : null;
        if (!$tree instanceof stdClass || ($tree->op ?? '') !== tree::OP_AND || empty($tree->c)) {
            return null;
        }
        $children = [];
        $showc = [];
        foreach ($tree->c as $index => $child) {
            if (!self::is_previous($child)) {
                $children[] = $child;
                $showc[] = $tree->showc[$index] ?? true;
            }
        }
        if (count($children) === count($tree->c)) {
            return null;
        }
        if (!$children) {
            return '';
        }
        if (count($children) === 1 && isset($children[0]->op)) {
            // The tree with_previous() wrapped: it is the root again.
            $inner = $children[0];
            if (in_array($inner->op, [tree::OP_AND, tree::OP_NOT_OR])) {
                $inner->showc = array_fill(0, count($inner->c), (bool)$showc[0]);
            } else {
                $inner->show = (bool)$showc[0];
            }
            return json_encode($inner);
        }
        $tree->c = $children;
        if (isset($tree->showc)) {
            $tree->showc = $showc;
        }
        return json_encode($tree);
    }

    /**
     * Whether a condition is "previous activity with completion must be complete".
     *
     * @param stdClass $condition
     * @return bool
     */
    protected static function is_previous(stdClass $condition): bool {
        return ($condition->type ?? '') === 'completion' && (int)($condition->cm ?? 0) === condition::OPTION_PREVIOUS
            && (int)($condition->e ?? 0) === COMPLETION_COMPLETE;
    }
}
