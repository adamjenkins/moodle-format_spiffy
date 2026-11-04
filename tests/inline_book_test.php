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
use format_spiffy\local\inline\mod_book;

/**
 * Tests for Book on a card.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_book::class)]
final class inline_book_test extends advanced_testcase {
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
     * A book with chapters.
     *
     * @param \stdClass $course
     * @param string[] $contents the content of each chapter, in order
     * @param array $options more module fields
     * @return \stdClass the book, with cmid
     */
    private function create_book(\stdClass $course, array $contents, array $options = []): \stdClass {
        $generator = $this->getDataGenerator();
        $book = $generator->create_module('book', ['course' => $course->id] + $options);
        $bookgenerator = $generator->get_plugin_generator('mod_book');
        foreach (array_values($contents) as $i => $content) {
            $bookgenerator->create_chapter(['bookid' => $book->id, 'pagenum' => $i + 1, 'title' => 'Chapter ' . ($i + 1),
                'content' => $content, 'contentformat' => FORMAT_HTML]);
        }
        return $book;
    }

    /**
     * The handler of a book, as the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param string $mode inline mode
     * @return mod_book
     */
    private function get_handler(\stdClass $course, int $cmid, string $mode = cm_settings::INLINE_INLINE): mod_book {
        $settings = cm_settings::defaults();
        $settings->inlinemode = $mode;
        return new mod_book(get_fast_modinfo($course)->get_cm($cmid), $settings);
    }

    /**
     * A book opens its page by default; set to inline, only a short book is read on the card.
     *
     * @return void
     */
    public function test_supports_inline(): void {
        $course = $this->setup_course();
        $short = $this->create_book($course, ['<p>Wear goggles.</p>', '<p>Report spills.</p>']);
        $long = $this->create_book($course, ['<p>' . str_repeat('A long chapter. ', 120) . '</p>', '<p>Short.</p>']);
        $empty = $this->create_book($course, []);

        $this->assertFalse(mod_book::default_inline());
        $this->assertFalse($this->get_handler($course, $short->cmid, cm_settings::INLINE_AUTO)->is_inline());
        $this->assertTrue($this->get_handler($course, $short->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $long->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $empty->cmid)->is_inline());
    }

    /**
     * The card shows the chapter list and the chapters.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $course = $this->setup_course();
        $book = $this->create_book(
            $course,
            ['<p>Wear goggles.</p>', '<p>Report spills.</p>'],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]
        );
        $html = $this->get_handler($course, $book->cmid)->render($PAGE->get_renderer('core'));
        $this->assertStringContainsString('spiffy-book-toc', $html);
        $this->assertStringContainsString('Wear goggles.', $html);
        $this->assertStringContainsString('Report spills.', $html);
        $this->assertStringContainsString('data-spiffy-action="view"', $html);
    }

    /**
     * The view action records the book and chapter views, and the last chapter completes the book.
     *
     * @return void
     */
    public function test_view_action(): void {
        $course = $this->setup_course();
        $book = $this->create_book(
            $course,
            ['<p>Wear goggles.</p>', '<p>Report spills.</p>'],
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]
        );
        $handler = $this->get_handler($course, $book->cmid);
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());

        $sink = $this->redirectEvents();
        $handler->process_action('view', []);
        $events = $sink->get_events();
        $sink->close();

        $viewed = array_filter($events, fn($event) => $event instanceof \mod_book\event\course_module_viewed);
        $chapters = array_filter($events, fn($event) => $event instanceof \mod_book\event\chapter_viewed);
        $this->assertCount(1, $viewed);
        $this->assertCount(2, $chapters);
        $this->assertTrue(handler::is_complete(get_fast_modinfo($course)->get_cm($book->cmid)));
    }

    /**
     * Unknown actions are refused.
     *
     * @return void
     */
    public function test_invalid_action(): void {
        $course = $this->setup_course();
        $book = $this->create_book($course, ['<p>One.</p>']);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $book->cmid)->process_action('delete', []);
    }
}
