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
use core_external\external_api;
use format_spiffy\external\get_sections;
use format_spiffy\external\inline_action;
use format_spiffy\local\cm_settings;
use format_spiffy\local\inline\handler;
use format_spiffy\local\inline\mod_choice;
use format_spiffy\local\inline\registry;
use format_spiffy\local\sections;

/**
 * Tests for the inline framework: card states, the Choice handler and the web services.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(handler::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(registry::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mod_choice::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(inline_action::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(get_sections::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sections::class)]
final class inline_framework_test extends advanced_testcase {
    /**
     * A Spiffy course with a choice and an enrolled student.
     *
     * @param array $choiceoptions extra choice settings
     * @return array [course, choice cm_info, student]
     */
    private function setup_choice(array $choiceoptions = []): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $choice = $generator->create_module('choice', $choiceoptions + [
            'course' => $course->id,
            'option' => ['Monday', 'Tuesday'],
            'showresults' => CHOICE_SHOWRESULTS_AFTER_ANSWER,
        ]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $cm = get_fast_modinfo($course)->get_cm($choice->cmid);
        return [$course, $cm, $student];
    }

    /**
     * Option ids of a choice by text.
     *
     * @param int $choiceid
     * @return int[]
     */
    private function option_ids(int $choiceid): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu('choice_options', ['choiceid' => $choiceid], 'id', 'text, id'));
    }

    public function test_registry_and_modes(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        $this->resetAfterTest();
        [, $cm] = $this->setup_choice();

        $this->assertSame(mod_choice::class, registry::get_class('choice'));
        $this->assertNull(registry::get_class('label'));

        $handler = registry::get_handler($cm, cm_settings::defaults());
        $this->assertTrue($handler->is_inline());
        $this->assertTrue($handler->accepts_actions());
        $this->assertSame(handler::STATE_AVAILABLE, $handler->get_state());

        $page = cm_settings::clean((object)['inlinemode' => cm_settings::INLINE_PAGE]);
        $handler = registry::get_handler($cm, $page);
        $this->assertFalse($handler->is_inline());
        $this->assertFalse($handler->accepts_actions());
        $this->assertSame('', $handler->render($GLOBALS['PAGE']->get_renderer('core')));
    }

    public function test_choose_through_the_web_service(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        $this->resetAfterTest();
        [$course, $cm] = $this->setup_choice(['allowupdate' => 1]);
        $options = $this->option_ids($cm->instance);

        $result = inline_action::execute($cm->id, 'choose', json_encode(['option' => (string)$options['Tuesday']]));
        $result = external_api::clean_returnvalue(inline_action::execute_returns(), $result);

        $this->assertSame(get_string('choicesaved', 'choice'), $result['message']);
        $this->assertNotEmpty($result['sections']);
        $html = implode('', array_column($result['sections'], 'html'));
        $this->assertStringContainsString('spiffy-card--done', $html);
        $this->assertStringContainsString('spiffy-choice-done', $html);
        $this->assertStringContainsString('Tuesday', $html);

        $choice = choice_get_choice($cm->instance);
        $this->assertSame([$options['Tuesday']], array_map('intval', array_column(choice_get_my_response($choice), 'optionid')));

        // Allowed to change: remove the answer and the card is available again.
        $result = inline_action::execute($cm->id, 'remove', '{}');
        $this->assertEmpty(choice_get_my_response($choice));
        $html = implode('', array_column($result['sections'], 'html'));
        $this->assertStringContainsString('spiffy-card--available', $html);
    }

    public function test_choice_without_update_cannot_be_changed(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/choice/lib.php');
        $this->resetAfterTest();
        [, $cm] = $this->setup_choice(['allowupdate' => 0]);
        $options = $this->option_ids($cm->instance);

        inline_action::execute($cm->id, 'choose', json_encode(['option' => (string)$options['Monday']]));
        $this->expectException(\moodle_exception::class);
        inline_action::execute($cm->id, 'choose', json_encode(['option' => (string)$options['Tuesday']]));
    }

    public function test_page_mode_rejects_actions(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->setup_choice();
        cm_settings::save($course->id, $cm->id, (object)['inlinemode' => cm_settings::INLINE_PAGE]);
        $options = $this->option_ids($cm->instance);

        $this->expectException(\moodle_exception::class);
        inline_action::execute($cm->id, 'choose', json_encode(['option' => (string)$options['Monday']]));
    }

    public function test_locked_activity_rejects_actions(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->setup_choice();
        $this->setAdminUser();
        set_coursemodule_visible($cm->id, 0);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $options = $this->option_ids($cm->instance);

        $this->expectException(\moodle_exception::class);
        inline_action::execute($cm->id, 'choose', json_encode(['option' => (string)$options['Monday']]));
    }

    public function test_get_sections_hash_is_stable(): void {
        $this->resetAfterTest();
        [$course] = $this->setup_choice();

        $first = external_api::clean_returnvalue(get_sections::execute_returns(), get_sections::execute($course->id));
        $second = get_sections::execute($course->id);
        $this->assertNotEmpty($first);
        $this->assertSame(array_column($first, 'hash', 'id'), array_column($second, 'hash', 'id'));
        $this->assertSame(sections::hash('<p id="abcdef0123456">x</p>'), sections::hash('<p id="0123456abcdef">x</p>'));
    }
}
