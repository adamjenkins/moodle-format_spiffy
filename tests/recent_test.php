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
use format_spiffy\external\card_viewed;
use format_spiffy\local\recent;

/**
 * Tests for "Recently viewed".
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\recent::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\external\card_viewed::class)]
final class recent_test extends advanced_testcase {
    /** @var \stdClass */
    private \stdClass $course;

    /** @var \stdClass[] pages, in the order they were made */
    private array $pages = [];

    /**
     * A Spiffy course with six pages and a student.
     *
     * @return \stdClass the student
     */
    private function make_course(): \stdClass {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'numsections' => 2,
            'enablecompletion' => 1]);
        for ($i = 1; $i <= 6; $i++) {
            $this->pages[$i] = $generator->create_module('page', ['course' => $this->course->id, 'section' => 1,
                'name' => "Page $i"]);
        }
        $student = $generator->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        return $student;
    }

    /**
     * The activity as the current user sees it.
     *
     * @param int $number
     * @return \cm_info
     */
    private function cm(int $number): \cm_info {
        get_fast_modinfo($this->course->id, 0, true);
        return get_fast_modinfo($this->course->id)->get_cm($this->pages[$number]->cmid);
    }

    /**
     * The course modules of the links, in order.
     *
     * @return int[]
     */
    private function exported(): array {
        \core_courseformat\base::reset_course_cache($this->course->id);
        get_fast_modinfo($this->course->id, 0, true);
        $export = recent::export(course_get_format($this->course->id));
        return $export ? array_column($export['items'], 'cmid') : [];
    }

    /**
     * The newest view comes first, viewing again moves a card up, and five are shown.
     */
    public function test_newest_first(): void {
        $this->make_course();
        $this->assertSame([], $this->exported());
        for ($i = 1; $i <= 6; $i++) {
            recent::record($this->cm($i), 1000 + $i);
        }
        recent::record($this->cm(3), 2000);
        $this->assertSame([
            (int)$this->pages[3]->cmid, (int)$this->pages[6]->cmid, (int)$this->pages[5]->cmid,
            (int)$this->pages[4]->cmid, (int)$this->pages[2]->cmid,
        ], $this->exported());
    }

    /**
     * Pages opened (the log) and cards put on stage (the preference) are merged by time.
     */
    public function test_merges_the_log_and_cards(): void {
        $this->preventResetByRollback();
        $this->make_course();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        \mod_page\event\course_module_viewed::create([
            'context' => \core\context\module::instance($this->pages[2]->cmid),
            'objectid' => $this->pages[2]->id,
        ])->trigger();

        recent::record($this->cm(1), time() - 100);
        recent::record($this->cm(3), time() + 100);
        $this->assertSame(
            [(int)$this->pages[3]->cmid, (int)$this->pages[2]->cmid, (int)$this->pages[1]->cmid],
            $this->exported()
        );
    }

    /**
     * Long names are cut to the limit, and the link keeps the full name for its tooltip.
     */
    public function test_truncates_names(): void {
        $this->make_course();
        $long = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id, 'section' => 1,
            'name' => 'Plan your observing night & more']);
        get_fast_modinfo($this->course->id, 0, true);
        recent::record(get_fast_modinfo($this->course->id)->get_cm($long->cmid));
        recent::record($this->cm(1), time() - 10);
        $items = recent::export(course_get_format($this->course->id))['items'];
        $this->assertSame('Plan your observing' . "\u{2026}", $items[0]['name']);
        $this->assertSame(recent::NAME_LENGTH, \core_text::strlen($items[0]['name']));
        $this->assertSame('Plan your observing night & more', $items[0]['fullname']);
        $this->assertSame('Page 1', $items[1]['name']);
        $this->assertStringEndsWith('#card-' . $long->cmid, $items[0]['url']);
    }

    /**
     * An activity the user can no longer see, or that was deleted, is left out.
     */
    public function test_leaves_out_what_cannot_be_seen(): void {
        $student = $this->make_course();
        recent::record($this->cm(1), 1001);
        recent::record($this->cm(2), 1002);
        recent::record($this->cm(3), 1003);
        $this->assertCount(3, $this->exported());
        set_coursemodule_visible($this->pages[2]->cmid, 0);
        $this->setAdminUser();
        \core_courseformat\formatactions::cm($this->course->id)->delete($this->pages[3]->cmid);
        $this->setUser($student);
        $this->assertSame([(int)$this->pages[1]->cmid], $this->exported());
    }

    /**
     * The links render, with every language string they need, in each place they appear.
     */
    public function test_renders(): void {
        global $PAGE;
        $this->make_course();
        recent::record($this->cm(1));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        $context = recent::export(course_get_format($this->course->id));
        $renderer = $PAGE->get_renderer('core');
        foreach (
            ['format_spiffy/local/recent' => $context,
                'format_spiffy/local/spotlight_toolbar' => ['hastoolbar' => true, 'recent' => $context],
                'format_spiffy/local/spotlight_whatsnext' => ['courseid' => 1, 'hasitems' => true, 'recent' => $context],
                'format_spiffy/local/teacher_spotlight' => ['isswitched' => false, 'recent' => $context]] as $template => $data
        ) {
            $html = $renderer->render_from_template($template, $data);
            $this->assertStringContainsString('data-spiffy-focus="' . $this->pages[1]->cmid . '"', $html, $template);
            $this->assertStringContainsString(get_string('recentlyviewed', 'format_spiffy'), $html, $template);
        }
    }

    /**
     * Each person has their own list, in each course.
     */
    public function test_own_list(): void {
        $this->make_course();
        recent::record($this->cm(1));
        $this->assertSame([(int)$this->pages[1]->cmid], $this->exported());
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        $this->assertSame([], $this->exported());
    }

    /**
     * The course switch turns it off, and edit mode has no stage to bring a card to.
     */
    public function test_off_switch_and_edit_mode(): void {
        $this->make_course();
        recent::record($this->cm(1));
        course_get_format($this->course->id)->update_course_format_options(['featurerecent' => 0]);
        $this->assertSame([], $this->exported());

        course_get_format($this->course->id)->update_course_format_options(['featurerecent' => 1]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        recent::record($this->cm(2));
        $this->assertSame([(int)$this->pages[2]->cmid], $this->exported());
        global $PAGE, $USER;
        $PAGE->set_context(\core\context\course::instance($this->course->id));
        $USER->editing = 1;
        $this->assertFalse(recent::export(course_get_format($this->course->id)));
        $USER->editing = 0;
    }

    /**
     * Guests share one account, so they get no list.
     */
    public function test_not_for_guests(): void {
        $this->make_course();
        $this->setGuestUser();
        $this->assertFalse(recent::export(course_get_format($this->course->id)));
    }

    /**
     * The links are inside the student's "What's next" and the teacher's attention block.
     */
    public function test_placed_inside_the_blocks(): void {
        global $PAGE;
        $this->make_course();
        recent::record($this->cm(1));
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        $format = course_get_format($this->course->id);
        $outputclass = $format->get_output_classname('content');
        $renderer = $PAGE->get_renderer('format_spiffy');

        $data = (new $outputclass($format))->export_for_template($renderer);
        $this->assertSame((int)$this->pages[1]->cmid, $data->spiffyspotlight['whatsnext']['recent']['items'][0]['cmid']);
        $this->assertFalse($data->spiffyspotlight['recent']);

        // With "What's next" off the links still show, in the toolbar.
        $format->update_course_format_options(['featurewhatsnext' => 0]);
        \core_courseformat\base::reset_course_cache($this->course->id);
        $format = course_get_format($this->course->id);
        $data = (new $outputclass($format))->export_for_template($renderer);
        $this->assertSame((int)$this->pages[1]->cmid, $data->spiffyspotlight['recent']['items'][0]['cmid']);
        $this->assertTrue($data->spiffyspotlight['hastoolbar']);

        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        recent::record($this->cm(2));
        $data = (new $outputclass(course_get_format($this->course->id)))->export_for_template($renderer);
        $this->assertSame((int)$this->pages[2]->cmid, $data->spiffyteacher['recent']['items'][0]['cmid']);
        $this->assertFalse($data->spiffyspotlight['recent']);
    }

    /**
     * The web service records a view, only for what the user can see.
     */
    public function test_web_service(): void {
        $this->make_course();
        card_viewed::execute($this->pages[1]->cmid);
        $this->assertSame([(int)$this->pages[1]->cmid], $this->exported());

        set_coursemodule_visible($this->pages[2]->cmid, 0);
        get_fast_modinfo($this->course->id, 0, true);
        try {
            card_viewed::execute($this->pages[2]->cmid);
        } catch (\moodle_exception $exception) {
            $this->assertInstanceOf(\require_login_exception::class, $exception);
        }
        $this->assertSame([(int)$this->pages[1]->cmid], $this->exported());
        $this->assertNull(card_viewed::execute_returns());
    }

    /**
     * Someone who isn't in the course can't record anything in it.
     */
    public function test_web_service_needs_course_access(): void {
        $this->make_course();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\require_login_exception::class);
        card_viewed::execute($this->pages[1]->cmid);
    }

    /**
     * Deleting a course removes everyone's list for it.
     */
    public function test_course_deleted(): void {
        global $DB, $USER;
        $this->make_course();
        recent::record($this->cm(1));
        $name = recent::PREFERENCE . $this->course->id;
        $this->assertTrue($DB->record_exists('user_preferences', ['name' => $name, 'userid' => $USER->id]));
        $this->setAdminUser();
        delete_course($this->course, false);
        $this->assertFalse($DB->record_exists('user_preferences', ['name' => $name]));
    }
}
