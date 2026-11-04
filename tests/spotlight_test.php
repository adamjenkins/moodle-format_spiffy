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
use completion_info;
use core_availability\tree;
use format_spiffy\local\inline\handler;
use format_spiffy\local\spotlight;

/**
 * Tests for spotlight mode: view mode, the stage, "What's next" and subsection islands.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\spotlight::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\output\courseformat\content::class)]
final class spotlight_test extends advanced_testcase {
    /**
     * Export the course page content as the current user.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private function export_content(\stdClass $course): \stdClass {
        global $PAGE;
        \core_courseformat\base::reset_course_cache($course->id);
        get_fast_modinfo($course->id, 0, true);
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $format = course_get_format($course->id);
        $renderer = $PAGE->get_renderer('format_spiffy');
        $outputclass = $format->get_output_classname('content');
        $content = new $outputclass($format);
        return $content->export_for_template($renderer);
    }

    /**
     * Find an exported section by number.
     *
     * @param \stdClass $data the content export
     * @param int $num section number
     * @return \stdClass
     */
    private function section(\stdClass $data, int $num): \stdClass {
        foreach ($data->spiffysections as $section) {
            if ((int)$section->num === $num) {
                return $section;
            }
        }
        $this->fail("Section $num not exported");
    }

    /**
     * A course in the Spiffy format with completion.
     *
     * @param array $options extra course fields
     * @return \stdClass
     */
    private function create_course(array $options = []): \stdClass {
        global $CFG;
        $CFG->enablecompletion = 1;
        $CFG->enableavailability = 1;
        return $this->getDataGenerator()->create_course($options + [
            'format' => 'spiffy',
            'numsections' => 2,
            'enablecompletion' => 1,
            'showactivitydates' => 1,
            'summary' => '',
        ]);
    }

    /**
     * An activity with manual completion.
     *
     * @param \stdClass $course
     * @param int $section section number
     * @param string $name
     * @param array $extra more module fields
     * @param string $modname
     * @return \stdClass the module record, with cmid
     */
    private function create_step(
        \stdClass $course,
        int $section,
        string $name,
        array $extra = [],
        string $modname = 'wiki'
    ): \stdClass {
        return $this->getDataGenerator()->create_module($modname, $extra + [
            'course' => $course->id,
            'section' => $section,
            'name' => $name,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
    }

    /**
     * The view mode comes from the user preference, and defaults to spotlight.
     */
    public function test_viewmode(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertSame(spotlight::MODE_SPOTLIGHT, spotlight::get_viewmode());
        $page = $this->export_content($course)->spiffyspotlight;
        $this->assertSame('spotlight', $page['viewmode']);
        $this->assertTrue($page['isspotlight']);
        $this->assertFalse($page['islist']);

        set_user_preference(spotlight::PREFERENCE, spotlight::MODE_LIST);
        $page = $this->export_content($course)->spiffyspotlight;
        $this->assertSame('list', $page['viewmode']);
        $this->assertTrue($page['islist']);

        set_user_preference(spotlight::PREFERENCE, 'bogus');
        $this->assertSame(spotlight::MODE_SPOTLIGHT, spotlight::get_viewmode());
    }

    /**
     * The stage is the first open, unfinished step with tracked completion, and moves on once
     * that step is done; a step without completion does not hold it.
     */
    public function test_stage_follows_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->create_course();
        $untracked = $this->create_step($course, 0, 'Announcements', ['completion' => COMPLETION_TRACKING_NONE]);
        $first = $this->create_step($course, 1, 'Read the guide');
        $second = $this->create_step($course, 1, 'Say hello');
        $later = $this->create_step($course, 2, 'Later');
        $DB->set_field('course_modules', 'availability', json_encode(tree::get_root_json([
            \availability_completion\condition::get_json($first->cmid, COMPLETION_COMPLETE),
        ])), ['id' => $second->cmid]);
        rebuild_course_cache($course->id, true);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $data = $this->export_content($course);
        $this->assertSame((string)$first->cmid, $data->spiffyspotlight['stage']);

        $cards = [];
        foreach ($this->section($data, 0)->spiffyitems as $item) {
            $cards[(int)$item->id] = $item->cmformat;
        }
        $this->assertFalse($cards[$untracked->cmid]->spiffytracked);
        $this->assertStringContainsString('data-spiffy-tracked="1"', $this->section($data, 1)->spiffyhtml);
        $this->assertStringContainsString('data-region="spiffy-strip"', $this->section($data, 1)->spiffyhtml);
        $this->assertStringContainsString('href="#card-' . $first->cmid . '"', $this->section($data, 1)->spiffyhtml);

        $completion = new completion_info($course);
        $completion->update_state(get_fast_modinfo($course)->get_cm($first->cmid), COMPLETION_COMPLETE, $student->id);
        $data = $this->export_content($course);
        $this->assertSame((string)$second->cmid, $data->spiffyspotlight['stage']);

        // Once every tracked step is done, an untracked one is all that is left.
        foreach ([$second, $later] as $step) {
            $completion->update_state(get_fast_modinfo($course)->get_cm($step->cmid), COMPLETION_COMPLETE, $student->id);
        }
        $data = $this->export_content($course);
        $this->assertSame((string)$untracked->cmid, $data->spiffyspotlight['stage']);
    }

    /**
     * The open steps of the highlighted section share the stage.
     */
    public function test_stage_highlighted_section(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->create_course();
        $this->create_step($course, 1, 'First week');
        $a = $this->create_step($course, 2, 'Watch');
        $b = $this->create_step($course, 2, 'Discuss');
        $DB->set_field('course', 'marker', 2, ['id' => $course->id]);
        rebuild_course_cache($course->id, true);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $data = $this->export_content($course);
        $this->assertSame($a->cmid . ' ' . $b->cmid, $data->spiffyspotlight['stage']);
    }

    /**
     * get_stage() on plain step data.
     */
    public function test_get_stage(): void {
        $step = fn(int $cmid, string $state, bool $tracked, bool $current) => (object)[
            'cmid' => $cmid,
            'format' => (object)['spiffystate' => $state, 'spiffytracked' => $tracked],
            'sectionid' => 1,
            'sectionname' => 'S',
            'iscurrent' => $current,
        ];
        $this->assertSame([], spotlight::get_stage([]));
        $this->assertSame([], spotlight::get_stage([$step(1, handler::STATE_DONE, true, false)]));
        $this->assertSame([2], spotlight::get_stage([
            $step(1, handler::STATE_AVAILABLE, false, false),
            $step(2, handler::STATE_AVAILABLE, true, false),
            $step(3, handler::STATE_AVAILABLE, true, false),
        ]));
        $this->assertSame([4, 5], spotlight::get_stage([
            $step(3, handler::STATE_AVAILABLE, true, false),
            $step(4, handler::STATE_AVAILABLE, true, true),
            $step(6, handler::STATE_LOCKED, true, true),
            $step(5, handler::STATE_AVAILABLE, true, true),
        ]));
        $this->assertSame([1], spotlight::get_stage([$step(1, handler::STATE_AVAILABLE, false, false)]));
    }

    /**
     * "What's next" lists overdue and due-soon steps first, soonest first, then the other
     * unfinished tracked steps; teachers do not get it.
     */
    public function test_whats_next(): void {
        $this->resetAfterTest();
        $now = time();
        $course = $this->create_course();
        $later = $this->create_step($course, 1, 'Read the guide');
        $soon = $this->create_step($course, 1, 'Vote soon', ['timeclose' => $now + 2 * DAYSECS], 'choice');
        $overdue = $this->create_step($course, 2, 'Vote late', ['timeclose' => $now - DAYSECS], 'choice');
        // Untracked and not due for a month: a suggestion, not a to-do.
        $someday = $this->create_step($course, 2, 'Some day', ['timeclose' => $now + 30 * DAYSECS,
            'completion' => COMPLETION_TRACKING_NONE], 'choice');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $whatsnext = $this->export_content($course)->spiffyspotlight['whatsnext'];
        $this->assertTrue($whatsnext['hasitems']);
        $this->assertSame(3, $whatsnext['total']);
        $this->assertSame([$overdue->cmid, $soon->cmid, $later->cmid], array_column($whatsnext['main']['items'], 'cmid'));
        $this->assertSame('danger', $whatsnext['main']['items'][0]['badge']['type']);
        $this->assertTrue($whatsnext['main']['items'][0]['overdue']);
        $this->assertSame('warning', $whatsnext['main']['items'][1]['badge']['type']);
        $this->assertNotSame('', $whatsnext['main']['items'][1]['datetext']);
        $this->assertFalse($whatsnext['main']['items'][2]['badge']);
        $this->assertSame('', $whatsnext['main']['items'][2]['datetext']);
        $this->assertSame('', $whatsnext['more']);
                $this->assertSame([$someday->cmid], array_column($whatsnext['suggestions']['items'], 'cmid'));

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);
        $this->assertFalse($this->export_content($course)->spiffyspotlight['whatsnext']);
    }

    /**
     * "What's next" stops at MAX_ITEMS and says how many more there are.
     */
    public function test_whats_next_more(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        for ($i = 1; $i <= spotlight::MAX_ITEMS + 2; $i++) {
            $this->create_step($course, 1, "Step $i");
        }
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $whatsnext = $this->export_content($course)->spiffyspotlight['whatsnext'];
        $this->assertCount(spotlight::MAX_ITEMS, $whatsnext['main']['items']);
        $this->assertSame(spotlight::MAX_ITEMS + 2, $whatsnext['total']);
        $this->assertSame(get_string('whatsnextmore', 'format_spiffy', 2), $whatsnext['more']);
    }

    /**
     * A subsection is a nested island with its own steps, counted in its section's progress, and
     * its cards do not pick up the subsection activity's own content.
     */
    public function test_subsection_island(): void {
        $this->resetAfterTest();
        \core\plugininfo\mod::enable_plugin('subsection', 1);
        $course = $this->create_course();
        $outer = $this->create_step($course, 1, 'Before the extras');
        $subsection = $this->getDataGenerator()->create_module('subsection', [
            'course' => $course->id,
            'section' => 1,
            'name' => 'Extra practice',
        ]);
        $delegated = get_fast_modinfo($course)->get_cm($subsection->cmid)->get_delegated_section_info();
        $inner = $this->create_step($course, (int)$delegated->section, 'Practise more');

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);
        $data = $this->export_content($course);
        $section = $this->section($data, 1);

        $items = [];
        foreach ($section->spiffyitems as $item) {
            $items[(int)$item->id] = $item->cmformat;
        }
        $this->assertCount(2, $items);
        $this->assertTrue($items[$outer->cmid]->spiffystep);
        $this->assertSame(3, $items[$outer->cmid]->spiffyheadinglevel);
        $sub = $items[$subsection->cmid];
        $this->assertTrue($sub->spiffysubsection);
        $this->assertFalse($sub->spiffystep);
        $this->assertFalse(isset($sub->altcontent));
        $this->assertSame('Extra practice', $sub->spiffysub->spiffytitle);
        $this->assertSame((int)$delegated->id, (int)$sub->spiffysub->id);
        $this->assertCount(1, $sub->spiffysub->spiffyitems);
        $card = $sub->spiffysub->spiffyitems[0]->cmformat;
        $this->assertSame((int)$inner->cmid, (int)$sub->spiffysub->spiffyitems[0]->id);
        $this->assertTrue($card->spiffystep);
        $this->assertFalse($card->spiffysub);
        $this->assertSame(4, $card->spiffyheadinglevel);
        $this->assertSame(handler::STATE_AVAILABLE, $card->spiffystate);

        $this->assertSame(1, $sub->spiffysub->spiffyprogress['total']);
        $this->assertSame(2, $section->spiffyprogress['total']);

        $steps = spotlight::get_steps($data->spiffysections);
        $this->assertContains((int)$inner->cmid, array_column($steps, 'cmid'));
        $this->assertStringContainsString('data-spiffy-island="' . $delegated->id . '"', $section->spiffyhtml);
        $this->assertStringContainsString('spiffy-island--sub', $section->spiffyhtml);
        $this->assertStringContainsString('Practise more', $section->spiffyhtml);
    }

    /**
     * With nothing due, "What's next" suggests open activities, those not opened yet first, and
     * finished ones when there would be fewer than two items.
     */
    public function test_whats_next_never_empty(): void {
        $this->resetAfterTest();
        // The log store waits for the transaction to end, and PostgreSQL tests run inside one.
        $this->preventResetByRollback();
        $course = $this->create_course();
        $untracked = ['completion' => COMPLETION_TRACKING_NONE];
        $opened = $this->create_step($course, 1, 'Opened', $untracked);
        $unopened = $this->create_step($course, 1, 'Not opened', $untracked);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        \mod_wiki\event\course_module_viewed::create([
            'context' => \core\context\module::instance($opened->cmid),
            'objectid' => $opened->id,
        ])->trigger();

        $whatsnext = $this->export_content($course)->spiffyspotlight['whatsnext'];
        $this->assertTrue($whatsnext['hasitems']);
        $this->assertFalse($whatsnext['main']);
        $this->assertSame([$unopened->cmid, $opened->cmid], array_column($whatsnext['suggestions']['items'], 'cmid'));
    }

    /**
     * A course with nothing open and nothing finished has no "What's next" at all.
     */
    public function test_whats_next_empty_course(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->assertFalse($this->export_content($course)->spiffyspotlight['whatsnext']);
    }

    /**
     * On a section page, "What's next" still covers the course, and links out to other pages.
     */
    public function test_whats_next_on_section_page(): void {
        $this->resetAfterTest();
        $course = $this->create_course();
        $here = $this->create_step($course, 1, 'Here');
        $elsewhere = $this->create_step($course, 2, 'Elsewhere');
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $format = course_get_format($course);
        $steps = array_values(array_filter(
            \format_spiffy\local\spotlight::get_steps($this->export_content($course)->spiffysections),
            fn($step) => (int)$step->cmid === (int)$here->cmid
        ));
        $whatsnext = spotlight::export_whats_next($format, $steps, time());
        $this->assertEqualsCanonicalizing([$here->cmid, $elsewhere->cmid], array_column($whatsnext['main']['items'], 'cmid'));
        $byid = array_column($whatsnext['main']['items'], null, 'cmid');
        $this->assertTrue($byid[$here->cmid]['onpage']);
        $this->assertFalse($byid[$elsewhere->cmid]['onpage']);
        $this->assertStringContainsString('#card-' . $elsewhere->cmid, $byid[$elsewhere->cmid]['url']);
    }
}
