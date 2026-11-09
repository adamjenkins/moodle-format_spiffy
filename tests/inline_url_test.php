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
use format_spiffy\local\inline\mod_url;

/**
 * Tests for URL on a card.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_url::class)]
final class inline_url_test extends advanced_testcase {
    /**
     * Links and the video id found in them.
     *
     * @return array[]
     */
    public static function youtube_provider(): array {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch with more parameters' => ['https://youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42', 'dQw4w9WgXcQ'],
            'watch with encoded ampersand' => ['https://www.youtube.com/watch?list=PL1&amp;v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short link' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'short link with time' => ['https://youtu.be/dQw4w9WgXcQ?t=10', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/a-b_c1D2e3F', 'a-b_c1D2e3F'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'nocookie embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'plain http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'id too short' => ['https://www.youtube.com/watch?v=dQw4w9WgXc', null],
            'id too long' => ['https://youtu.be/dQw4w9WgXcQQ', null],
            'id with bad characters' => ['https://youtu.be/dQw4w9"gXcQ', null],
            'id with a slash' => ['https://www.youtube.com/embed/dQw4w9WgXcQ/x', null],
            'array parameter' => ['https://www.youtube.com/watch?v[]=dQw4w9WgXcQ', null],
            'channel' => ['https://www.youtube.com/@moodle', null],
            'other site' => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
            'lookalike host' => ['https://youtube.com.example.com/watch?v=dQw4w9WgXcQ', null],
            'javascript' => ['javascript:alert(1)//youtu.be/dQw4w9WgXcQ', null],
            'with user info' => ['https://youtu.be@example.com/dQw4w9WgXcQ', null],
            'not a link' => ['dQw4w9WgXcQ', null],
        ];
    }

    /**
     * The video id is found in YouTube links and nowhere else.
     *
     * @param string $link
     * @param string|null $expected
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('youtube_provider')]
    public function test_youtube_id(string $link, ?string $expected): void {
        $this->assertSame($expected, mod_url::youtube_id($link));
    }

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
     * The handler of a link, as the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param string $mode inline mode
     * @return mod_url
     */
    private function get_handler(\stdClass $course, int $cmid, string $mode = cm_settings::INLINE_AUTO): mod_url {
        $settings = cm_settings::defaults();
        $settings->inlinemode = $mode;
        return new mod_url(get_fast_modinfo($course)->get_cm($cmid), $settings);
    }

    /**
     * YouTube links are inline by default; other links are not.
     *
     * @return void
     */
    public function test_supports_inline(): void {
        $course = $this->setup_course();
        $generator = $this->getDataGenerator();
        $video = $generator->create_module('url', ['course' => $course->id, 'externalurl' => 'https://youtu.be/dQw4w9WgXcQ']);
        $other = $generator->create_module('url', ['course' => $course->id, 'externalurl' => 'https://moodle.org/']);

        $this->assertTrue(mod_url::default_inline());
        $this->assertTrue($this->get_handler($course, $video->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $video->cmid, cm_settings::INLINE_PAGE)->is_inline());
        $this->assertFalse($this->get_handler($course, $other->cmid)->is_inline());
        $this->assertFalse($this->get_handler($course, $other->cmid, cm_settings::INLINE_INLINE)->is_inline());
    }

    /**
     * The card shows a play button for the youtube-nocookie.com video, and loads nothing from YouTube
     * until it is clicked.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $course = $this->setup_course();
        $video = $this->getDataGenerator()->create_module('url', ['course' => $course->id, 'name' => 'Welcome video',
            'externalurl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);
        $html = $this->get_handler($course, $video->cmid)->render($PAGE->get_renderer('core'));
        $this->assertStringContainsString('data-spiffy-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $html);
        $this->assertStringContainsString('data-spiffy-title="Welcome video"', $html);
        $this->assertStringContainsString(get_string('playvideoname', 'format_spiffy', 'Welcome video'), $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('src="https://www.youtube', $html);
    }

    /**
     * The view action records the view and completes a link that completes on view.
     *
     * @return void
     */
    public function test_view_action(): void {
        $course = $this->setup_course();
        $video = $this->getDataGenerator()->create_module('url', ['course' => $course->id,
            'externalurl' => 'https://youtu.be/dQw4w9WgXcQ',
            'completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]);
        $handler = $this->get_handler($course, $video->cmid);
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());

        $sink = $this->redirectEvents();
        $message = $handler->process_action('view', []);
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_url\event\course_module_viewed);
        $sink->close();

        $this->assertSame(get_string('markedasviewed', 'format_spiffy'), $message);
        $this->assertCount(1, $events);
        $this->assertTrue(handler::is_complete(get_fast_modinfo($course)->get_cm($video->cmid)));
    }
}
