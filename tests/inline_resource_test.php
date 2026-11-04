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
use format_spiffy\local\inline\mod_resource;

/**
 * Tests for File (mod_resource) on a card.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_resource::class)]
final class inline_resource_test extends advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The student. */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/resourcelib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $this->student = $generator->create_and_enrol($this->course, 'student');
        // The resource generator needs a real user to own the draft file.
        $this->setAdminUser();
    }

    /**
     * A file resource.
     *
     * @param string $filename
     * @param array $options more module fields
     * @return \stdClass with cmid
     */
    private function create_resource(string $filename, array $options = []): \stdClass {
        return $this->getDataGenerator()->create_module(
            'resource',
            ['course' => $this->course->id, 'defaultfilename' => $filename] + $options
        );
    }

    /**
     * The handler of a file, as the student.
     *
     * @param int $cmid
     * @param string $mode inline mode
     * @return mod_resource
     */
    private function get_handler(int $cmid, string $mode = cm_settings::INLINE_AUTO): mod_resource {
        $this->setUser($this->student);
        $settings = cm_settings::defaults();
        $settings->inlinemode = $mode;
        return new mod_resource(get_fast_modinfo($this->course)->get_cm($cmid), $settings);
    }

    /**
     * PDFs and web images are shown on the card; other types are not.
     *
     * @return void
     */
    public function test_preview_kind(): void {
        $this->assertSame(mod_resource::KIND_PDF, mod_resource::preview_kind('application/pdf'));
        $this->assertSame(mod_resource::KIND_IMAGE, mod_resource::preview_kind('image/png'));
        $this->assertSame(mod_resource::KIND_IMAGE, mod_resource::preview_kind('image/jpeg'));
        $this->assertNull(mod_resource::preview_kind('text/plain'));
        $this->assertNull(mod_resource::preview_kind('application/zip'));
    }

    /**
     * PDFs and images are inline by default; other files and forced downloads are not.
     *
     * @return void
     */
    public function test_supports_inline(): void {
        $pdf = $this->create_resource('reading.pdf');
        $image = $this->create_resource('map.png');
        $text = $this->create_resource('notes.txt');
        $download = $this->create_resource('handout.pdf', ['display' => RESOURCELIB_DISPLAY_DOWNLOAD]);

        $this->assertTrue(mod_resource::default_inline());
        $this->assertTrue($this->get_handler($pdf->cmid)->is_inline());
        $this->assertFalse($this->get_handler($pdf->cmid, cm_settings::INLINE_PAGE)->is_inline());
        $this->assertTrue($this->get_handler($image->cmid)->is_inline());
        $this->assertFalse($this->get_handler($text->cmid)->is_inline());
        $this->assertFalse($this->get_handler($download->cmid)->is_inline());
    }

    /**
     * The card shows a PDF in an object element and an image with the activity name as its text.
     *
     * @return void
     */
    public function test_render(): void {
        global $PAGE;
        $pdf = $this->create_resource('reading.pdf');
        $image = $this->create_resource('map.png', ['name' => 'Campus map']);
        $output = $PAGE->get_renderer('core');

        $html = $this->get_handler($pdf->cmid)->render($output);
        $this->assertStringContainsString('type="application/pdf"', $html);
        $this->assertStringContainsString('/mod_resource/content/', $html);
        $this->assertStringContainsString('reading.pdf', $html);

        $html = $this->get_handler($image->cmid)->render($output);
        $this->assertStringContainsString('alt="Campus map"', $html);
    }

    /**
     * The view action records the view and completes a file that completes on view.
     *
     * @return void
     */
    public function test_view_action(): void {
        $pdf = $this->create_resource(
            'reading.pdf',
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => COMPLETION_VIEW_REQUIRED]
        );
        $handler = $this->get_handler($pdf->cmid);
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());

        $sink = $this->redirectEvents();
        $message = $handler->process_action('view', []);
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_resource\event\course_module_viewed);
        $sink->close();

        $this->assertSame(get_string('markedasviewed', 'format_spiffy'), $message);
        $this->assertCount(1, $events);
        $this->assertTrue(handler::is_complete(get_fast_modinfo($this->course)->get_cm($pdf->cmid)));
    }
}
