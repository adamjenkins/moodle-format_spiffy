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
use format_spiffy\local\cm_settings;
use format_spiffy\local\inline\handler;
use format_spiffy\local\inline\mod_page;

/**
 * Tests for Page on a card.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_page::class)]
final class inline_page_test extends advanced_testcase {
    /**
     * A Spiffy course with completion and a student logged in.
     *
     * @return \stdClass the course
     */
    private function setup_course(): \stdClass {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        return $course;
    }

    /**
     * The handler of a page, as the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param string $mode inline mode
     * @return mod_page
     */
    private function get_handler(\stdClass $course, int $cmid, string $mode = cm_settings::INLINE_AUTO): mod_page {
        $settings = cm_settings::defaults();
        $settings->inlinemode = $mode;
        return new mod_page(get_fast_modinfo($course)->get_cm($cmid), $settings);
    }

    /**
     * A short page is inline by default; a long one, or one set to open its page, is not.
     *
     * @return void
     */
    public function test_supports_inline(): void {
        $course = $this->setup_course();
        $generator = $this->getDataGenerator();
        $short = $generator->create_module('page', ['course' => $course->id, 'content' => '<p>Meet in room 4.</p>']);
        $long = $generator->create_module('page', ['course' => $course->id,
            'content' => '<p>' . str_repeat('A long page. ', 200) . '</p>']);

        $this->assertTrue(mod_page::default_inline());
        $this->assertTrue($this->get_handler($course, $short->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $short->cmid, cm_settings::INLINE_PAGE)->is_inline());
        $this->assertFalse($this->get_handler($course, $long->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $long->cmid, cm_settings::INLINE_INLINE)->is_inline());
    }

    /**
     * The plain-text length ignores tags and entities.
     *
     * @return void
     */
    public function test_plain_length(): void {
        $this->assertSame(11, mod_page::plain_length('<p><strong>Hello</strong> &amp; you</p>'));
        $this->assertSame('One Two', mod_page::plain_text('<p>One</p><p>Two</p>'));
    }

    /**
     * The card shows the content, with "Mark as read" only when the page completes on view.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $course = $this->setup_course();
        $generator = $this->getDataGenerator();
        $tracked = $generator->create_module('page', ['course' => $course->id, 'content' => '<p>Meet in room 4.</p>',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]);
        $plain = $generator->create_module('page', ['course' => $course->id, 'content' => '<p>Meet in room 5.</p>']);
        $output = $PAGE->get_renderer('core');

        $html = $this->get_handler($course, $tracked->cmid)->render($output);
        $this->assertStringContainsString('Meet in room 4.', $html);
        $this->assertStringContainsString('data-spiffy-action="view"', $html);

        $html = $this->get_handler($course, $plain->cmid)->render($output);
        $this->assertStringContainsString('Meet in room 5.', $html);
        $this->assertStringNotContainsString('data-spiffy-action="view"', $html);
    }

    /**
     * The view action records the view and completes a page that completes on view.
     *
     * @return void
     */
    public function test_view_action(): void {
        $course = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Read me.</p>',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]);
        $handler = $this->get_handler($course, $page->cmid);
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());

        $sink = $this->redirectEvents();
        $message = $handler->process_action('view', []);
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_page\event\course_module_viewed);
        $sink->close();

        $this->assertSame(get_string('markedasread', 'format_spiffy'), $message);
        $this->assertCount(1, $events);
        $this->assertTrue(handler::is_complete(get_fast_modinfo($course)->get_cm($page->cmid)));
        $this->assertSame(handler::STATE_DONE, $this->get_handler($course, $page->cmid)->get_state());
    }

    /**
     * Unknown actions are refused.
     *
     * @return void
     */
    public function test_invalid_action(): void {
        $course = $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $page->cmid)->process_action('delete', []);
    }
}
