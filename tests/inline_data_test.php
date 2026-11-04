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
use format_spiffy\local\inline\mod_data;
use format_spiffy\local\inline\registry;

/**
 * Tests for the Database card handler.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_data::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\inline\mod_glossary::class)]
final class inline_data_test extends advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/rating/lib.php');
        require_once($CFG->dirroot . '/mod/data/lib.php');
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
     * A database with a required text field, a menu and checkboxes.
     *
     * @param \stdClass $course
     * @param array $settings more database settings
     * @return array [database, fields by name]
     */
    private function create_database(\stdClass $course, array $settings = []): array {
        $database = $this->getDataGenerator()->create_module('data', ['course' => $course->id] + $settings);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $fields = [
            'title' => $generator->create_field((object)['type' => 'text', 'name' => 'Title', 'required' => 1], $database),
            'colour' => $generator->create_field(
                (object)['type' => 'menu', 'name' => 'Colour', 'param1' => "Red\nBlue"],
                $database
            ),
            'tags' => $generator->create_field(
                (object)['type' => 'checkbox', 'name' => 'Tags', 'param1' => "Fun\nHard\nLong"],
                $database
            ),
        ];
        return [$database, array_map(fn($field) => $field->field, $fields)];
    }

    /**
     * The handler of a database, for the current user.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @return mod_data
     */
    private function get_handler(\stdClass $course, int $cmid): mod_data {
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $handler = registry::get_handler($cm, cm_settings::get($cmid));
        $this->assertInstanceOf(mod_data::class, $handler);
        return $handler;
    }

    /**
     * Render a card's inside for the current user.
     *
     * @param mod_data $handler
     * @return string
     */
    private function render(mod_data $handler): string {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $handler->get_cm()->course]));
        return $handler->render($PAGE->get_renderer('core'));
    }

    /**
     * Add an entry by a user.
     *
     * @param \stdClass $database
     * @param array $fields fields by name
     * @param \stdClass $user
     * @param string $title
     * @return int record id
     */
    private function add_entry(\stdClass $database, array $fields, \stdClass $user, string $title): int {
        return $this->getDataGenerator()->get_plugin_generator('mod_data')->create_entry(
            $database,
            [$fields['title']->id => $title, $fields['colour']->id => 'Blue', $fields['tags']->id => ['Fun']],
            0,
            [],
            ['approved' => 1],
            $user->id
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
     * A simple database is filled in on the card, through mod_data.
     */
    public function test_add_entry(): void {
        global $DB;
        [$course, $student] = $this->setup_course();
        [$database, $fields] = $this->create_database($course);

        $this->setUser($student);
        $handler = $this->get_handler($course, $database->cmid);
        $this->assertTrue($handler->is_simple());
        $this->assertTrue($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringContainsString('data-spiffy-action="add"', $html);
        $this->assertStringContainsString('name="f' . $fields['title']->id . '"', $html);
        $this->assertStringContainsString('name="f' . $fields['tags']->id . '[]"', $html);

        $message = $handler->process_action('add', [
            'f' . $fields['title']->id => 'My first entry',
            'f' . $fields['colour']->id => 'Red',
            'f' . $fields['tags']->id => ['Fun', 'Long', 'Not an option'],
        ]);
        $this->assertSame(get_string('data_added', 'format_spiffy'), $message);
        $record = $DB->get_record('data_records', ['dataid' => $database->id, 'userid' => $student->id], '*', MUST_EXIST);
        $contents = $DB->get_records_menu('data_content', ['recordid' => $record->id], '', 'fieldid, content');
        $this->assertSame('My first entry', $contents[$fields['title']->id]);
        $this->assertSame('Red', $contents[$fields['colour']->id]);
        $this->assertSame('Fun##Long', $contents[$fields['tags']->id]);

        $html = $this->render($this->get_handler($course, $database->cmid));
        $this->assertStringContainsString('My first entry', $html);
        $this->assertStringContainsString(get_string('data_yourentry', 'format_spiffy'), $html);
    }

    /**
     * Core's required-field check applies, and a value that is not an option counts as empty.
     */
    public function test_add_entry_required(): void {
        global $DB;
        [$course, $student] = $this->setup_course();
        [$database, $fields] = $this->create_database($course);

        $this->setUser($student);
        try {
            $this->get_handler($course, $database->cmid)->process_action('add', [
                'f' . $fields['title']->id => '   ',
                'f' . $fields['colour']->id => 'Green',
            ]);
            $this->fail('An entry without its required field was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('data_notsaved', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('data_records', ['dataid' => $database->id]));
    }

    /**
     * Databases with other field types, or too many fields, open their page.
     */
    public function test_not_simple(): void {
        [$course, $student] = $this->setup_course();
        [$database] = $this->create_database($course);
        $this->getDataGenerator()->get_plugin_generator('mod_data')->create_field(
            (object)['type' => 'file', 'name' => 'File'],
            $database
        );
        $many = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        for ($i = 0; $i <= mod_data::MAX_FIELDS; $i++) {
            $this->getDataGenerator()->get_plugin_generator('mod_data')->create_field((object)['type' => 'text'], $many);
        }
        $empty = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);

        $this->setUser($student);
        foreach ([$database, $many, $empty] as $instance) {
            $handler = $this->get_handler($course, $instance->cmid);
            $this->assertFalse($handler->is_simple());
            $this->assertFalse($handler->is_inline());
            $this->assertSame('', $this->render($handler));
        }
        $this->expectException(\moodle_exception::class);
        $this->get_handler($course, $database->cmid)->process_action('add', []);
    }

    /**
     * Once the entry limit is reached the form goes, and the latest entry stays on the card.
     */
    public function test_entry_limit(): void {
        [$course, $student] = $this->setup_course();
        [$database, $fields] = $this->create_database($course, ['maxentries' => 1]);
        $this->setUser($student);
        $this->add_entry($database, $fields, $student, 'Only one');

        $handler = $this->get_handler($course, $database->cmid);
        $this->assertTrue($handler->is_inline());
        $html = $this->render($handler);
        $this->assertStringContainsString('Only one', $html);
        $this->assertStringNotContainsString('data-spiffy-action="add"', $html);

        $this->expectException(\moodle_exception::class);
        $handler->process_action('add', ['f' . $fields['title']->id => 'Second']);
    }

    /**
     * A closed database cannot be filled in from the card.
     */
    public function test_closed_database(): void {
        [$course, $student] = $this->setup_course();
        [$database, $fields] = $this->create_database($course, ['timeavailableto' => time() - 3600]);

        $this->setUser($student);
        $handler = $this->get_handler($course, $database->cmid);
        $this->assertFalse($handler->is_inline());
        $this->expectException(\moodle_exception::class);
        $handler->process_action('add', ['f' . $fields['title']->id => 'Late']);
    }

    /**
     * Classmates' entries are shown, and can be rated and commented on when the database allows it.
     */
    public function test_peer_reactions(): void {
        global $CFG, $DB;
        [$course, $student, $classmate] = $this->setup_course();
        $CFG->usecomments = 1;
        [$database, $fields] = $this->create_database($course, ['assessed' => RATING_AGGREGATE_AVERAGE, 'scale' => 10,
            'comments' => 1]);
        $context = \core\context\module::instance($database->cmid);
        assign_capability('mod/data:rate', CAP_ALLOW, $this->get_student_roleid(), $context->id, true);
        $theirs = $this->add_entry($database, $fields, $classmate, 'Their entry');

        $this->setUser($student);
        $handler = $this->get_handler($course, $database->cmid);
        $html = $this->render($handler);
        $this->assertStringContainsString('Their entry', $html);
        $this->assertStringContainsString('data-spiffy-action="rate"', $html);
        $this->assertStringContainsString('data-spiffy-action="comment"', $html);

        $this->assertSame(
            get_string('peer_ratingsaved', 'format_spiffy'),
            $handler->process_action('rate', ['itemid' => (string)$theirs, 'rating' => '7'])
        );
        $this->assertEquals(7, $DB->get_field('rating', 'rating', ['component' => 'mod_data', 'itemid' => $theirs,
            'userid' => $student->id]));

        $this->assertSame(
            get_string('peer_commentsaved', 'format_spiffy'),
            $handler->process_action('comment', ['itemid' => (string)$theirs, 'content' => 'Nice one'])
        );
        $this->assertTrue($DB->record_exists('comments', ['component' => 'mod_data', 'commentarea' => 'database_entry',
            'itemid' => $theirs, 'userid' => $student->id]));
        $this->assertStringContainsString('Nice one', $this->render($this->get_handler($course, $database->cmid)));
    }

    /**
     * Entries waiting for approval are not shown to classmates or reacted to.
     */
    public function test_unapproved_peer_hidden(): void {
        global $CFG;
        [$course, $student, $classmate] = $this->setup_course();
        $CFG->usecomments = 1;
        [$database, $fields] = $this->create_database($course, ['approval' => 1, 'comments' => 1]);
        $theirs = $this->getDataGenerator()->get_plugin_generator('mod_data')->create_entry(
            $database,
            [$fields['title']->id => 'Not yet approved', $fields['colour']->id => 'Red', $fields['tags']->id => ['Fun']],
            0,
            [],
            ['approved' => 0],
            $classmate->id
        );

        $this->setUser($student);
        $handler = $this->get_handler($course, $database->cmid);
        $this->assertStringNotContainsString('Not yet approved', $this->render($handler));
        $this->expectException(\moodle_exception::class);
        $handler->process_action('comment', ['itemid' => (string)$theirs, 'content' => 'Hello']);
    }

    /**
     * A database that needs approval says the user's entry is waiting.
     */
    public function test_pending_badge(): void {
        [$course, $student] = $this->setup_course();
        [$database, $fields] = $this->create_database($course, ['approval' => 1]);

        $this->setUser($student);
        $message = $this->get_handler($course, $database->cmid)->process_action(
            'add',
            ['f' . $fields['title']->id => 'Waiting']
        );
        $this->assertSame(get_string('data_addedpending', 'format_spiffy'), $message);
        $handler = $this->get_handler($course, $database->cmid);
        $this->assertContains(get_string('peer_badgepending', 'format_spiffy', 1), array_column($handler->get_badges(), 'text'));
        $this->assertStringContainsString(get_string('peer_waiting', 'format_spiffy'), $this->render($handler));
    }

    /**
     * A URL field never becomes a javascript: link, even when PARAM_URL lets the value through,
     * and it is linked only when the field asks for it.
     */
    public function test_url_field_is_safe(): void {
        [$course, $student, $classmate] = $this->setup_course();
        $database = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $linked = $generator->create_field((object)['type' => 'url', 'name' => 'Linked', 'required' => 0,
            'param1' => 1, 'description' => ''], $database);
        $plain = $generator->create_field((object)['type' => 'url', 'name' => 'Plain', 'required' => 0,
            'param1' => 0, 'description' => ''], $database);
        $payload = 'javascript:1?alert(document.cookie):0//://x';
        $this->assertSame($payload, clean_param($payload, PARAM_URL), 'The payload must still get through PARAM_URL.');

        $this->setUser($student);
        $generator->create_entry(
            $database,
            [$linked->field->id => $payload, $plain->field->id => 'http://example.com/plain'],
            0,
            [],
            ['approved' => 1]
        );
        $this->setUser($classmate);
        $generator->create_entry(
            $database,
            [$linked->field->id => 'http://example.com/ok', $plain->field->id => ''],
            0,
            [],
            ['approved' => 1]
        );

        $html = $this->render($this->get_handler($course, $database->cmid));
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('javascript:1?alert', $html);
        $this->assertStringNotContainsString('href="http://example.com/plain"', $html);
        $this->assertStringContainsString('http://example.com/plain', $html);
    }

    /**
     * Classmates' entries are hidden outside the database's open dates.
     */
    public function test_peers_hidden_when_closed(): void {
        [$course, $student, $classmate] = $this->setup_course();
        [$database, $fields] = $this->create_database($course);
        $this->add_entry($database, $fields, $student, 'Mine');
        $this->add_entry($database, $fields, $classmate, 'Theirs');
        $this->setUser($student);
        $this->assertStringContainsString('Theirs', $this->render($this->get_handler($course, $database->cmid)));

        global $DB;
        $DB->set_field('data', 'timeavailableto', time() - HOURSECS, ['id' => $database->id]);
        rebuild_course_cache($course->id, true);
        $this->assertStringNotContainsString('Theirs', $this->render($this->get_handler($course, $database->cmid)));
    }

    /**
     * Under separate groups, a student in no group sees only entries for all groups.
     */
    public function test_peers_of_other_groups_hidden_for_no_group(): void {
        [$course, $student, $classmate] = $this->setup_course();
        [$database, $fields] = $this->create_database($course, ['groupmode' => SEPARATEGROUPS]);
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $classmate->id]);
        $this->add_entry($database, $fields, $student, 'Mine');
        $this->add_entry($database, $fields, $classmate, 'Theirs');

        global $DB;
        $DB->set_field('data_records', 'groupid', $group->id, ['dataid' => $database->id, 'userid' => $classmate->id]);
        $this->setUser($student);
        $this->assertStringNotContainsString('Theirs', $this->render($this->get_handler($course, $database->cmid)));
    }
}
