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
use format_spiffy\local\inline\mod_assign;
use format_spiffy\local\inline\registry;

/**
 * Tests for the assignment card: when it is answered inline, and saving and submitting.
 *
 * @package    format_spiffy
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_assign::class)]
final class inline_assign_test extends advanced_testcase {
    /**
     * A Spiffy course with a student and an online text assignment.
     *
     * @param array $settings assignment settings to change
     * @return array [course, assignment record, student]
     */
    private function setup_assign(array $settings = []): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $assign = $generator->create_module('assign', $settings + [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'assignfeedback_comments_enabled' => 1,
            'submissiondrafts' => 0,
        ]);
        return [$course, $assign, $student];
    }

    /**
     * The card handler of an activity for the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return mod_assign
     */
    private function handler(\stdClass $course, int $cmid): mod_assign {
        $handler = registry::get_handler(get_fast_modinfo($course)->get_cm($cmid), cm_settings::get($cmid));
        $this->assertInstanceOf(mod_assign::class, $handler);
        return $handler;
    }

    /**
     * The current user's latest submission.
     *
     * @param \stdClass $assign
     * @return \stdClass|false
     */
    private function submission(\stdClass $assign): \stdClass|false {
        global $DB, $USER;
        $records = $DB->get_records(
            'assign_submission',
            ['assignment' => $assign->id, 'userid' => $USER->id],
            'attemptnumber DESC',
            '*',
            0,
            1
        );
        return reset($records);
    }

    /**
     * An online text only assignment is answered inline by students, not by teachers.
     */
    public function test_inline_for_online_text_only(): void {
        [$course, $assign, $student] = $this->setup_assign();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($student);
        $this->assertTrue($this->handler($course, $assign->cmid)->is_inline());

        $this->setUser($teacher);
        $this->assertFalse($this->handler($course, $assign->cmid)->is_inline());
    }

    /**
     * File submissions, team submissions and time limits open the assignment page instead.
     */
    public function test_not_inline_for_other_settings(): void {
        [$course, $files, $student] = $this->setup_assign(['assignsubmission_file_enabled' => 1]);
        $team = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'teamsubmission' => 1,
        ]);
        $nothing = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_file_enabled' => 0,
        ]);

        $this->setUser($student);
        $this->assertFalse($this->handler($course, $files->cmid)->is_inline());
        $this->assertFalse($this->handler($course, $team->cmid)->is_inline());
        $this->assertFalse($this->handler($course, $nothing->cmid)->is_inline());
    }

    /**
     * Before submissions open and after the cut-off date the card is not a form; an extension
     * reopens it.
     */
    public function test_not_inline_outside_dates(): void {
        [$course, $assign, $student] = $this->setup_assign([
            'duedate' => time() - 2 * DAYSECS,
            'cutoffdate' => time() - DAYSECS,
        ]);
        $later = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 0,
            'allowsubmissionsfromdate' => time() + DAYSECS,
        ]);

        $this->setUser($student);
        $this->assertFalse($this->handler($course, $assign->cmid)->is_inline());
        $this->assertFalse($this->handler($course, $later->cmid)->is_inline());

        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_extension([
            'cmid' => $assign->cmid,
            'userid' => $student->id,
            'extensionduedate' => time() + DAYSECS,
        ]);
        $this->setUser($student);
        $this->assertTrue($this->handler($course, $assign->cmid)->is_inline());
    }

    /**
     * A draft written in the full editor is edited on the assignment page, so nothing is lost.
     */
    public function test_not_inline_for_html_draft(): void {
        [$course, $assign, $student] = $this->setup_assign(['submissiondrafts' => 1]);
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $assign->cmid,
            'status' => 'draft',
            'onlinetext' => '<p>My <strong>formatted</strong> draft</p>',
        ]);

        $this->setUser($student);
        $this->assertFalse($this->handler($course, $assign->cmid)->is_inline());
    }

    /**
     * Without "require students to click submit", saving submits and the card is done.
     */
    public function test_save_submits_without_drafts(): void {
        global $DB;
        [$course, $assign, $student] = $this->setup_assign();
        $this->setUser($student);

        $handler = $this->handler($course, $assign->cmid);
        $this->assertFalse($handler->is_done());
        $message = $handler->process_action('save', ['text' => 'My short answer']);
        $this->assertSame(get_string('assignsubmitted', 'format_spiffy'), $message);

        $submission = $this->submission($assign);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $submission->status);
        $text = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
        $this->assertSame('My short answer', $text->onlinetext);
        $this->assertEquals(FORMAT_PLAIN, $text->onlineformat);
        $this->assertTrue($this->handler($course, $assign->cmid)->is_done());
    }

    /**
     * With drafts, Save keeps a draft and Submit submits it.
     */
    public function test_save_draft_then_submit(): void {
        [$course, $assign, $student] = $this->setup_assign(['submissiondrafts' => 1]);
        $this->setUser($student);

        $message = $this->handler($course, $assign->cmid)->process_action('save', ['text' => 'First go']);
        $this->assertSame(get_string('assigndraftsaved', 'format_spiffy'), $message);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_DRAFT, $this->submission($assign)->status);
        $handler = $this->handler($course, $assign->cmid);
        $this->assertFalse($handler->is_done());
        $this->assertTrue($handler->is_inline());

        $this->handler($course, $assign->cmid)->process_action('save', ['text' => 'Final answer', 'submit' => '1']);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $this->submission($assign)->status);
        $handler = $this->handler($course, $assign->cmid);
        $this->assertTrue($handler->is_done());
        // Once submitted with drafts on, only the teacher can reopen it.
        $this->assertFalse($handler->is_inline());
    }

    /**
     * Submitting needs the submission statement when the assignment asks for it.
     */
    public function test_submit_needs_statement(): void {
        [$course, $assign, $student] = $this->setup_assign(['submissiondrafts' => 1, 'requiresubmissionstatement' => 1]);
        set_config('submissionstatement', 'This is my own work.', 'assign');
        $this->setUser($student);

        try {
            $this->handler($course, $assign->cmid)->process_action('submit', ['text' => 'My answer']);
            $this->fail('Submitting without the statement must fail.');
        } catch (\moodle_exception $e) {
            $this->assertSame('submissionstatementrequired', $e->errorcode);
        }
        $this->assertFalse($this->submission($assign));

        $this->handler($course, $assign->cmid)->process_action('submit', ['text' => 'My answer', 'statement' => '1']);
        $this->assertSame(ASSIGN_SUBMISSION_STATUS_SUBMITTED, $this->submission($assign)->status);
    }

    /**
     * An empty answer is not saved, and unknown actions are refused.
     */
    public function test_empty_and_unknown_actions_fail(): void {
        [$course, $assign, $student] = $this->setup_assign();
        $this->setUser($student);

        try {
            $this->handler($course, $assign->cmid)->process_action('save', ['text' => '   ']);
            $this->fail('An empty answer must not be saved.');
        } catch (\moodle_exception $e) {
            $this->assertSame('assignnotsaved', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        $this->handler($course, $assign->cmid)->process_action('remove', []);
    }

    /**
     * The done card shows the answer, and the grade and feedback once the teacher has graded.
     */
    public function test_done_view_shows_grade_and_feedback(): void {
        global $PAGE;
        [$course, $assign, $student] = $this->setup_assign();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($student);
        $this->handler($course, $assign->cmid)->process_action('save', ['text' => 'My graded answer']);

        $this->setUser($teacher);
        $cm = get_coursemodule_from_id('assign', $assign->cmid);
        $instance = new \assign(\context_module::instance($assign->cmid), $cm, $course);
        $instance->save_grade($student->id, (object)[
            'attemptnumber' => -1,
            'grade' => '80',
            'assignfeedbackcomments_editor' => ['text' => 'Well argued.', 'format' => FORMAT_HTML],
        ]);

        $this->setUser($student);
        $html = $this->handler($course, $assign->cmid)->render($PAGE->get_renderer('core'));
        $this->assertStringContainsString('My graded answer', $html);
        $this->assertStringContainsString('Well argued.', $html);
        $this->assertStringContainsString('80.00', $html);
    }
}
