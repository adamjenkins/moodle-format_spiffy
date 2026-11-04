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
use core_external\external_api;
use format_spiffy\external\mark_questions_seen;
use format_spiffy\external\message_unfinished;
use format_spiffy\local\teacher;
use stdClass;

/**
 * Tests for the teacher side: the teacher spotlight, the teacher parts of a card and their web services.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(teacher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(message_unfinished::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mark_questions_seen::class)]
final class teacher_test extends advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/completionlib.php');
        teacher::reset_caches();
    }

    /**
     * A Spiffy course with completion, a forum (manual completion), an editing teacher, a
     * non-editing teacher and a student.
     *
     * @return array [course, forum cm_info, editingteacher, teacher, student]
     */
    private function setup_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $editor = $generator->create_and_enrol($course, 'editingteacher');
        $teacher = $generator->create_and_enrol($course, 'teacher');
        $student = $generator->create_and_enrol($course, 'student');
        return [$course, get_fast_modinfo($course)->get_cm($forum->cmid), $editor, $teacher, $student];
    }

    /**
     * The activity again from the current user's modinfo.
     *
     * @param stdClass $course
     * @param int $cmid
     * @return \cm_info
     */
    private function cm(stdClass $course, int $cmid): \cm_info {
        teacher::reset_caches();
        return get_fast_modinfo($course)->get_cm($cmid);
    }

    /**
     * Mark an activity complete for a user (manual completion).
     *
     * @param stdClass $course
     * @param \cm_info $cm
     * @param int $userid
     * @param int|null $time when, to backdate it
     * @return void
     */
    private function complete(stdClass $course, \cm_info $cm, int $userid, ?int $time = null): void {
        global $DB;
        (new completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $userid);
        if ($time !== null) {
            $DB->set_field(
                'course_modules_completion',
                'timemodified',
                $time,
                ['coursemoduleid' => $cm->id, 'userid' => $userid]
            );
        }
    }

    public function test_export_card_is_for_teachers_only(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, $cm, $editor, $teacher, $student] = $this->setup_course();
        $output = $PAGE->get_renderer('core');

        $this->setUser($student);
        $this->assertSame([], teacher::export_card($this->cm($course, $cm->id), $output));

        // An editing teacher gets the pencil menu with the everyday edit actions.
        $this->setUser($editor);
        $card = teacher::export_card($this->cm($course, $cm->id), $output);
        $this->assertTrue($card['hasmenu']);
        $urls = [];
        foreach ($card['menu']['groups'] as $group) {
            foreach ($group['items'] as $item) {
                $urls[] = $item['url'];
            }
        }
        $all = implode(' ', $urls);
        $this->assertStringContainsString('/course/modedit.php?update=' . $cm->id, $all);
        $this->assertStringContainsString('#id_format_spiffy_header', $all);
        $this->assertStringContainsString('action=cm_hide', $all);
        $this->assertStringContainsString('action=cm_duplicate', $all);
        $this->assertStringContainsString('action=cm_delete', $all);
        $this->assertStringContainsString('/admin/roles/assign.php', $all);
        // One student has not finished, and the teacher may message them.
        $this->assertTrue($card['canmessage']);
        $this->assertSame(1, $card['notfinished']);

        // A non-editing teacher sees the counts but gets no edit actions.
        $this->setUser($teacher);
        $card = teacher::export_card($this->cm($course, $cm->id), $output);
        $this->assertFalse($card['hasmenu']);
        $this->assertTrue($card['hasbadges']);
    }

    public function test_export_card_counts_work_to_grade(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, , $editor, , $student] = $this->setup_course();
        $generator = $this->getDataGenerator();
        $assign = $generator->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'submissiondrafts' => 0,
            'duedate' => time() + WEEKSECS,
        ]);
        /** @var \mod_assign_generator $assigngenerator */
        $assigngenerator = $generator->get_plugin_generator('mod_assign');
        $assigngenerator->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->cmid,
            'status' => 'submitted',
            'onlinetext' => 'My plan',
            'assignsubmission_onlinetext_enabled' => 1,
        ]);

        $this->setUser($editor);
        $output = $PAGE->get_renderer('core');
        $card = teacher::export_card($this->cm($course, $assign->cmid), $output);
        $kinds = array_column($card['badges'], 'label', 'kind');
        $this->assertSame(get_string('teacherneedsgrading', 'format_spiffy', 1), $kinds['grading']);
        $actionurls = implode(' ', array_column($card['actions'], 'url'));
        $this->assertStringContainsString('action=grader', $actionurls);
        $this->assertStringContainsString('/mod/assign/overrides.php', $actionurls);

        $page = teacher::export_page(course_get_format($course), new stdClass(), $output);
        $this->assertTrue($page['hasitems']);
        $this->assertSame((int)$assign->cmid, $page['items'][0]['cmid']);
        $this->assertSame('grading', $page['items'][0]['kind']);
    }

    public function test_export_page_gating_and_preview(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        [$course, $cm, $editor, , $student] = $this->setup_course();
        $output = $PAGE->get_renderer('core');
        $format = course_get_format($course);

        $this->setUser($student);
        $this->assertSame([], teacher::export_page($format, new stdClass(), $output));

        $this->setUser($editor);
        $page = teacher::export_page($format, new stdClass(), $output);
        $this->assertFalse($page['isswitched']);
        $this->assertFalse($page['hasitems']);
        $this->assertNotNull($page['preview']);
        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $this->assertStringContainsString('switchrole=' . $studentrole->id, $page['preview']['url']);

        // While previewing as a student, only the way back is shown.
        role_switch($studentrole->id, \core\context\course::instance($course->id));
        teacher::reset_caches();
        $page = teacher::export_page($format, new stdClass(), $output);
        $this->assertTrue($page['isswitched']);
        $this->assertStringContainsString('switchrole=0', $page['returnurl']);
        $this->assertSame([], teacher::export_card($this->cm($course, $cm->id), $output));
    }

    public function test_stuck_students(): void {
        global $CFG, $PAGE;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        [$course, $first, $editor, , $student] = $this->setup_course();
        $generator = $this->getDataGenerator();
        $second = $generator->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
            'availability' => json_encode(['op' => '&', 'showc' => [true],
                'c' => [['type' => 'completion', 'cm' => (int)$first->id, 'e' => COMPLETION_COMPLETE]]]),
        ]);
        $recent = $generator->create_and_enrol($course, 'student');
        $this->complete($course, $first, $student->id, time() - (teacher::STUCK_DAYS + 1) * DAYSECS);
        $this->complete($course, $first, $recent->id);

        $this->setUser($editor);
        $counts = teacher::load($course);
        $this->assertSame(1, $counts[(int)$second->cmid]['stuck']);
        $this->assertSame(2, $counts[(int)$second->cmid]['notfinished']);
        $this->assertSame(0, $counts[(int)$first->id]['stuck']);
        $this->assertSame(0, $counts[(int)$first->id]['notfinished']);

        $page = teacher::export_page(course_get_format($course), new stdClass(), $PAGE->get_renderer('core'));
        $this->assertSame('stuck', $page['items'][0]['kind']);
    }

    public function test_questions_and_mark_seen(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        [$course, $cm, $editor, $teacher, $student] = $this->setup_course();
        $question = ['courseid' => $course->id, 'cmid' => $cm->id, 'userid' => $student->id, 'timecreated' => time(),
            'timeseen' => 0];
        $DB->insert_record('format_spiffy_question', (object)($question + ['teacherid' => $editor->id]));
        $other = $DB->insert_record('format_spiffy_question', (object)($question + ['teacherid' => $teacher->id]));

        $this->setUser($editor);
        $card = teacher::export_card($this->cm($course, $cm->id), $PAGE->get_renderer('core'));
        $this->assertTrue($card['hasquestions']);
        $this->assertCount(1, $card['askers']);
        $this->assertSame(fullname($student), $card['askers'][0]['fullname']);

        $result = external_api::clean_returnvalue(
            mark_questions_seen::execute_returns(),
            mark_questions_seen::execute($cm->id)
        );
        $this->assertSame(1, $result['count']);
        $this->assertSame(0, $DB->count_records('format_spiffy_question', ['teacherid' => $editor->id, 'timeseen' => 0]));
        // Another teacher's question stays new.
        $this->assertEquals(0, $DB->get_field('format_spiffy_question', 'timeseen', ['id' => $other]));

        $card = teacher::export_card($this->cm($course, $cm->id), $PAGE->get_renderer('core'));
        $this->assertFalse($card['hasquestions']);
    }

    public function test_message_unfinished_recipients(): void {
        $this->resetAfterTest();
        [$course, $cm, $editor, , $student] = $this->setup_course();
        $generator = $this->getDataGenerator();
        $finished = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->complete($course, $cm, $finished->id);

        $this->setUser($editor);
        $recipients = teacher::get_unfinished_recipients($this->cm($course, $cm->id));
        $this->assertSame([(int)$student->id], array_map('intval', array_keys($recipients)));

        // The dry run only counts.
        $sink = $this->redirectMessages();
        $result = external_api::clean_returnvalue(
            message_unfinished::execute_returns(),
            message_unfinished::execute($cm->id, 'Please finish the forum task.', true)
        );
        $this->assertSame(1, $result['count']);
        $this->assertSame(0, $sink->count());

        $result = external_api::clean_returnvalue(
            message_unfinished::execute_returns(),
            message_unfinished::execute($cm->id, 'Please finish the forum task.', false)
        );
        $this->assertSame(1, $result['count']);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($student->id, $messages[0]->useridto);
        $this->assertEquals($editor->id, $messages[0]->useridfrom);
        $this->assertStringContainsString('Please finish the forum task.', $messages[0]->fullmessage);
        $sink->close();
    }

    public function test_message_unfinished_needs_bulk_messaging(): void {
        $this->resetAfterTest();
        [, $cm, , , $student] = $this->setup_course();
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        message_unfinished::execute($cm->id, 'Hello', false);
    }

    public function test_message_unfinished_needs_a_message(): void {
        $this->resetAfterTest();
        [, $cm, $editor] = $this->setup_course();
        $this->setUser($editor);
        $this->expectException(\moodle_exception::class);
        message_unfinished::execute($cm->id, '   ', false);
    }

    /**
     * A teacher cannot message the same activity's students twice within a minute.
     */
    public function test_message_repeat_guard(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();
        $course = $generator->create_course(['format' => 'spiffy', 'enablecompletion' => 1, 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $generator->create_and_enrol($course, 'student');
        $this->setUser($teacher);
        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        teacher::message_unfinished($cm, 'Please finish this.');
        $this->expectExceptionMessage(get_string('teachermessagetoorecent', 'format_spiffy'));
        teacher::message_unfinished($cm, 'Please finish this.');
    }

    /**
     * The Teacher tools switch also stops its web services.
     */
    public function test_switch_stops_web_services(): void {
        $generator = $this->getDataGenerator();
        $this->resetAfterTest();
        $course = $generator->create_course(['format' => 'spiffy', 'enablecompletion' => 1, 'summary' => '']);
        course_get_format($course)->update_course_format_options(['featureteachertools' => 0]);
        $page = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $this->expectException(\moodle_exception::class);
        \format_spiffy\external\mark_questions_seen::execute($page->cmid);
    }
}
