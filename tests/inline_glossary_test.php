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
use format_spiffy\local\inline\mod_glossary;
use format_spiffy\local\inline\registry;

/**
 * Tests for the Glossary card handler and the peer reactions it shares with the Database card.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_glossary::class)]
final class inline_glossary_test extends advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/rating/lib.php');
    }

    /**
     * A Spiffy course with two students.
     *
     * @return array [course, student, classmate]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $classmate = $generator->create_and_enrol($course, 'student');
        return [$course, $student, $classmate];
    }

    /**
     * The handler of a glossary, for the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return mod_glossary
     */
    private function get_handler(\stdClass $course, int $cmid): mod_glossary {
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $handler = registry::get_handler($cm, cm_settings::get($cmid));
        $this->assertInstanceOf(mod_glossary::class, $handler);
        return $handler;
    }

    /**
     * Render a card's inside for the current user.
     *
     * @param mod_glossary $handler
     * @return string
     */
    private function render(mod_glossary $handler): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $handler->get_cm()->course]));
        return $handler->render($PAGE->get_renderer('core'));
    }

    /**
     * Add an approved entry by a user.
     *
     * @param \stdClass $glossary
     * @param \stdClass $user
     * @param string $concept
     * @return \stdClass
     */
    private function add_entry(\stdClass $glossary, \stdClass $user, string $concept): \stdClass {
        return $this->getDataGenerator()->get_plugin_generator('mod_glossary')->create_content(
            $glossary,
            ['userid' => $user->id, 'concept' => $concept, 'definition' => "About {$concept}.", 'approved' => 1]
        );
    }

    /**
     * The id of the student role.
     *
     * @return int
     */
    private function get_student_roleid(): int {
        global $DB;
        return (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
    }

    /**
     * A student adds a term from the card.
     */
    public function test_add_term(): void {
        global $DB;
        [$course, $student] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'defaultapproval' => 1]);

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        $this->assertTrue($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringContainsString('data-spiffy-action="add"', $html);
        $this->assertStringContainsString('name="concept"', $html);

        $message = $handler->process_action('add', ['concept' => 'Osmosis', 'definition' => 'Water moving through a membrane.']);
        $this->assertSame(get_string('glossary_added', 'format_spiffy'), $message);
        $entry = $DB->get_record('glossary_entries', ['glossaryid' => $glossary->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame('Osmosis', $entry->concept);
        $this->assertEquals(FORMAT_MOODLE, $entry->definitionformat);
        $this->assertEquals(1, $entry->approved);

        $html = $this->render($this->get_handler($course, $glossary->cmid));
        $this->assertStringContainsString('Osmosis', $html);
        $this->assertStringNotContainsString(get_string('peer_waiting', 'format_spiffy'), $html);
    }

    /**
     * A term in a glossary that needs approval waits for it, and says so.
     */
    public function test_add_term_needs_approval(): void {
        [$course, $student] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'defaultapproval' => 0]);

        $this->setUser($student);
        $message = $this->get_handler($course, $glossary->cmid)->process_action(
            'add',
            ['concept' => 'Mitosis', 'definition' => 'Cell division.']
        );
        $this->assertSame(get_string('glossary_addedpending', 'format_spiffy'), $message);

        $handler = $this->get_handler($course, $glossary->cmid);
        $this->assertStringContainsString(get_string('peer_waiting', 'format_spiffy'), $this->render($handler));
        $this->assertContains(get_string('peer_badgepending', 'format_spiffy', 1), array_column($handler->get_badges(), 'text'));
    }

    /**
     * Empty terms and duplicates are refused.
     */
    public function test_add_term_refused(): void {
        [$course, $student, $classmate] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id,
            'allowduplicatedentries' => 0]);
        $this->add_entry($glossary, $classmate, 'Osmosis');

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        try {
            $handler->process_action('add', ['concept' => '', 'definition' => 'Something']);
            $this->fail('An empty concept was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('glossary_empty', $e->errorcode);
        }
        try {
            $handler->process_action('add', ['concept' => 'Osmosis', 'definition' => 'Again']);
            $this->fail('A duplicate concept was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errconceptalreadyexists', $e->errorcode);
        }
    }

    /**
     * Without the write capability, and with no ratings or comments, the glossary opens its page.
     */
    public function test_needs_write_capability(): void {
        [$course, $student] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'allowcomments' => 0,
            'assessed' => 0]);
        $context = \core\context\module::instance($glossary->cmid);
        assign_capability('mod/glossary:write', CAP_PROHIBIT, $this->get_student_roleid(), $context->id, true);

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        $this->assertFalse($handler->is_inline());
        $this->expectException(\required_capability_exception::class);
        $handler->process_action('add', ['concept' => 'Osmosis', 'definition' => 'Water.']);
    }

    /**
     * A student rates a classmate's term from the card, but not their own.
     */
    public function test_rate_classmate(): void {
        global $DB;
        [$course, $student, $classmate] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id,
            'assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 5]);
        $context = \core\context\module::instance($glossary->cmid);
        assign_capability('mod/glossary:rate', CAP_ALLOW, $this->get_student_roleid(), $context->id, true);
        $theirs = $this->add_entry($glossary, $classmate, 'Photosynthesis');
        $mine = $this->add_entry($glossary, $student, 'Respiration');

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        $html = $this->render($handler);
        $this->assertStringContainsString('Photosynthesis', $html);
        $this->assertStringContainsString('data-spiffy-action="rate"', $html);

        $message = $handler->process_action('rate', ['itemid' => (string)$theirs->id, 'rating' => '4']);
        $this->assertSame(get_string('peer_ratingsaved', 'format_spiffy'), $message);
        $rating = $DB->get_record('rating', ['component' => 'mod_glossary', 'ratingarea' => 'entry', 'itemid' => $theirs->id,
            'userid' => $student->id], '*', MUST_EXIST);
        $this->assertEquals(4, $rating->rating);

        $this->expectException(\moodle_exception::class);
        $handler->process_action('rate', ['itemid' => (string)$mine->id, 'rating' => '4']);
    }

    /**
     * Without ratings on, rating is refused.
     */
    public function test_rate_without_ratings(): void {
        [$course, $student, $classmate] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'assessed' => 0]);
        $theirs = $this->add_entry($glossary, $classmate, 'Photosynthesis');

        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $glossary->cmid)->process_action('rate', ['itemid' => (string)$theirs->id, 'rating' => '4']);
    }

    /**
     * A student comments on a classmate's term when comments are on.
     */
    public function test_comment_classmate(): void {
        global $CFG, $DB;
        [$course, $student, $classmate] = $this->setup_course();
        $CFG->usecomments = 1;
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'allowcomments' => 1]);
        $theirs = $this->add_entry($glossary, $classmate, 'Photosynthesis');

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        $this->assertStringContainsString('data-spiffy-action="comment"', $this->render($handler));

        $message = $handler->process_action('comment', ['itemid' => (string)$theirs->id, 'content' => 'Great example']);
        $this->assertSame(get_string('peer_commentsaved', 'format_spiffy'), $message);
        $this->assertTrue($DB->record_exists('comments', ['component' => 'mod_glossary', 'commentarea' => 'glossary_entry',
            'itemid' => $theirs->id, 'userid' => $student->id]));

        $this->assertStringContainsString('Great example', $this->render($this->get_handler($course, $glossary->cmid)));
    }

    /**
     * Comments are refused when the glossary has them off, and for entries of another glossary.
     */
    public function test_comment_refused(): void {
        global $CFG;
        [$course, $student, $classmate] = $this->setup_course();
        $CFG->usecomments = 1;
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'allowcomments' => 0]);
        $other = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'allowcomments' => 1]);
        $theirs = $this->add_entry($glossary, $classmate, 'Photosynthesis');
        $elsewhere = $this->add_entry($other, $classmate, 'Respiration');

        $this->setUser($student);
        $handler = $this->get_handler($course, $glossary->cmid);
        $this->assertStringNotContainsString('data-spiffy-action="comment"', $this->render($handler));
        try {
            $handler->process_action('comment', ['itemid' => (string)$theirs->id, 'content' => 'Hello']);
            $this->fail('A comment was accepted with comments off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('commentsnotenabled', $e->errorcode);
        }

        $handler = $this->get_handler($course, $other->cmid);
        try {
            $handler->process_action('comment', ['itemid' => (string)$theirs->id, 'content' => 'Hello']);
            $this->fail('A comment on another glossary\'s entry was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('peer_invalidentry', $e->errorcode);
        }
        $this->assertSame(
            get_string('peer_commentsaved', 'format_spiffy'),
            $handler->process_action('comment', ['itemid' => (string)$elsewhere->id, 'content' => 'Hello'])
        );
    }

    /**
     * Unknown actions are refused.
     */
    public function test_unknown_action(): void {
        [$course, $student] = $this->setup_course();
        $glossary = $this->getDataGenerator()->create_module('glossary', ['course' => $course->id]);

        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $glossary->cmid)->process_action('approve', []);
    }
}
