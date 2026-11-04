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

namespace format_spiffy;

use advanced_testcase;
use availability_completion\condition;
use format_spiffy\local\sequential;

/**
 * Tests for the "Force sequential" section tool.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\sequential::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\output\courseformat\content\section\controlmenu::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\output\courseformat\content\cm\delegatedcontrolmenu::class)]
final class sequential_test extends advanced_testcase {
    /**
     * The availability of a course module, decoded.
     *
     * @param int $cmid
     * @return \stdClass|null
     */
    private function availability(int $cmid): ?\stdClass {
        global $DB;
        $json = $DB->get_field('course_modules', 'availability', ['id' => $cmid]);
        return $json ? json_decode($json) : null;
    }

    /**
     * Every activity after the first gets "previous activity with completion"; labels, the first
     * activity and activities that already have it are left alone, other restrictions are kept.
     * Remove sequential takes the condition off again.
     */
    public function test_apply(): void {
        global $CFG, $PAGE, $USER;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $manual = ['course' => $course->id, 'section' => 1, 'completion' => COMPLETION_TRACKING_MANUAL];
        $label = $generator->create_module('label', ['course' => $course->id, 'section' => 1, 'intro' => 'Read this first']);
        $first = $generator->create_module('page', $manual + ['name' => 'First']);
        $date = json_encode(['op' => '&', 'showc' => [false], 'c' => [['type' => 'date', 'd' => '>=', 't' => 1893456000]]]);
        $dated = $generator->create_module('page', $manual + ['name' => 'Dated', 'availability' => $date]);
        $already = $generator->create_module('page', $manual + ['name' => 'Already',
            'availability' => sequential::with_previous(null)]);
        $sub = $generator->create_module('subsection', ['course' => $course->id, 'section' => 1, 'name' => 'More']);
        $modinfo = get_fast_modinfo($course);
        $delegated = $modinfo->get_cm($sub->cmid)->get_delegated_section_info();
        $inner1 = $generator->create_module('page', ['name' => 'Inner 1', 'section' => $delegated->section] + $manual);
        $inner2 = $generator->create_module('page', ['name' => 'Inner 2', 'section' => $delegated->section] + $manual);

        $changed = sequential::apply(get_fast_modinfo($course)->get_section_info(1));
        sort($changed);
        $this->assertSame([(int)$dated->cmid, (int)$sub->cmid], $changed);
        $this->assertNull($this->availability($label->cmid));
        $this->assertNull($this->availability($first->cmid));
        $tree = $this->availability($dated->cmid);
        $this->assertSame('&', $tree->op);
        $this->assertSame('date', $tree->c[0]->type);
        $this->assertSame(condition::OPTION_PREVIOUS, $tree->c[1]->cm);
        $this->assertSame([false, true], $tree->showc);
        $this->assertCount(1, $this->availability($already->cmid)->c);
        $this->assertNull($this->availability($inner2->cmid));
        // Core resolves "previous" to the activity before it.
        $cm = get_fast_modinfo($course)->get_cm($dated->cmid);
        $info = new \core_availability\info_module($cm);
        $condition = $info->get_availability_tree()->get_all_children(condition::class)[0];
        $this->assertEquals($first->cmid, $condition->get_cmid($course, $dated->cmid, null));

        // Once is enough; the subsection on its own.
        $this->assertSame([], sequential::apply(get_fast_modinfo($course)->get_section_info(1)));
        $subsection = get_fast_modinfo($course)->get_section_info_by_id($delegated->id);
        $this->assertSame([(int)$inner2->cmid], sequential::apply($subsection));
        $this->assertNull($this->availability($inner1->cmid));

        // Keep "any of" restrictions whole next to the new condition.
        $or = json_encode(['op' => '|', 'show' => false, 'c' => [['type' => 'date', 'd' => '>=', 't' => 1]]]);
        $tree = json_decode(sequential::with_previous($or));
        $this->assertSame('&', $tree->op);
        $this->assertSame('|', $tree->c[0]->op);
        $this->assertObjectNotHasProperty('show', $tree->c[0]);
        $this->assertSame([false, false], $tree->showc);

        // Undo: only the condition goes, the other restrictions stay as they were.
        $this->assertSame([false, true], sequential::state(get_fast_modinfo($course)->get_section_info(1)));
        $removed = sequential::remove(get_fast_modinfo($course)->get_section_info(1));
        sort($removed);
        $this->assertSame([(int)$dated->cmid, (int)$already->cmid, (int)$sub->cmid], $removed);
        $this->assertEquals(json_decode($date), $this->availability($dated->cmid));
        $this->assertNull($this->availability($already->cmid));
        $this->assertNull($this->availability($sub->cmid));
        $this->assertNull($this->availability($first->cmid));
        $this->assertSame(condition::OPTION_PREVIOUS, $this->availability($inner2->cmid)->c[0]->cm);
        $this->assertSame([true, false], sequential::state(get_fast_modinfo($course)->get_section_info(1)));
        $this->assertSame([], sequential::remove(get_fast_modinfo($course)->get_section_info(1)));
        $this->assertEquals(json_decode($or), json_decode(sequential::without_previous(sequential::with_previous($or))));
        $this->assertNull(sequential::without_previous($date));
        $this->assertNull(sequential::without_previous(null));

        // The edit menus offer what would change something, before Delete, with a confirmation.
        $PAGE->set_course($course);
        $USER->editing = 1;
        $format = course_get_format($course);
        $class = $format->get_output_classname('content\\section\\controlmenu');
        $menu = new $class($format, get_fast_modinfo($course)->get_section_info(1));
        $items = $menu->section_control_items();
        $this->assertArrayHasKey('spiffysequential', $items);
        $this->assertArrayNotHasKey('spiffyunsequential', $items);
        $this->assertSame(array_search('delete', array_keys($items)) - 1, array_search('spiffysequential', array_keys($items)));
        $this->assertSame('confirmation', $items['spiffysequential']->attributes['data-modal']);
        $this->assertStringContainsString('confirm=1', $items['spiffysequential']->attributes['data-modal-destination']);
        $this->assertStringNotContainsString('confirm', $items['spiffysequential']->url->out(false));
        $class = $format->get_output_classname('content\\cm\\delegatedcontrolmenu');
        $menu = new $class(
            $format,
            get_fast_modinfo($course)->get_section_info_by_id($delegated->id),
            get_fast_modinfo($course)->get_cm($sub->cmid)
        );
        $items = $menu->delegated_control_items();
        $this->assertArrayNotHasKey('spiffysequential', $items);
        $this->assertArrayHasKey('spiffyunsequential', $items);

        // Not for a user who cannot manage activities.
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $this->assertSame([], sequential::menu_items($format, get_fast_modinfo($course)->get_section_info(1)));
    }

    /**
     * Without an earlier activity that tracks completion, nothing is locked for good.
     */
    public function test_apply_skips_without_tracked_activity(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $first = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $second = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $third = $generator->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $fourth = $generator->create_module('page', ['course' => $course->id, 'section' => 1]);

        $section = get_fast_modinfo($course)->get_section_info(1);
        $this->assertSame([(int)$fourth->cmid], sequential::apply($section));
        $this->assertNull($this->availability($second->cmid));
        $this->assertNull($this->availability($third->cmid));
    }

    /**
     * A module-level prohibit keeps that activity out of Force and Remove sequential.
     */
    public function test_module_level_prohibit(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $manual = ['course' => $course->id, 'section' => 1, 'completion' => COMPLETION_TRACKING_MANUAL];
        $generator->create_module('page', $manual);
        $forbidden = $generator->create_module('page', $manual);
        $allowed = $generator->create_module('page', $manual);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $role, \core\context\module::instance($forbidden->cmid));
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($teacher);

        $section = get_fast_modinfo($course)->get_section_info(1);
        $this->assertSame([(int)$allowed->cmid], sequential::apply($section));
    }
}
