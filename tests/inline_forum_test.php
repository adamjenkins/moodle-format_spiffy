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
use format_spiffy\local\inline\mod_forum;
use format_spiffy\local\inline\registry;

/**
 * Tests for the Forum card handler.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_forum::class)]
final class inline_forum_test extends advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/mod/forum/lib.php');
    }

    /**
     * A Spiffy course with a student and a teacher.
     *
     * @return array [course, student, teacher]
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        return [$course, $student, $teacher];
    }

    /**
     * The handler of a forum, for the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return mod_forum
     */
    private function get_handler(\stdClass $course, int $cmid): mod_forum {
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $handler = registry::get_handler($cm, cm_settings::get($cmid));
        $this->assertInstanceOf(mod_forum::class, $handler);
        return $handler;
    }

    /**
     * Render a card's inside for the current user.
     *
     * @param mod_forum $handler
     * @return string
     */
    private function render(mod_forum $handler): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $handler->get_cm()->course]));
        return $handler->render($PAGE->get_renderer('core'));
    }

    /**
     * Add a discussion to a forum.
     *
     * @param \stdClass $course
     * @param \stdClass $forum
     * @param \stdClass $user
     * @param array $extra more discussion fields
     * @return \stdClass the forum_discussions record
     */
    private function add_discussion(\stdClass $course, \stdClass $forum, \stdClass $user, array $extra = []): \stdClass {
        global $DB;
        $record = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion(
            ['course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id] + $extra
        );
        return $DB->get_record('forum_discussions', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * A single simple discussion forum is done on its card: the opening post and a reply box,
     * and a reply posts through mod_forum.
     */
    public function test_single_forum_reply(): void {
        global $DB;
        [$course, $student] = $this->setup_course();
        $this->setAdminUser();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'single',
            'intro' => 'Tell us about your weekend', 'introformat' => FORMAT_HTML]);
        $discussion = $DB->get_record('forum_discussions', ['forum' => $forum->id], '*', MUST_EXIST);

        $this->setUser($student);
        $handler = $this->get_handler($course, $forum->cmid);
        $this->assertTrue($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringContainsString('Tell us about your weekend', $html);
        $this->assertStringContainsString('data-spiffy-action="reply"', $html);
        $this->assertStringContainsString(get_string('forum_noreplies', 'format_spiffy'), $html);

        $message = $handler->process_action('reply', ['message' => 'I went hiking']);
        $this->assertSame(get_string('forum_replysent', 'format_spiffy'), $message);
        $post = $DB->get_record('forum_posts', ['discussion' => $discussion->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertSame('I went hiking', $post->message);
        $this->assertEquals(FORMAT_MOODLE, $post->messageformat);
        $this->assertEquals($discussion->firstpost, $post->parent);
        $parent = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], 'subject', MUST_EXIST);
        $this->assertSame($parent->subject, $post->subject);

        $html = $this->render($this->get_handler($course, $forum->cmid));
        $this->assertStringContainsString('I went hiking', $html);
    }

    /**
     * Only the latest replies are shown, with a link to the whole discussion.
     */
    public function test_latest_replies_only(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $discussion = $this->add_discussion($course, $forum, $teacher);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        for ($i = 1; $i <= mod_forum::MAX_REPLIES + 2; $i++) {
            $generator->create_post(['discussion' => $discussion->id, 'parent' => $discussion->firstpost,
                'userid' => $teacher->id, 'message' => "Reply number {$i}.", 'created' => time() - 100 + $i]);
        }

        $this->setUser($student);
        $html = $this->render($this->get_handler($course, $forum->cmid));
        $this->assertStringNotContainsString('Reply number 1.', $html);
        $this->assertStringNotContainsString('Reply number 2.', $html);
        $this->assertStringContainsString('Reply number 3.', $html);
        $this->assertStringContainsString('Reply number 5.', $html);
        $this->assertStringContainsString('discuss.php?d=' . $discussion->id, $html);
    }

    /**
     * A standard forum is inline only while it has exactly one discussion.
     */
    public function test_general_forum_needs_one_discussion(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);

        $this->setUser($student);
        $this->assertFalse($this->get_handler($course, $forum->cmid)->is_inline());

        $this->add_discussion($course, $forum, $teacher);
        $this->assertTrue($this->get_handler($course, $forum->cmid)->is_inline());

        $this->add_discussion($course, $forum, $teacher);
        $handler = $this->get_handler($course, $forum->cmid);
        $this->assertFalse($handler->is_inline());
        $this->assertSame('', $this->render($handler));
    }

    /**
     * Data provider of forum types that always open their page.
     *
     * @return array
     */
    public static function other_types_provider(): array {
        return [
            'Q and A' => ['qanda'],
            'Each person posts one discussion' => ['eachuser'],
            'Blog-like' => ['blog'],
        ];
    }

    /**
     * Other forum types are not done inline, even with one discussion.
     *
     * @param string $type
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('other_types_provider')]
    public function test_other_types_not_inline(string $type): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => $type]);
        $this->add_discussion($course, $forum, $teacher);

        $this->setUser($student);
        $handler = $this->get_handler($course, $forum->cmid);
        $this->assertFalse($handler->is_inline());
        $this->expectException(\moodle_exception::class);
        $handler->process_action('reply', ['message' => 'Hello']);
    }

    /**
     * Without the reply capability the forum opens its page.
     */
    public function test_needs_replypost(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $this->add_discussion($course, $forum, $teacher);
        $context = \core\context\module::instance($forum->cmid);
        assign_capability('mod/forum:replypost', CAP_PROHIBIT, $this->get_student_roleid(), $context->id, true);

        $this->setUser($student);
        $this->assertFalse($this->get_handler($course, $forum->cmid)->is_inline());
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
     * Empty replies are refused.
     */
    public function test_empty_reply(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $this->add_discussion($course, $forum, $teacher);

        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $forum->cmid)->process_action('reply', ['message' => "  \n "]);
    }

    /**
     * Unknown actions are refused.
     */
    public function test_unknown_action(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $this->add_discussion($course, $forum, $teacher);

        $this->setUser($student);
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $forum->cmid)->process_action('delete', []);
    }

    /**
     * A locked discussion shows why the reply box is gone, and core refuses the reply.
     */
    public function test_locked_discussion(): void {
        global $DB;
        [$course, $student, $teacher] = $this->setup_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $this->add_discussion($course, $forum, $teacher, ['timelocked' => time() - 60]);

        $this->setUser($student);
        $handler = $this->get_handler($course, $forum->cmid);
        $this->assertTrue($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringNotContainsString('data-spiffy-action="reply"', $html);
        $this->assertStringContainsString(s(get_string('discussionlocked', 'forum')), $html);

        try {
            $handler->process_action('reply', ['message' => 'Too late']);
            $this->fail('A reply to a locked discussion was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('nopostforum', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('forum_posts', ['userid' => $student->id]));
    }

    /**
     * Private replies to someone else are not shown.
     */
    public function test_private_replies_hidden(): void {
        [$course, $student, $teacher] = $this->setup_course();
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general']);
        $discussion = $this->add_discussion($course, $forum, $other);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post(['discussion' => $discussion->id,
            'parent' => $discussion->firstpost, 'userid' => $teacher->id, 'message' => 'Just for you',
            'privatereplyto' => $other->id]);

        $this->setUser($student);
        $this->assertStringNotContainsString('Just for you', $this->render($this->get_handler($course, $forum->cmid)));
        $this->setUser($other);
        $this->assertStringContainsString('Just for you', $this->render($this->get_handler($course, $forum->cmid)));
    }

    /**
     * The card shows how many posts are unread when the forum tracks them.
     */
    public function test_unread_badge(): void {
        global $CFG;
        [$course, $student, $teacher] = $this->setup_course();
        $CFG->forum_trackreadposts = 1;
        $CFG->forum_allowforcedreadtracking = 1;
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'type' => 'general',
            'trackingtype' => FORUM_TRACKING_FORCED]);
        $discussion = $this->add_discussion($course, $forum, $teacher);
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_post(['discussion' => $discussion->id,
            'parent' => $discussion->firstpost, 'userid' => $teacher->id]);

        $this->setUser($student);
        $texts = array_column($this->get_handler($course, $forum->cmid)->get_badges(), 'text');
        $this->assertContains(get_string('forum_badgeunread', 'format_spiffy', 2), $texts);
    }
}
