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
use backup;
use backup_controller;
use backup_setting;
use completion_info;
use core_external\external_api;
use format_spiffy\external\ask_teacher;
use format_spiffy\external\save_note;
use format_spiffy\external\set_confidence;
use format_spiffy\local\inline\handler;
use format_spiffy\local\tools;
use restore_controller;

/**
 * Tests for the student tools on cards: notes, questions to the teacher and the confidence check.
 *
 * @package    format_spiffy
 * @copyright  2020 Ferran Recio <ferran@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\tools::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\external\save_note::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\external\ask_teacher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\external\set_confidence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_format_spiffy_plugin::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_format_spiffy_plugin::class)]
final class tools_test extends advanced_testcase {
    /** @var \stdClass The course. */
    private \stdClass $course;

    /** @var \stdClass The teacher, a course contact. */
    private \stdClass $teacher;

    /** @var \stdClass The student. */
    private \stdClass $student;

    /** @var \stdClass The choice activity. */
    private \stdClass $choice;

    #[\Override]
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        tools::reset_caches();
        set_config('coursecontact', (string)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']));
    }

    #[\Override]
    protected function tearDown(): void {
        tools::reset_caches();
        parent::tearDown();
    }

    /**
     * Create a Spiffy course with a teacher, a student and a choice with manual completion.
     *
     * @param array $courseoptions extra course fields
     * @return void
     */
    private function create_course(array $courseoptions = []): void {
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course($courseoptions + [
            'format' => 'spiffy',
            'numsections' => 1,
            'enablecompletion' => 1,
            'summary' => '',
        ]);
        $this->teacher = $generator->create_user(['firstname' => 'Terry', 'lastname' => 'Teacher']);
        $this->student = $generator->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $generator->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($this->student->id, $this->course->id, 'student');
        $this->choice = $generator->create_module('choice', [
            'course' => $this->course->id,
            'section' => 1,
            'name' => 'Pick one',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
    }

    /**
     * A real renderer: $OUTPUT is only a stand-in until something has used the page.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        return $PAGE->get_renderer('core');
    }

    /**
     * The choice as the given user sees it.
     *
     * @param \stdClass $user
     * @return \cm_info
     */
    private function cm(\stdClass $user): \cm_info {
        return get_fast_modinfo($this->course, $user->id)->get_cm($this->choice->cmid);
    }

    /**
     * Mark the choice done for the student.
     *
     * @return void
     */
    private function complete_choice(): void {
        $completion = new completion_info($this->course);
        $completion->update_state($this->cm($this->student), COMPLETION_COMPLETE, $this->student->id);
    }

    /**
     * A student gets notes and "Ask my teacher" on an open card, and the confidence check once it is done.
     */
    public function test_export_card_for_student(): void {
        $this->create_course();
        $this->setUser($this->student);

        $context = tools::export_card($this->cm($this->student), handler::STATE_AVAILABLE, $this->renderer());
        $this->assertSame((int)$this->choice->cmid, $context['cmid']);
        $this->assertFalse($context['hasnote']);
        $this->assertTrue($context['canask']);
        $this->assertFalse($context['multipleteachers']);
        $this->assertSame((int)$this->teacher->id, $context['teacher']['id']);
        $this->assertSame('Terry Teacher', $context['teacher']['fullname']);
        $this->assertNotEmpty($context['teacher']['pictureurl']);
        $this->assertFalse($context['showconfidence']);

        $context = tools::export_card($this->cm($this->student), handler::STATE_DONE, $this->renderer());
        $this->assertTrue($context['showconfidence']);
        $this->assertSame([1, 2, 3], array_column($context['confidence'], 'value'));
        $this->assertSame([false, false, false], array_column($context['confidence'], 'pressed'));

        $this->assertSame([], tools::export_card($this->cm($this->student), handler::STATE_LOCKED, $this->renderer()));

        $html = $this->renderer()->render_from_template('format_spiffy/local/card_tools', $context);
        $this->assertStringContainsString('Save to my notes', $html);
        $this->assertStringContainsString('Ask my teacher', $html);
        $this->assertStringContainsString('Only you can see this', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
    }

    /**
     * Teachers, guests and users who are not enrolled get no student tools.
     */
    public function test_export_card_empty_for_others(): void {
        $this->create_course();
        $outsider = $this->getDataGenerator()->create_user();

        $this->setUser($this->teacher);
        $this->assertSame([], tools::export_card($this->cm($this->teacher), handler::STATE_AVAILABLE, $this->renderer()));

        $this->setAdminUser();
        $this->assertSame([], tools::export_card($this->cm(get_admin()), handler::STATE_AVAILABLE, $this->renderer()));

        $this->setUser($outsider);
        $this->assertSame([], tools::export_card($this->cm($outsider), handler::STATE_AVAILABLE, $this->renderer()));

        $this->setGuestUser();
        $this->assertSame([], tools::export_card($this->cm(guest_user()), handler::STATE_AVAILABLE, $this->renderer()));
    }

    /**
     * Notes are saved, shown on the card, replaced, removed when emptied and limited in length.
     */
    public function test_save_note(): void {
        global $DB;
        $this->create_course();
        $this->setUser($this->student);

        $result = external_api::clean_returnvalue(
            save_note::execute_returns(),
            save_note::execute($this->choice->cmid, '  <b>Remember</b> the second option  ')
        );
        $this->assertSame(get_string('notesaved', 'format_spiffy'), $result['message']);
        $this->assertSame(
            'Remember the second option',
            $DB->get_field('format_spiffy_note', 'note', ['cmid' => $this->choice->cmid, 'userid' => $this->student->id])
        );

        save_note::execute($this->choice->cmid, 'Changed');
        $this->assertSame(1, $DB->count_records('format_spiffy_note', ['userid' => $this->student->id]));

        tools::reset_caches();
        $context = tools::export_card($this->cm($this->student), handler::STATE_AVAILABLE, $this->renderer());
        $this->assertTrue($context['hasnote']);
        $this->assertSame('Changed', $context['note']);

        $result = save_note::execute($this->choice->cmid, '');
        $this->assertSame(get_string('noteremoved', 'format_spiffy'), $result['message']);
        $this->assertFalse($DB->record_exists('format_spiffy_note', ['userid' => $this->student->id]));

        $this->expectException(\moodle_exception::class);
        save_note::execute($this->choice->cmid, str_repeat('a', tools::NOTE_MAXLENGTH + 1));
    }

    /**
     * Only a student can save notes; a teacher of the course cannot.
     */
    public function test_save_note_refused_for_teacher(): void {
        $this->create_course();
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        save_note::execute($this->choice->cmid, 'Mine');
    }

    /**
     * The confidence check needs a done activity, and teachers see the totals.
     */
    public function test_set_confidence(): void {
        global $DB;
        $this->create_course();
        $this->setUser($this->student);

        try {
            set_confidence::execute($this->choice->cmid, 3);
            $this->fail('Confidence was accepted before the activity was done');
        } catch (\moodle_exception $e) {
            $this->assertSame('confidencenotdone', $e->errorcode);
        }

        $this->complete_choice();
        $result = external_api::clean_returnvalue(
            set_confidence::execute_returns(),
            set_confidence::execute($this->choice->cmid, 3)
        );
        $this->assertNotEmpty($result['message']);
        set_confidence::execute($this->choice->cmid, 2);
        $this->assertSame(2, (int)$DB->get_field(
            'format_spiffy_confidence',
            'confidence',
            ['cmid' => $this->choice->cmid, 'userid' => $this->student->id]
        ));

        $this->assertSame(['1' => 0, '2' => 1, '3' => 0, 'total' => 1], tools::confidence_summary($this->choice->cmid));

        $this->expectException(\invalid_parameter_exception::class);
        set_confidence::execute($this->choice->cmid, 4);
    }

    /**
     * A question goes to the teacher as a personal message with the activity's name and link, and
     * only the fact that it was sent is logged.
     */
    public function test_ask_teacher(): void {
        global $DB;
        $this->create_course();
        $this->setUser($this->student);
        $sink = $this->redirectMessages();

        $result = external_api::clean_returnvalue(
            ask_teacher::execute_returns(),
            ask_teacher::execute($this->choice->cmid, $this->teacher->id, 'Which option is right?')
        );
        $this->assertSame(get_string('questionsent', 'format_spiffy', 'Terry Teacher'), $result['message']);

        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($this->student->id, $message->useridfrom);
        $conversationid = \core_message\api::get_conversation_between_users([$this->student->id, $this->teacher->id]);
        $this->assertEquals($conversationid, $message->conversationid);
        $this->assertStringContainsString('Pick one', $message->fullmessage);
        $this->assertStringContainsString('/mod/choice/view.php?id=' . $this->choice->cmid, $message->fullmessage);
        $this->assertStringContainsString('Which option is right?', $message->fullmessage);

        $log = $DB->get_record('format_spiffy_question', ['cmid' => $this->choice->cmid], '*', MUST_EXIST);
        $this->assertEquals($this->student->id, $log->userid);
        $this->assertEquals($this->teacher->id, $log->teacherid);
        $this->assertEquals(0, $log->timeseen);
        $this->assertObjectNotHasProperty('message', $log);
    }

    /**
     * A question to someone who is not one of the card's teachers, or an empty one, is refused.
     */
    public function test_ask_teacher_refused(): void {
        global $DB;
        $this->create_course();
        $classmate = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($classmate->id, $this->course->id, 'student');
        $this->setUser($this->student);

        foreach ([[$classmate->id, 'Hello'], [$this->teacher->id, '   ']] as [$teacherid, $text]) {
            try {
                ask_teacher::execute($this->choice->cmid, $teacherid, $text);
                $this->fail('The question was sent');
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['cannotaskteacher', 'questionempty']);
            }
        }
        $this->assertSame(0, $DB->count_records('format_spiffy_question'));
    }

    /**
     * In a separate-groups course a student can ask the teachers of their own group, and teachers
     * who see all groups and belong to none.
     */
    public function test_teachers_in_separate_groups(): void {
        $generator = $this->getDataGenerator();
        $this->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $other = $generator->create_user(['firstname' => 'Olga', 'lastname' => 'Other']);
        $head = $generator->create_user(['firstname' => 'Hana', 'lastname' => 'Head']);
        $generator->enrol_user($other->id, $this->course->id, 'editingteacher');
        $generator->enrol_user($head->id, $this->course->id, 'editingteacher');
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->student->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->teacher->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $other->id]);

        $this->setUser($this->student);
        $teachers = tools::get_teachers($this->cm($this->student));
        $this->assertEqualsCanonicalizing([(int)$this->teacher->id, (int)$head->id], array_keys($teachers));

        $context = tools::export_card($this->cm($this->student), handler::STATE_AVAILABLE, $this->renderer());
        $this->assertTrue($context['multipleteachers']);

        $this->expectException(\moodle_exception::class);
        ask_teacher::execute($this->choice->cmid, $other->id, 'Hello');
    }

    /**
     * With user data, a backup carries the notes and confidence checks; without, it does not.
     */
    public function test_backup_and_restore_user_data(): void {
        global $DB;
        $this->create_course();
        $this->setUser($this->student);
        save_note::execute($this->choice->cmid, 'My note');
        $this->complete_choice();
        set_confidence::execute($this->choice->cmid, 1);
        $this->setAdminUser();

        $withdata = $this->backup_and_restore(true);
        $newcm = get_fast_modinfo($withdata)->get_instances_of('choice');
        $newcmid = (int)reset($newcm)->id;
        $this->assertSame('My note', $DB->get_field(
            'format_spiffy_note',
            'note',
            ['cmid' => $newcmid, 'userid' => $this->student->id, 'courseid' => $withdata]
        ));
        $this->assertSame(1, (int)$DB->get_field(
            'format_spiffy_confidence',
            'confidence',
            ['cmid' => $newcmid, 'userid' => $this->student->id, 'courseid' => $withdata]
        ));

        $withoutdata = $this->backup_and_restore(false);
        $this->assertSame(0, $DB->count_records('format_spiffy_note', ['courseid' => $withoutdata]));
        $this->assertSame(0, $DB->count_records('format_spiffy_confidence', ['courseid' => $withoutdata]));
    }

    /**
     * Back the course up and restore it into a new course, as in mod_h5pactivity's restore test.
     *
     * @param bool $userdata whether user data is included
     * @return int the new course id
     */
    private function backup_and_restore(bool $userdata): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $CFG->backup_file_logger_level = backup::LOG_NONE;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $this->course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($userdata);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED' . (int)$userdata, $this->course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($userdata);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * Ask my teacher stops after too many questions in an hour.
     */
    public function test_ask_teacher_rate_limit(): void {
        global $DB;
        $this->create_course();
        $this->setUser($this->student);
        $cm = $this->cm($this->student);
        foreach (range(1, tools::QUESTIONS_PER_HOUR) as $unused) {
            $DB->insert_record('format_spiffy_question', (object)['courseid' => $cm->course, 'cmid' => $cm->id,
                'userid' => $this->student->id, 'teacherid' => $this->teacher->id, 'timecreated' => time(), 'timeseen' => 0]);
        }
        $this->expectExceptionMessage(get_string('questiontoomany', 'format_spiffy'));
        tools::ask_teacher($cm, (int)$this->teacher->id, 'One more');
    }

    /**
     * Confidence results can be limited to some groups.
     */
    public function test_confidence_summary_groups(): void {
        global $DB;
        $this->create_course();
        $cm = $this->cm($this->student);
        $DB->insert_record('format_spiffy_confidence', (object)['courseid' => $cm->course, 'cmid' => $cm->id,
            'userid' => $this->student->id, 'confidence' => 3, 'timemodified' => time()]);
        $this->assertSame(1, tools::confidence_summary($cm->id)['total']);
        $this->assertSame(0, tools::confidence_summary($cm->id, [])['total']);
        $group = $this->getDataGenerator()->create_group(['courseid' => $cm->course]);
        $this->assertSame(0, tools::confidence_summary($cm->id, [(int)$group->id])['total']);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->student->id]);
        $this->assertSame(1, tools::confidence_summary($cm->id, [(int)$group->id])['total']);
    }
}
