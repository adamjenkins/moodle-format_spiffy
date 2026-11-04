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
use format_spiffy\local\inline\mod_lesson;
use format_spiffy\local\inline\registry;

/**
 * Tests for the lesson card: when it is stepped through inline, and answering its pages.
 *
 * @package    format_spiffy
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_lesson::class)]
final class inline_lesson_test extends advanced_testcase {
    /**
     * A Spiffy course with a student and a lesson of one content page followed by one
     * true/false question.
     *
     * @param array $settings lesson settings to change
     * @return array [course, lesson record, student, content page, question page]
     */
    private function setup_lesson(array $settings = []): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lesson/locallib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        // The lesson generator saves files, which needs a real user.
        $this->setAdminUser();
        $lesson = $generator->create_module('lesson', $settings + [
            'course' => $course->id,
            'feedback' => 1,
            'maxattempts' => 2,
            'retake' => 1,
            'modattempts' => 0,
        ]);
        $lessongenerator = $generator->get_plugin_generator('mod_lesson');
        // A content page with a "Next page" button, as the lesson editor makes them.
        $content = $lessongenerator->create_content($lesson, [
            'title' => 'Welcome page',
            'answer_editor' => [['text' => 'Next page', 'format' => FORMAT_MOODLE]],
            'jumpto' => [LESSON_NEXTPAGE],
        ]);
        $question = $lessongenerator->create_question_truefalse($lesson, ['title' => 'Water is wet', 'pageid' => $content->id]);
        return [$course, $lesson, $student, $content, $question];
    }

    /**
     * The card handler of an activity for the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return mod_lesson
     */
    private function handler(\stdClass $course, int $cmid): mod_lesson {
        $handler = registry::get_handler(get_fast_modinfo($course)->get_cm($cmid), cm_settings::get($cmid));
        $this->assertInstanceOf(mod_lesson::class, $handler);
        return $handler;
    }

    /**
     * The card as the current user sees it.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return string HTML
     */
    private function render(\stdClass $course, int $cmid): string {
        global $PAGE;
        return $this->handler($course, $cmid)->render($PAGE->get_renderer('core'));
    }

    /**
     * The answer of a question page that jumps to a page (correct) or stays (wrong).
     *
     * @param \stdClass $page
     * @param bool $correct
     * @return int answer id
     */
    private function answer(\stdClass $page, bool $correct): int {
        global $DB;
        foreach ($DB->get_records('lesson_answers', ['pageid' => $page->id]) as $answer) {
            if (((int)$answer->jumpto === LESSON_THISPAGE) !== $correct) {
                return (int)$answer->id;
            }
        }
        $this->fail('No such answer.');
    }

    /**
     * A simple lesson is stepped through inline by students, not by teachers.
     */
    public function test_inline_for_simple_lesson(): void {
        [$course, $lesson, $student] = $this->setup_lesson();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $this->setUser($student);
        $this->assertTrue($this->handler($course, $lesson->cmid)->is_inline());

        $this->setUser($teacher);
        $this->assertFalse($this->handler($course, $lesson->cmid)->is_inline());
    }

    /**
     * Passwords, time limits, essays and random jumps open the lesson page instead.
     */
    public function test_not_inline_for_complex_lessons(): void {
        [$course, $password, $student] = $this->setup_lesson(['usepassword' => 1, 'password' => 'secret']);
        $lessongenerator = $this->getDataGenerator()->get_plugin_generator('mod_lesson');

        $timed = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id, 'timelimit' => 600]);
        $lessongenerator->create_content($timed);

        $essay = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $lessongenerator->create_question_essay($essay);

        $random = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);
        $lessongenerator->create_question_truefalse($random, ['jumpto' => [LESSON_RANDOMPAGE, LESSON_THISPAGE]]);

        $empty = $this->getDataGenerator()->create_module('lesson', ['course' => $course->id]);

        $this->setUser($student);
        foreach ([$password, $timed, $essay, $random, $empty] as $lesson) {
            $this->assertFalse($this->handler($course, $lesson->cmid)->is_inline(), $lesson->name);
        }
    }

    /**
     * Continue on the content page, answer wrong, then right: the card follows the lesson and is
     * done at its end, with a way to try again.
     */
    public function test_step_through_and_answer(): void {
        global $DB;
        [$course, $lesson, $student, $content, $question] = $this->setup_lesson();
        $this->setUser($student);

        $html = $this->render($course, $lesson->cmid);
        $this->assertStringContainsString('Welcome page', $html);
        $this->assertNull($this->handler($course, $lesson->cmid)
            ->process_action('continue', ['pageid' => (string)$content->id, 'jumpto' => (string)LESSON_NEXTPAGE]));
        $this->assertTrue($DB->record_exists('lesson_branch', ['lessonid' => $lesson->id, 'userid' => $student->id,
            'pageid' => $content->id]));

        $html = $this->render($course, $lesson->cmid);
        $this->assertStringContainsString('Water is wet', $html);

        // A wrong answer with an attempt left stays on the question.
        $this->handler($course, $lesson->cmid)
            ->process_action('answer', ['pageid' => (string)$question->id, 'answerid' => (string)$this->answer($question, false)]);
        $this->assertTrue($DB->record_exists('lesson_attempts', ['lessonid' => $lesson->id, 'userid' => $student->id,
            'pageid' => $question->id, 'correct' => 0]));
        $handler = $this->handler($course, $lesson->cmid);
        $this->assertFalse($handler->is_done());
        $this->assertStringContainsString('Water is wet', $this->render($course, $lesson->cmid));

        // The right answer ends the lesson.
        $message = $this->handler($course, $lesson->cmid)
            ->process_action('answer', ['pageid' => (string)$question->id, 'answerid' => (string)$this->answer($question, true)]);
        $this->assertSame(get_string('lessonfinished', 'format_spiffy'), $message);
        $this->assertTrue($DB->record_exists('lesson_grades', ['lessonid' => $lesson->id, 'userid' => $student->id]));
        $this->assertTrue($this->handler($course, $lesson->cmid)->is_done());
        $this->assertStringContainsString(get_string('lessonretake', 'format_spiffy'), $this->render($course, $lesson->cmid));

        // Trying again starts a new attempt on the first page.
        $this->handler($course, $lesson->cmid)->process_action('start', []);
        $this->assertFalse($this->handler($course, $lesson->cmid)->is_done());
        $this->assertStringContainsString('Welcome page', $this->render($course, $lesson->cmid));
    }

    /**
     * An answer for a page the user is not on is refused, so a stale card cannot skip ahead.
     */
    public function test_stale_page_is_refused(): void {
        [$course, $lesson, $student, , $question] = $this->setup_lesson();
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->handler($course, $lesson->cmid)
            ->process_action('answer', ['pageid' => (string)$question->id, 'answerid' => (string)$this->answer($question, true)]);
    }
}
