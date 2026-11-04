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

use core\context\module as context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use format_spiffy\privacy\provider;

/**
 * Tests for the privacy provider of the Spiffy course format.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\privacy\provider::class)]
final class privacy_provider_test extends provider_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var context_module[] Two activity contexts. */
    private array $contexts = [];

    /** @var \stdClass A student with data in both activities. */
    private \stdClass $student;

    /** @var \stdClass Another student, with data in the first activity. */
    private \stdClass $other;

    /** @var \stdClass The teacher who was asked. */
    private \stdClass $teacher;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '',
            'enablecompletion' => 1]);
        $this->student = $generator->create_user();
        $this->other = $generator->create_user();
        $this->teacher = $generator->create_user();
        $this->contexts = [];
        foreach (['First', 'Second'] as $name) {
            $page = $generator->create_module('page', ['course' => $this->course->id, 'section' => 1, 'name' => $name]);
            $this->contexts[] = context_module::instance($page->cmid);
        }
        $this->add_data($this->student, $this->contexts[0], 'Note one', 3);
        $this->add_data($this->student, $this->contexts[1], 'Note two', 1);
        $this->add_data($this->other, $this->contexts[0], 'Other note', 2);
    }

    /**
     * Store a note, a confidence check and a question for a user in an activity.
     *
     * @param \stdClass $user
     * @param context_module $context
     * @param string $note
     * @param int $confidence
     * @return void
     */
    private function add_data(\stdClass $user, context_module $context, string $note, int $confidence): void {
        global $DB;
        $base = ['courseid' => $this->course->id, 'cmid' => $context->instanceid, 'userid' => $user->id];
        $DB->insert_record('format_spiffy_note', (object)($base + ['note' => $note, 'timemodified' => time()]));
        $DB->insert_record('format_spiffy_confidence', (object)($base + ['confidence' => $confidence, 'timemodified' => time()]));
        $DB->insert_record('format_spiffy_question', (object)($base + ['teacherid' => $this->teacher->id,
            'timecreated' => time(), 'timeseen' => 0]));
    }

    /**
     * Count the rows of the three tables in an activity.
     *
     * @param context_module $context
     * @param int|null $userid only this user's rows (as student or teacher)
     * @return int
     */
    private function count_rows(context_module $context, ?int $userid = null): int {
        global $DB;
        $count = 0;
        foreach (['format_spiffy_note', 'format_spiffy_confidence', 'format_spiffy_question'] as $table) {
            $conditions = ['cmid' => $context->instanceid];
            if ($userid) {
                $conditions['userid'] = $userid;
            }
            $count += $DB->count_records($table, $conditions);
        }
        return $count;
    }

    /**
     * The metadata names the three tables, the message link and the view preference.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('format_spiffy'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());
        foreach (
            ['format_spiffy_note', 'format_spiffy_confidence', 'format_spiffy_question', 'core_message',
                'format_spiffy_viewmode'] as $name
        ) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * Students have data in the activities they wrote in; the teacher in those they were asked about.
     */
    public function test_get_contexts_for_userid(): void {
        $ids = fn($user) => provider::get_contexts_for_userid($user->id)->get_contextids();
        $all = [$this->contexts[0]->id, $this->contexts[1]->id];
        $this->assertEqualsCanonicalizing($all, $ids($this->student));
        $this->assertEqualsCanonicalizing([$this->contexts[0]->id], $ids($this->other));
        $this->assertEqualsCanonicalizing($all, $ids($this->teacher));
    }

    /**
     * Users in an activity context include the students and the teacher asked.
     */
    public function test_get_users_in_context(): void {
        $userlist = new userlist($this->contexts[0], 'format_spiffy');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [$this->student->id, $this->other->id, $this->teacher->id],
            $userlist->get_userids()
        );

        $userlist = new userlist(\core\context\course::instance($this->course->id), 'format_spiffy');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * The export has the student's own note, confidence and questions, and the teacher's received questions.
     */
    public function test_export_user_data(): void {
        $path = [get_string('privacy:path', 'format_spiffy')];
        $this->export_context_data_for_user($this->student->id, $this->contexts[0], 'format_spiffy');
        $data = writer::with_context($this->contexts[0])->get_data($path);
        $this->assertSame('Note one', $data->note->note);
        $this->assertSame(get_string('confidence3', 'format_spiffy'), $data->confidence->confidence);
        $this->assertCount(1, $data->questionsasked);
        $this->assertObjectNotHasProperty('questionsreceived', $data);

        writer::reset();
        $this->export_context_data_for_user($this->teacher->id, $this->contexts[0], 'format_spiffy');
        $data = writer::with_context($this->contexts[0])->get_data($path);
        $this->assertCount(2, $data->questionsreceived);
        $this->assertObjectNotHasProperty('note', $data);
    }

    /**
     * The view preference is exported.
     */
    public function test_export_user_preferences(): void {
        set_user_preference('format_spiffy_viewmode', 'list', $this->student->id);
        provider::export_user_preferences($this->student->id);
        $prefs = writer::with_context(\core\context\system::instance())->get_user_preferences('format_spiffy');
        $this->assertSame('list', $prefs->format_spiffy_viewmode->value);
    }

    /**
     * Deleting a context removes everyone's data there and nowhere else.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context($this->contexts[0]);
        $this->assertSame(0, $this->count_rows($this->contexts[0]));
        $this->assertSame(3, $this->count_rows($this->contexts[1]));
    }

    /**
     * Deleting one user's data leaves the others', and the other person keeps their record of a question.
     */
    public function test_delete_data_for_user(): void {
        provider::delete_data_for_user(new approved_contextlist($this->student, 'format_spiffy', [$this->contexts[0]->id]));
        $this->assertSame(0, $this->count_rows($this->contexts[0], $this->student->id));
        $this->assertSame(3, $this->count_rows($this->contexts[0], $this->other->id));
        $this->assertSame(3, $this->count_rows($this->contexts[1], $this->student->id));

        provider::delete_data_for_user(new approved_contextlist($this->teacher, 'format_spiffy', [$this->contexts[0]->id]));
        $this->assertSame(3, $this->count_rows($this->contexts[0], $this->other->id));
    }

    /**
     * Deleting a list of users in a context removes only theirs.
     */
    public function test_delete_data_for_users(): void {
        provider::delete_data_for_users(new approved_userlist($this->contexts[0], 'format_spiffy', [$this->other->id]));
        $this->assertSame(0, $this->count_rows($this->contexts[0], $this->other->id));
        $this->assertSame(3, $this->count_rows($this->contexts[0], $this->student->id));
        $this->assertSame(3, $this->count_rows($this->contexts[1], $this->student->id));
    }
}
