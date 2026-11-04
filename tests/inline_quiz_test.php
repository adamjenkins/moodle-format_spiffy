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
use format_spiffy\local\inline\mod_quiz;
use format_spiffy\local\inline\registry;
use mod_quiz\quiz_attempt;

/**
 * Tests for the inline Quiz handler.
 *
 * Questions go into a standard question bank made in the test course, never the System shared one.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\inline\mod_quiz::class)]
final class inline_quiz_test extends advanced_testcase {
    /** @var \stdClass the course */
    private \stdClass $course;

    /** @var \stdClass the student */
    private \stdClass $student;

    /** @var \stdClass the question category, in a standard question bank of the course */
    private \stdClass $category;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $qbank = $generator->create_module('qbank', [
            'course' => $this->course->id,
            'type' => \core_question\local\bank\question_bank_helper::TYPE_STANDARD,
        ]);
        $this->category = $generator->get_plugin_generator('core_question')->create_question_category(
            ['contextid' => \context_module::instance($qbank->cmid)->id]
        );
    }

    /**
     * Make a quiz with questions.
     *
     * @param string $qtype question type
     * @param string|null $which test question name of that type
     * @param array $settings quiz settings
     * @param int $count number of questions
     * @return \stdClass the quiz record, with cmid
     */
    private function create_quiz(string $qtype, ?string $which, array $settings = [], int $count = 1): \stdClass {
        $quiz = $this->getDataGenerator()->create_module('quiz', $settings + [
            'course' => $this->course->id,
            'grade' => 10,
            'sumgrades' => $count,
            'questionsperpage' => 0,
        ]);
        $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
        for ($i = 0; $i < $count; $i++) {
            $question = $questions->create_question($qtype, $which, ['category' => $this->category->id]);
            quiz_add_quiz_question($question->id, $quiz);
        }
        return $quiz;
    }

    /**
     * The id of the right answer of the quiz's question.
     *
     * @param \stdClass $quiz
     * @return int question_answers id
     */
    private function right_answer(\stdClass $quiz): int {
        global $DB;
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        $quizobj->preload_questions();
        $questions = $quizobj->get_questions(null, false);
        $question = reset($questions);
        $answers = $DB->get_records('question_answers', ['question' => $question->questionid], 'fraction DESC', 'id', 0, 1);
        return (int)array_key_first($answers);
    }

    /**
     * The handler of a quiz for the current user, from fresh modinfo.
     *
     * @param \stdClass $quiz
     * @return mod_quiz
     */
    private function handler(\stdClass $quiz): mod_quiz {
        get_fast_modinfo($this->course->id, 0, true);
        $cm = get_fast_modinfo($this->course->id)->get_cm($quiz->cmid);
        $handler = registry::get_handler($cm, cm_settings::get($quiz->cmid));
        $this->assertInstanceOf(mod_quiz::class, $handler);
        return $handler;
    }

    /**
     * Render a handler's card inside.
     *
     * @param mod_quiz $handler
     * @return string
     */
    private function render(mod_quiz $handler): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        return $handler->render($PAGE->get_renderer('core'));
    }

    /**
     * The current user's attempts at a quiz.
     *
     * @param \stdClass $quiz
     * @return \stdClass[]
     */
    private function attempts(\stdClass $quiz): array {
        global $USER;
        return array_values(quiz_get_user_attempts($quiz->id, $USER->id, 'all'));
    }

    /**
     * Data provider: the question types answered on the card.
     *
     * @return array[] qtype, test question, right answer (null: the right option's id)
     */
    public static function question_provider(): array {
        return [
            'multichoice' => ['multichoice', 'one_of_four', null],
            'truefalse' => ['truefalse', 'true', '1'],
            'shortanswer' => ['shortanswer', 'frogtoad', 'frog'],
            'numerical' => ['numerical', 'pi', '3.14'],
        ];
    }

    /**
     * A one-question quiz is answered on the card: one action starts, submits and grades an attempt.
     *
     * @param string $qtype
     * @param string $which
     * @param string|null $right
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('question_provider')]
    public function test_answer_right(string $qtype, string $which, ?string $right): void {
        $quiz = $this->create_quiz($qtype, $which, ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1]);
        $this->setUser($this->student);

        $handler = $this->handler($quiz);
        $this->assertTrue($handler->is_inline());
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());
        $this->assertSame([], $handler->get_badges());
        $this->assertStringContainsString('data-spiffy-action="answer"', $this->render($handler));

        $answer = $right ?? (string)$this->right_answer($quiz);
        $message = $handler->process_action('answer', ['answer' => $answer]);
        $this->assertSame(get_string('quizanswersaved', 'format_spiffy'), $message);

        $attempts = $this->attempts($quiz);
        $this->assertCount(1, $attempts);
        $this->assertSame(quiz_attempt::FINISHED, $attempts[0]->state);
        $this->assertEquals(1, (float)$attempts[0]->sumgrades);

        $handler = $this->handler($quiz);
        $this->assertTrue($handler->is_done());
        $this->assertTrue(handler::is_complete($handler->get_cm()));
        $badges = $handler->get_badges();
        $this->assertCount(1, $badges);
        $this->assertStringContainsString('10.00/10.00', $badges[0]['text']);
        $html = $this->render($handler);
        $this->assertStringContainsString('spiffy-quiz-done', $html);
        $this->assertStringContainsString('10.00/10.00', $html);
        $this->assertStringContainsString('data-spiffy-action="answer"', $html, 'Unlimited attempts: try again is offered.');
    }

    /**
     * A wrong answer is graded zero, and with one attempt allowed the form is gone.
     */
    public function test_answer_wrong_last_attempt(): void {
        $quiz = $this->create_quiz('truefalse', 'true', ['attempts' => 1]);
        $this->setUser($this->student);

        $handler = $this->handler($quiz);
        $this->assertStringContainsString(get_string('quizattemptsleft', 'format_spiffy', 1), $this->render($handler));
        $handler->process_action('answer', ['answer' => '0']);

        $attempts = $this->attempts($quiz);
        $this->assertEquals(0, (float)$attempts[0]->sumgrades);
        $handler = $this->handler($quiz);
        $this->assertTrue($handler->is_done());
        $this->assertFalse($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringContainsString('0.00/10.00', $html);
        $this->assertStringNotContainsString('data-spiffy-action="answer"', $html);

        $this->expectException(\moodle_exception::class);
        $handler->process_action('answer', ['answer' => '1']);
    }

    /**
     * Marks stay hidden when the review options hide them.
     */
    public function test_marks_hidden_by_review_options(): void {
        $hidden = [];
        foreach (['immediately', 'open', 'closed'] as $when) {
            $hidden['marks' . $when] = 0;
            $hidden['maxmarks' . $when] = 0;
        }
        $quiz = $this->create_quiz('truefalse', 'true', $hidden);
        $this->setUser($this->student);

        $this->handler($quiz)->process_action('answer', ['answer' => '1']);
        $handler = $this->handler($quiz);
        $this->assertSame([], $handler->get_badges());
        $this->assertStringNotContainsString('10.00/10.00', $this->render($handler));
    }

    /**
     * An empty or unknown answer is refused before any attempt starts.
     */
    public function test_bad_answer_leaves_no_attempt(): void {
        $quiz = $this->create_quiz('multichoice', 'one_of_four');
        $this->setUser($this->student);

        foreach (['', '999999'] as $answer) {
            try {
                $this->handler($quiz)->process_action('answer', ['answer' => $answer]);
                $this->fail('The answer must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('quizanswerrequired', $e->errorcode);
            }
        }
        $this->assertSame([], $this->attempts($quiz));
    }

    /**
     * Quizzes the card cannot handle open their page.
     */
    public function test_not_inline(): void {
        $this->setUser($this->student);
        $cases = [
            'two questions' => $this->create_quiz('truefalse', 'true', [], 2),
            'several answers' => $this->create_quiz('multichoice', 'two_of_four'),
            'password' => $this->create_quiz('truefalse', 'true', ['quizpassword' => 'secret']),
            'time limit' => $this->create_quiz('truefalse', 'true', ['timelimit' => 600]),
            'closed' => $this->create_quiz('truefalse', 'true', ['timeclose' => time() - DAYSECS]),
            'not open yet' => $this->create_quiz('truefalse', 'true', ['timeopen' => time() + DAYSECS]),
        ];
        foreach ($cases as $case => $quiz) {
            $this->assertFalse($this->handler($quiz)->is_inline(), $case);
        }
    }

    /**
     * An attempt started on the quiz page is finished there.
     */
    public function test_attempt_in_progress_is_not_inline(): void {
        $quiz = $this->create_quiz('truefalse', 'true');
        // The generator starts the attempt as $USER.
        $this->setUser($this->student);
        $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $this->student->id);
        $this->assertFalse($this->handler($quiz)->is_inline());
    }

    /**
     * A user who cannot attempt the quiz gets no form.
     */
    public function test_without_capability_is_not_inline(): void {
        global $DB;
        $quiz = $this->create_quiz('truefalse', 'true');
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/quiz:attempt', CAP_PROHIBIT, $studentrole, \context_module::instance($quiz->cmid));
        $this->setUser($this->student);
        $this->assertFalse($this->handler($quiz)->is_inline());
    }

    /**
     * An unknown action is refused.
     */
    public function test_unknown_action(): void {
        $quiz = $this->create_quiz('truefalse', 'true');
        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        $this->handler($quiz)->process_action('retry', []);
    }

    /**
     * Without mod/quiz:reviewmyattempts the card does not show the attempt's review data.
     */
    public function test_review_capability(): void {
        global $DB;
        $quiz = $this->create_quiz('truefalse', 'true', ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1]);
        $this->setUser($this->student);
        $this->handler($quiz)->process_action('answer', ['answer' => '1']);
        $this->assertStringContainsString(get_string('correct', 'question'), $this->render($this->handler($quiz)));

        $role = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/quiz:reviewmyattempts', CAP_PROHIBIT, $role, \core\context\module::instance($quiz->cmid));
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->student);
        $this->assertStringNotContainsString(get_string('correct', 'question'), $this->render($this->handler($quiz)));
    }

    /**
     * Only the answer counts: hostile fields in the post cannot pick slots or steps.
     */
    public function test_hostile_answer_fields(): void {
        $quiz = $this->create_quiz('truefalse', 'true', [], 1);
        $this->setUser($this->student);
        $this->handler($quiz)->process_action('answer', [
            'answer' => '1',
            'slots' => '1,2,3',
            'q1:1_:sequencecheck' => '99',
            'q1:1_-submit' => '1',
            'q1:2_-submit' => '1',
        ]);
        $attempts = $this->attempts($quiz);
        $this->assertCount(1, $attempts);
        $this->assertEquals(1, (float)$attempts[0]->sumgrades);
    }

    /**
     * Shuffled answers keep one order per user and do not follow the stored order for everyone.
     */
    public function test_answers_shuffled(): void {
        $quiz = $this->create_quiz('multichoice', 'one_of_four', ['shuffleanswers' => 1]);
        $this->setUser($this->student);
        $first = $this->render($this->handler($quiz));
        $this->assertSame($first, $this->render($this->handler($quiz)));
        preg_match_all('~name="answer" value="(\d+)"~', $first, $matches);
        $this->assertCount(4, $matches[1]);
        $ordered = $matches[1];
        usort($ordered, fn($a, $b) => strcmp(sha1($this->student->id . '/' . $a), sha1($this->student->id . '/' . $b)));
        $this->assertSame($ordered, $matches[1]);
    }
}
