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
use format_spiffy\local\inline\mod_feedback;
use format_spiffy\local\inline\registry;

/**
 * Tests for the inline Feedback handler.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\inline\mod_feedback::class)]
final class inline_feedback_test extends advanced_testcase {
    /** @var \stdClass the course */
    private \stdClass $course;

    /** @var \stdClass the student */
    private \stdClass $student;

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(
            ['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]
        );
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Make a feedback in the course.
     *
     * @param array $record feedback settings
     * @return \stdClass the feedback record, with cmid
     */
    private function create_feedback(array $record = []): \stdClass {
        return $this->getDataGenerator()->create_module('feedback', $record + [
            'course' => $this->course->id,
            'anonymous' => FEEDBACK_ANONYMOUS_NO,
        ]);
    }

    /**
     * The feedback item generator.
     *
     * @return \mod_feedback_generator
     */
    private function items(): \mod_feedback_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_feedback');
    }

    /**
     * The handler of a feedback for the current user, from fresh modinfo.
     *
     * @param \stdClass $feedback
     * @return mod_feedback
     */
    private function handler(\stdClass $feedback): mod_feedback {
        get_fast_modinfo($this->course->id, 0, true);
        $cm = get_fast_modinfo($this->course->id)->get_cm($feedback->cmid);
        $handler = registry::get_handler($cm, cm_settings::get($feedback->cmid));
        $this->assertInstanceOf(mod_feedback::class, $handler);
        return $handler;
    }

    /**
     * Render a handler's card inside.
     *
     * @param mod_feedback $handler
     * @return string
     */
    private function render(mod_feedback $handler): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $this->course->id]));
        return $handler->render($PAGE->get_renderer('core'));
    }

    /**
     * A feedback with one question (and a label) is answered on the card.
     */
    public function test_one_question_is_inline(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_label($feedback);
        $this->items()->create_item_multichoice($feedback, ['values' => "Yes\nNo", 'name' => 'Did it help?']);
        $this->setUser($this->student);

        $handler = $this->handler($feedback);
        $this->assertTrue($handler->is_inline());
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());
        $this->assertSame([], $handler->get_badges());
        $html = $this->render($handler);
        $this->assertStringContainsString('data-spiffy-action="submit"', $html);
        $this->assertStringContainsString('Did it help?', $html);
        $this->assertStringContainsString('type="radio"', $html);
    }

    /**
     * A feedback with two questions opens its page.
     */
    public function test_two_questions_are_not_inline(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_multichoice($feedback);
        $this->items()->create_item_textfield($feedback);
        $this->setUser($this->student);
        $this->assertFalse($this->handler($feedback)->is_inline());
    }

    /**
     * A feedback whose only item is of a type the card cannot show opens its page.
     */
    public function test_info_item_is_not_inline(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_info($feedback);
        $this->setUser($this->student);
        $this->assertFalse($this->handler($feedback)->is_inline());
    }

    /**
     * A closed or not yet open feedback is not answered on the card.
     */
    public function test_closed_feedback_is_not_inline(): void {
        $feedback = $this->create_feedback(['timeclose' => time() - DAYSECS]);
        $this->items()->create_item_multichoice($feedback);
        $this->setUser($this->student);
        $this->assertFalse($this->handler($feedback)->is_inline());

        $feedback = $this->create_feedback(['timeopen' => time() + DAYSECS]);
        $this->items()->create_item_multichoice($feedback);
        $this->assertFalse($this->handler($feedback)->is_inline());
    }

    /**
     * A user who cannot complete the feedback gets no form.
     */
    public function test_without_capability_is_not_inline(): void {
        global $DB;
        $feedback = $this->create_feedback();
        $this->items()->create_item_multichoice($feedback);
        $studentrole = (int)$DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('mod/feedback:complete', CAP_PROHIBIT, $studentrole, \context_module::instance($feedback->cmid));
        $this->setUser($this->student);
        $this->assertFalse($this->handler($feedback)->is_inline());
    }

    /**
     * The teacher's "open the activity page" setting wins.
     */
    public function test_page_mode_is_not_inline(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_multichoice($feedback);
        cm_settings::save(
            $this->course->id,
            $feedback->cmid,
            (object)(['inlinemode' => cm_settings::INLINE_PAGE] + (array)cm_settings::defaults())
        );
        $this->setUser($this->student);
        $this->assertFalse($this->handler($feedback)->is_inline());
    }

    /**
     * Submitting a radio answer saves it, completes the activity and shows the done view.
     */
    public function test_submit_radio_answer(): void {
        global $DB;
        $feedback = $this->create_feedback(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionsubmit' => 1]);
        $item = $this->items()->create_item_multichoice($feedback, ['values' => "Red\nGreen\nBlue"]);
        $this->setUser($this->student);

        $message = $this->handler($feedback)->process_action('submit', ['value' => '2']);
        $this->assertSame(get_string('entries_saved', 'feedback'), $message);

        $completed = $DB->get_record(
            'feedback_completed',
            ['feedback' => $feedback->id, 'userid' => $this->student->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame('2', $DB->get_field('feedback_value', 'value', ['completed' => $completed->id, 'item' => $item->id]));

        $handler = $this->handler($feedback);
        $this->assertTrue($handler->is_done());
        $this->assertTrue(handler::is_complete($handler->get_cm()));
        $this->assertFalse($handler->is_inline(), 'One submission only: the form is gone.');
        $html = $this->render($handler);
        $this->assertStringContainsString('spiffy-feedback-done', $html);
        $this->assertStringContainsString('Green', $html);
        $this->assertStringNotContainsString('spiffy-results', $html, 'Results are not published.');
        $this->assertStringNotContainsString('data-spiffy-action="submit"', $html);
    }

    /**
     * Submitting several ticked options saves them all.
     */
    public function test_submit_checkbox_answer(): void {
        global $DB;
        $feedback = $this->create_feedback();
        $item = $this->items()->create_item_multichoice($feedback, ['subtype' => 'c', 'values' => "A\nB\nC"]);
        $this->setUser($this->student);

        $this->handler($feedback)->process_action('submit', ['value' => ['1', '3']]);
        $completed = $DB->get_record(
            'feedback_completed',
            ['feedback' => $feedback->id, 'userid' => $this->student->id],
            '*',
            MUST_EXIST
        );
        $this->assertSame(
            '1' . FEEDBACK_MULTICHOICE_LINE_SEP . '3',
            $DB->get_field('feedback_value', 'value', ['completed' => $completed->id, 'item' => $item->id])
        );
        $this->assertStringContainsString('A, C', $this->render($this->handler($feedback)));
    }

    /**
     * Submitting a text answer saves it and shows it, escaped, on the done view.
     */
    public function test_submit_text_answer(): void {
        global $DB;
        $feedback = $this->create_feedback();
        $item = $this->items()->create_item_textfield($feedback);
        $this->setUser($this->student);

        $handler = $this->handler($feedback);
        $this->assertStringContainsString('type="text"', $this->render($handler));
        $handler->process_action('submit', ['value' => 'Fish & chips']);
        $completed = $DB->get_record(
            'feedback_completed',
            ['feedback' => $feedback->id, 'userid' => $this->student->id],
            '*',
            MUST_EXIST
        );
        $this->assertNotEmpty($DB->get_field('feedback_value', 'value', ['completed' => $completed->id, 'item' => $item->id]));
        $this->assertStringContainsString('Fish &amp; chips', $this->render($this->handler($feedback)));
    }

    /**
     * An empty answer to a required question is refused and nothing is saved.
     */
    public function test_required_answer_missing_is_refused(): void {
        global $DB;
        $feedback = $this->create_feedback();
        $this->items()->create_item_textfield($feedback, ['required' => 1]);
        $this->setUser($this->student);

        try {
            $this->handler($feedback)->process_action('submit', ['value' => '']);
            $this->fail('An empty required answer must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('feedbacknotsaved', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('feedback_completed', ['feedback' => $feedback->id]));
    }

    /**
     * Published results show after answering, and the form returns when several submissions are allowed.
     */
    public function test_results_and_answer_again(): void {
        $feedback = $this->create_feedback(['publish_stats' => 1, 'multiple_submit' => 1]);
        $this->items()->create_item_multichoice($feedback, ['values' => "Yes\nNo"]);
        $this->setUser($this->student);

        $this->handler($feedback)->process_action('submit', ['value' => '1']);
        $handler = $this->handler($feedback);
        $this->assertTrue($handler->is_done());
        $this->assertTrue($handler->is_inline(), 'Several submissions are allowed.');
        $html = $this->render($handler);
        $this->assertStringContainsString('spiffy-results', $html);
        $this->assertStringContainsString('is-mine', $html);
        $this->assertStringContainsString('data-spiffy-action="submit"', $html, 'The form is offered again.');
    }

    /**
     * A second submission is refused when the feedback allows only one.
     */
    public function test_second_submission_refused_when_not_allowed(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_multichoice($feedback);
        $this->setUser($this->student);
        $this->handler($feedback)->process_action('submit', ['value' => '1']);

        $this->expectException(\moodle_exception::class);
        $this->handler($feedback)->process_action('submit', ['value' => '2']);
    }

    /**
     * An unknown action is refused.
     */
    public function test_unknown_action(): void {
        $feedback = $this->create_feedback();
        $this->items()->create_item_multichoice($feedback);
        $this->setUser($this->student);

        $this->expectException(\moodle_exception::class);
        $this->handler($feedback)->process_action('remove', []);
    }

    /**
     * A card that is done by completion alone does not show the text for those who submitted.
     */
    public function test_after_submit_text_needs_a_submission(): void {
        $feedback = $this->create_feedback(['page_after_submit' => '<p>Secret thanks</p>',
            'page_after_submitformat' => FORMAT_HTML, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->items()->create_item_multichoice($feedback, ['name' => 'Q', 'label' => 'q', 'values' => "A\nB"]);
        $this->setUser($this->student);
        $cm = get_fast_modinfo($feedback->course)->get_cm($feedback->cmid);
        (new \completion_info($cm->get_course()))->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
        $this->assertStringNotContainsString('Secret thanks', $this->render($this->handler($feedback)));
    }
}
