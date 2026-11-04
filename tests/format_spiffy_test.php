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
use completion_info;
use core_availability\tree;
use core_external\external_api;
use format_spiffy\external\get_sections;
use format_spiffy\local\cm_settings;
use restore_controller;

/**
 * Tests for the Spiffy course format.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\output\courseformat\content::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\output\courseformat\content\cm::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\format_spiffy\local\cm_settings::class)]
final class format_spiffy_test extends advanced_testcase {
    /**
     * Export the course page content as the current user.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    private function export_content(\stdClass $course): \stdClass {
        global $PAGE;
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $format = course_get_format($course);
        $renderer = $PAGE->get_renderer('format_spiffy');
        $outputclass = $format->get_output_classname('content');
        $content = new $outputclass($format);
        $this->assertSame('format_spiffy/local/content', $content->get_template_name($renderer));
        return $content->export_for_template($renderer);
    }

    /**
     * Find an exported section by number.
     *
     * @param \stdClass $data the content export
     * @param int $num section number
     * @return \stdClass
     */
    private function section(\stdClass $data, int $num): \stdClass {
        foreach ($data->spiffysections as $section) {
            if ((int)$section->num === $num) {
                return $section;
            }
        }
        $this->fail("Section $num not exported");
    }

    /**
     * The exported items of a section, keyed by course module id.
     *
     * @param \stdClass $section
     * @return \stdClass[] the cmformat of each item
     */
    private function items(\stdClass $section): array {
        $items = [];
        foreach ($section->spiffyitems as $item) {
            $items[(int)$item->id] = $item->cmformat;
        }
        return $items;
    }

    /**
     * Completion restriction JSON on one activity.
     *
     * @param int $cmid
     * @return string
     */
    private function completion_restriction(int $cmid): string {
        return json_encode(tree::get_root_json([
            \availability_completion\condition::get_json($cmid, COMPLETION_COMPLETE),
        ]));
    }

    /**
     * Course and section options have the expected defaults and columns are clamped.
     */
    public function test_format_options(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'spiffy', 'numsections' => 2, 'summary' => '']);
        $format = course_get_format($course);

        $this->assertSame(3, $format->get_columns());
        $sectionoptions = $format->get_format_options(1);
        $this->assertSame('', $sectionoptions['eyebrow']);
        $this->assertSame(\format_spiffy::NUMBERS_AUTO, $sectionoptions['numbers']);

        $format->update_course_format_options(['columns' => 9]);
        $this->assertSame(4, course_get_format($course->id)->get_columns());
        $this->assertTrue(\format_spiffy::uses_linear_navigation());
    }

    /**
     * Card design, page width, arrow direction and the course index: defaults, unknown values
     * ignored, the page width as a body class, and the course index switched off.
     */
    public function test_display_options(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $format = course_get_format($course);
        $this->assertSame('soft', $format->get_display_option('cardstyle'));
        $this->assertSame('wide', $format->get_display_option('pagewidth'));
        $this->assertSame('forwards', $format->get_display_option('arrows'));
        $this->assertTrue($format->uses_course_index());
        $this->assertEmpty($format->get_format_options()['blocksopen']);

        $format->update_course_format_options(['cardstyle' => 'lifted', 'pagewidth' => 'huge', 'arrows' => 'backwards',
            'courseindex' => 0]);
        $format = course_get_format($course->id);
        $this->assertSame('lifted', $format->get_display_option('cardstyle'));
        $this->assertSame('wide', $format->get_display_option('pagewidth'));
        $this->assertSame('backwards', $format->get_display_option('arrows'));
        $this->assertFalse($format->uses_course_index());

        $format->update_course_format_options(['pagewidth' => 'full', 'blocksopen' => 1]);
        $PAGE->set_url(new \moodle_url('/course/view.php', ['id' => $course->id]));
        $PAGE->set_pagetype('course-view-spiffy');
        $PAGE->set_course(get_course($course->id));
        $this->assertContains('spiffy-width-full', explode(' ', $PAGE->bodyclasses));
        $this->assertContains('spiffy-widened', explode(' ', $PAGE->bodyclasses));
        $amdcode = implode("\n", (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires));
        $this->assertStringContainsString("require(['format_spiffy/blocks']", $amdcode);
        $this->assertStringContainsString('amd.init(' . \format_spiffy::BLOCKS_OPEN_MINWIDTH . ')', $amdcode);

        $data = $this->export_content(get_course($course->id));
        $this->assertSame('lifted', $data->spiffycardstyle);
        $this->assertSame('backwards', $data->spiffyarrows);

        // Section label: "Section 1" by default, a custom word, the number only, none; the section's own label follows it.
        $this->assertSame('Section 1', $this->section($data, 1)->spiffyeyebrow);
        $format = course_get_format($course->id);
        $format->update_course_format_options(['sectionword' => 'Week']);
        $this->assertSame('Week 1', $this->section($this->export_content(get_course($course->id)), 1)->spiffyeyebrow);
        $format->update_course_format_options(['sectionlabel' => 'number']);
        $this->assertSame('1', $this->section($this->export_content(get_course($course->id)), 1)->spiffyeyebrow);
        $format->update_course_format_options(['sectionlabel' => 'none']);
        $this->assertSame('', $this->section($this->export_content(get_course($course->id)), 1)->spiffyeyebrow);
        $section1 = get_fast_modinfo($course->id)->get_section_info(1);
        $format->update_section_format_options(['id' => $section1->id, 'eyebrow' => 'Before we begin']);
        rebuild_course_cache($course->id, true);
        $this->assertSame('Before we begin', $this->section($this->export_content(get_course($course->id)), 1)->spiffyeyebrow);
        $format->update_course_format_options(['sectionlabel' => 'word']);
        $this->assertSame(
            'Week 1 · Before we begin',
            $this->section($this->export_content(get_course($course->id)), 1)->spiffyeyebrow
        );

        // General section always open: no hide button, marked for the page script; only section 0.
        $this->setAdminUser();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 0, 'name' => 'Welcome']);
        $general = $this->section($this->export_content(get_course($course->id)), 0);
        $this->assertFalse($general->spiffyalwaysopen);
        $this->assertStringContainsString('data-spiffy-toggle', $general->spiffyhtml);
        $format->update_course_format_options(['generalopen' => 1]);
        $data = $this->export_content(get_course($course->id));
        $general = $this->section($data, 0);
        $this->assertTrue($general->spiffyalwaysopen);
        $this->assertStringContainsString('data-spiffy-alwaysopen="1"', $general->spiffyhtml);
        $this->assertStringNotContainsString('data-spiffy-toggle', $general->spiffyhtml);
        $this->assertFalse($this->section($data, 1)->spiffyalwaysopen);
    }

    /**
     * Every feature can be switched off per course, and the site's defaults are what new courses
     * start with.
     */
    public function test_features(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();

        // Site defaults: a feature off and a display setting changed for new courses.
        set_config('default_featurenotes', 0, 'format_spiffy');
        set_config('default_cardstyle', 'flat', 'format_spiffy');
        set_config('default_arrows', 'sideways', 'format_spiffy');
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1,
            'summary' => '']);
        $format = course_get_format($course);
        $this->assertFalse($format->feature_enabled('notes'));
        $this->assertTrue($format->feature_enabled('askteacher'));
        $this->assertSame('flat', $format->get_display_option('cardstyle'));
        $this->assertSame('forwards', $format->get_display_option('arrows'));
        $this->assertSame(\format_spiffy::FEATURES, array_map(
            fn($name) => substr($name, 7),
            array_values(array_filter(array_keys($format->course_format_options()), fn($name) => str_starts_with($name, 'feature')))
        ));
        $format->update_course_format_options(['featurenotes' => 1]);

        $manual = ['course' => $course->id, 'section' => 1, 'completion' => COMPLETION_TRACKING_MANUAL];
        $first = $generator->create_module('choice', $manual + ['name' => 'Pick one', 'option' => ['A', 'B']]);
        $second = $generator->create_module('page', $manual + ['name' => 'Read on']);
        $DB->set_field('course_modules', 'availability', $this->completion_restriction($first->cmid), ['id' => $second->cmid]);
        rebuild_course_cache($course->id, true);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        // Everything on.
        $this->setUser($student);
        $data = $this->export_content(get_course($course->id));
        $card = $this->items($this->section($data, 1))[$first->cmid];
        $this->assertTrue($card->spiffytools['cannote']);
        $this->assertTrue($card->spiffytools['canask']);
        $this->assertNotSame('', $card->spiffyunlockhint);
        $this->assertNotSame('', $card->spiffyinlinehtml);
        $this->assertTrue($card->completion->showmanualcompletion);
        $this->assertTrue($data->spiffyspotlight['canswitch']);
        $this->assertNotEmpty($data->spiffyspotlight['whatsnext']);
        $this->assertTrue($data->spiffycelebrate);
        $this->setUser($teacher);
        $this->assertNotEmpty($this->export_content(get_course($course->id))->spiffyteacher);

        // Everything off.
        $off = [];
        foreach (\format_spiffy::FEATURES as $feature) {
            $off['feature' . $feature] = 0;
        }
        course_get_format($course->id)->update_course_format_options($off + ['arrows' => 'none']);
        \format_spiffy\local\teacher::reset_caches();
        $this->assertSame([], $this->export_content(get_course($course->id))->spiffyteacher);
        $this->setUser($student);
        $data = $this->export_content(get_course($course->id));
        $card = $this->items($this->section($data, 1))[$first->cmid];
        $this->assertSame([], $card->spiffytools);
        $this->assertSame('', $card->spiffyunlockhint);
        $this->assertSame('', $card->spiffyinlinehtml);
        $this->assertFalse($card->completion->showmanualcompletion);
        $this->assertFalse($data->spiffyspotlight['canswitch']);
        $this->assertSame('list', $data->spiffyspotlight['viewmode']);
        $this->assertFalse($data->spiffyspotlight['whatsnext']);
        $this->assertFalse($data->spiffyspotlight['hastoolbar']);
        $this->assertFalse($data->spiffycelebrate);
        $this->assertSame('none', $data->spiffyarrows);

        // The tools' web services refuse a switched-off tool.
        $this->expectException(\moodle_exception::class);
        \format_spiffy\local\tools::validate_card((int)$first->cmid, 'notes');
    }

    /**
     * Activities are steps, numbered when they must be done in order; text and media are notes,
     * and the notes after the last step go once every step is complete.
     */
    public function test_steps_and_notes(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $CFG->enableavailability = 1;
        $CFG->enablecompletion = 1;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'format' => 'spiffy',
            'numsections' => 1,
            'enablecompletion' => 1,
            'summary' => '',
        ]);
        $format = course_get_format($course);
        $section1 = get_fast_modinfo($course)->get_section_info(1);
        $DB->set_field('course_sections', 'name', 'Get ready', ['id' => $section1->id]);
        $format->update_section_format_options(['id' => $section1->id, 'eyebrow' => 'Before we begin']);

        $manual = ['course' => $course->id, 'section' => 1, 'completion' => COMPLETION_TRACKING_MANUAL];
        $first = $generator->create_module('forum', $manual + ['name' => 'Learn here']);
        $second = $generator->create_module('page', $manual + ['name' => 'Create an idea']);
        $third = $generator->create_module('page', $manual + ['name' => 'Discover']);
        $extra = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Optional reading']);
        $note = $generator->create_module('label', ['course' => $course->id, 'section' => 1,
            'intro' => '<h4>What happens next?</h4>']);
        $DB->set_field('course_modules', 'availability', $this->completion_restriction($first->cmid), ['id' => $second->cmid]);
        $DB->set_field('course_modules', 'availability', $this->completion_restriction($second->cmid), ['id' => $third->cmid]);
        cm_settings::save($course->id, $first->cmid, (object)[
            'buttonlabel' => 'Practise in the forum',
            'width' => 'full',
            'accent' => 'auto',
        ]);
        rebuild_course_cache($course->id, true);

        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $section = $this->section($this->export_content($course), 1);
        $this->assertSame('Section 1 · Before we begin', $section->spiffyeyebrow);
        $this->assertSame('Get ready', $section->spiffytitle);
        $items = $this->items($section);
        $this->assertCount(5, $items);

        $this->assertSame(1, $items[$first->cmid]->spiffynumber);
        $this->assertNotEmpty($items[$first->cmid]->spiffyicon['icon']);
        $this->assertSame(1, $items[$first->cmid]->spiffyicon['showtooltip']);
        $this->assertSame('Step 1: Learn here', $items[$first->cmid]->spiffytitle);
        $this->assertSame('Practise in the forum', $items[$first->cmid]->spiffybuttonlabel);
        $this->assertTrue($items[$first->cmid]->spiffyfull);
        $this->assertSame('green', $items[$first->cmid]->spiffyaccent);
        $this->assertSame('Complete this step to unlock Step 2.', $items[$first->cmid]->spiffyunlockhint);
        $this->assertFalse($items[$first->cmid]->spiffylocked);

        $this->assertSame(2, $items[$second->cmid]->spiffynumber);
        $this->assertTrue($items[$second->cmid]->spiffylocked);
        $this->assertFalse($items[$second->cmid]->spiffyavailable);
        $this->assertSame('amber', $items[$second->cmid]->spiffyaccent);
        $this->assertSame('Complete this step to unlock Step 3.', $items[$second->cmid]->spiffyunlockhint);
        $this->assertSame(3, $items[$third->cmid]->spiffynumber);
        $this->assertSame('Start Discover', $items[$third->cmid]->spiffybuttonlabel);
        $this->assertSame('', $items[$third->cmid]->spiffyunlockhint);
        // The chain: each locked step lists its own prerequisite; the step to do first is the open one.
        $this->assertSame('', $items[$first->cmid]->spiffyprereqs);
        $this->assertSame((string)$first->cmid, $items[$second->cmid]->spiffyprereqs);
        $this->assertSame((string)$second->cmid, $items[$third->cmid]->spiffyprereqs);
        $this->assertSame((string)$first->cmid, $items[$third->cmid]->spiffyrequires);

        $this->assertNull($items[$extra->cmid]->spiffynumber);
        $this->assertSame('Optional reading', $items[$extra->cmid]->spiffytitle);
        $this->assertFalse($items[$extra->cmid]->spiffyfull);
        $this->assertTrue($items[$note->cmid]->spiffynote);
        $this->assertTrue($items[$note->cmid]->spiffyfull);

        // Completing every tracked step removes the note after the last step.
        $completion = new completion_info($course);
        $modinfo = get_fast_modinfo($course);
        foreach ([$first, $second, $third] as $module) {
            $completion->update_state($modinfo->get_cm($module->cmid), COMPLETION_COMPLETE, $student->id);
        }
        // A new page load builds the format and its modinfo afresh.
        \core_courseformat\base::reset_course_cache($course->id);
        get_fast_modinfo($course->id, 0, true);
        $items = $this->items($this->section($this->export_content($course), 1));
        $this->assertArrayNotHasKey($note->cmid, $items);
        $this->assertSame('', $items[$first->cmid]->spiffyunlockhint);
    }

    /**
     * Section option "numbers" overrides the automatic numbering.
     */
    public function test_numbers_option(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Alone']);
        $format = course_get_format($course);
        $section1 = get_fast_modinfo($course)->get_section_info(1);

        $this->setAdminUser();
        $items = $this->items($this->section($this->export_content($course), 1));
        $this->assertNull($items[$page->cmid]->spiffynumber);

        $format->update_section_format_options(['id' => $section1->id, 'numbers' => \format_spiffy::NUMBERS_ALWAYS]);
        $items = $this->items($this->section($this->export_content(get_course($course->id)), 1));
        $this->assertSame(1, $items[$page->cmid]->spiffynumber);
    }

    /**
     * Editing mode keeps the core layout and editor.
     */
    public function test_editing_uses_core_layout(): void {
        global $PAGE, $USER;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $this->setAdminUser();
        $PAGE->set_course($course);
        $USER->editing = 1;

        $format = course_get_format($course);
        $outputclass = $format->get_output_classname('content');
        $content = new $outputclass($format);
        $renderer = $PAGE->get_renderer('format_spiffy');
        $this->assertFalse($content->is_card_view());
        $this->assertSame('core_courseformat/local/content', $content->get_template_name($renderer));
    }

    /**
     * A section's own page (course/section.php) shows that section in the card layout, and the
     * web service that refreshes the page renders the same section.
     */
    public function test_section_page_uses_cards(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 2, 'summary' => '']);
        $other = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'First']);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'Second']);
        $section2 = get_fast_modinfo($course)->get_section_info(2);

        $format = course_get_format($course);
        $format->set_sectionid($section2->id);
        $PAGE->set_url(new \moodle_url('/course/section.php', ['id' => $section2->id]));
        $outputclass = $format->get_output_classname('content');
        $content = new $outputclass($format);
        $renderer = $PAGE->get_renderer('format_spiffy');
        $this->assertTrue($content->is_card_view());
        $this->assertSame('format_spiffy/local/content', $content->get_template_name($renderer));
        $data = $content->export_for_template($renderer);
        $this->assertSame([(int)$section2->id], array_map(fn($section) => (int)$section->id, $data->spiffysections));
        $this->assertSame((int)$section2->id, $data->spiffysectionid);
        $this->assertSame([(int)$page->cmid], array_map(fn($item) => (int)$item->id, $data->spiffysections[0]->spiffyitems));
        $html = $renderer->render_from_template('format_spiffy/local/content', $data);
        $this->assertStringContainsString('data-spiffy-sectionid="' . $section2->id . '"', $html);

        $refreshed = external_api::clean_returnvalue(
            get_sections::execute_returns(),
            get_sections::execute($course->id, $section2->id)
        );
        $this->assertSame([(int)$section2->id], array_column($refreshed, 'id'));
        $this->assertSame($data->spiffysections[0]->spiffyhash, $refreshed[0]['hash']);
        // A section of another course renders nothing.
        $othersection = get_fast_modinfo($other)->get_section_info(1);
        $this->assertSame([], get_sections::execute($course->id, $othersection->id));
    }

    /**
     * Activity settings are cleaned on save, deleted with the activity and copied by backup and restore.
     */
    public function test_activity_settings_lifecycle(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Page']);
        $gone = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Gone']);

        cm_settings::save($course->id, $page->cmid, (object)['buttonlabel' => '<b>Go</b>', 'width' => 'bogus', 'accent' => 'teal']);
        cm_settings::save($course->id, $gone->cmid, (object)['buttonlabel' => 'Bye', 'width' => 'full', 'accent' => 'auto']);
        $saved = cm_settings::get($page->cmid);
        $this->assertSame('Go', $saved->buttonlabel);
        $this->assertSame(\format_spiffy::WIDTH_AUTO, $saved->width);
        $this->assertSame('teal', $saved->accent);

        \core_courseformat\formatactions::cm($course->id)->delete($gone->cmid);
        $this->assertFalse($DB->record_exists('format_spiffy_cm', ['cmid' => $gone->cmid]));

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $course->category);
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newcm = get_fast_modinfo($newcourseid)->get_instances_of('page');
        $this->assertCount(1, $newcm);
        $restored = cm_settings::get((int)reset($newcm)->id);
        $this->assertSame('teal', $restored->accent);
        $this->assertSame('Go', $restored->buttonlabel);
        $this->assertSame(1, $DB->count_records('format_spiffy_cm', ['courseid' => $newcourseid]));
    }

    /**
     * The activity form hook ignores a coursemodule that is not in the course being edited.
     */
    public function test_form_hook_refuses_other_course(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $mine = $generator->create_course(['format' => 'spiffy', 'summary' => '']);
        $other = $generator->create_course(['format' => 'spiffy', 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $other->id]);
        \format_spiffy\local\cm_settings::save($other->id, $page->cmid, (object)['buttonlabel' => 'Theirs']);

        $forged = (object)['coursemodule' => $page->cmid, 'format_spiffy_width' => 'full', 'format_spiffy_buttonlabel' => 'Hacked'];
        format_spiffy_coursemodule_edit_post_actions($forged, $mine);
        $record = $DB->get_record('format_spiffy_cm', ['cmid' => $page->cmid]);
        $this->assertSame('Theirs', $record->buttonlabel);
        $this->assertEquals($other->id, $record->courseid);

        $mypage = $generator->create_module('page', ['course' => $mine->id]);
        $own = (object)['coursemodule' => $mypage->cmid, 'format_spiffy_width' => 'full', 'format_spiffy_buttonlabel' => 'Mine'];
        format_spiffy_coursemodule_edit_post_actions($own, $mine);
        $this->assertSame('Mine', $DB->get_field('format_spiffy_cm', 'buttonlabel', ['cmid' => $mypage->cmid]));
    }

    /**
     * The section label and the section word are limited on the server.
     */
    public function test_label_lengths_limited(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['format' => 'spiffy', 'numsections' => 1, 'summary' => '']);
        $format = course_get_format($course);
        $format->update_course_format_options(['sectionword' => str_repeat('x', 200)]);
        $this->assertSame(\format_spiffy::SECTIONWORD_MAXLENGTH, \core_text::strlen($format->get_format_options()['sectionword']));
        $section = get_fast_modinfo($course)->get_section_info(1);
        $format->update_section_format_options(['id' => $section->id, 'eyebrow' => str_repeat('y', 200)]);
        $this->assertSame(
            \format_spiffy::EYEBROW_MAXLENGTH,
            \core_text::strlen($format->get_format_options($section)['eyebrow'])
        );
    }

    /**
     * Stored rows for testing the observers.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param int $student
     * @param int $teacher
     * @return void
     */
    private function add_rows(\stdClass $course, int $cmid, int $student, int $teacher): void {
        global $DB;
        $base = ['courseid' => $course->id, 'cmid' => $cmid];
        $DB->insert_record('format_spiffy_note', (object)($base + ['userid' => $student, 'note' => 'N', 'timemodified' => 1]));
        $DB->insert_record(
            'format_spiffy_confidence',
            (object)($base + ['userid' => $student, 'confidence' => 2, 'timemodified' => 1])
        );
        $DB->insert_record('format_spiffy_question', (object)($base + ['userid' => $student, 'teacherid' => $teacher,
            'timecreated' => 1, 'timeseen' => 0]));
    }

    /**
     * A course reset that clears completion or unenrols clears the rows, and only then; settings stay.
     */
    public function test_course_reset_clears_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '', 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id]);
        \format_spiffy\local\cm_settings::save($course->id, $page->cmid, (object)['buttonlabel' => 'Keep']);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->add_rows($course, $page->cmid, $student->id, $teacher->id);

        reset_course_userdata((object)['id' => $course->id, 'courseid' => $course->id, 'reset_events' => 1,
            'reset_start_date_old' => $course->startdate]);
        $this->assertSame(1, $DB->count_records('format_spiffy_note', ['courseid' => $course->id]));

        reset_course_userdata((object)['id' => $course->id, 'courseid' => $course->id, 'reset_completion' => 1,
            'reset_start_date_old' => $course->startdate]);
        foreach (['note', 'confidence', 'question'] as $table) {
            $this->assertSame(0, $DB->count_records("format_spiffy_$table", ['courseid' => $course->id]));
        }
        $this->assertSame(1, $DB->count_records('format_spiffy_cm', ['courseid' => $course->id]));
    }

    /**
     * Removing a course's content, as a restore that deletes it does, leaves no rows behind.
     */
    public function test_course_content_deleted_clears_rows(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $course->id]);
        \format_spiffy\local\cm_settings::save($course->id, $page->cmid, (object)['buttonlabel' => 'Gone']);
        $this->add_rows($course, $page->cmid, 2, 2);
        remove_course_contents($course->id, false);
        foreach (['cm', 'note', 'confidence', 'question'] as $table) {
            $this->assertSame(0, $DB->count_records("format_spiffy_$table", ['courseid' => $course->id]));
        }
    }

    /**
     * Deleting a user removes their rows and keeps the other person's record of a question.
     */
    public function test_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'summary' => '']);
        $page = $generator->create_module('page', ['course' => $course->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->add_rows($course, $page->cmid, $student->id, $teacher->id);

        delete_user($student);
        $this->assertSame(0, $DB->count_records('format_spiffy_note', ['userid' => $student->id]));
        $this->assertSame(0, $DB->count_records('format_spiffy_confidence', ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records('format_spiffy_question', ['userid' => 0, 'teacherid' => $teacher->id]));

        delete_user($teacher);
        $this->assertSame(0, $DB->count_records('format_spiffy_question', ['courseid' => $course->id]));
    }

    /**
     * Rings count only what completion tracks, and a section of tracked work can finish beside
     * untracked work. Users who are not tracked get no ring.
     */
    public function test_progress_counts_tracked_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $tracked = $generator->create_module('page', ['course' => $course->id, 'section' => 1,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $generator->create_module('page', ['course' => $course->id, 'section' => 1]);
        $student = $generator->create_and_enrol($course, 'student');
        $this->setUser($student);
        $progress = $this->section($this->export_content($course), 1)->spiffyprogress;
        $this->assertSame([0, 1, false], [$progress['done'], $progress['total'], $progress['complete']]);

        $cm = get_fast_modinfo($course)->get_cm($tracked->cmid);
        (new \completion_info($course))->update_state($cm, COMPLETION_COMPLETE, $student->id);
        $progress = $this->section($this->export_content($course), 1)->spiffyprogress;
        $this->assertSame([1, 1, true], [$progress['done'], $progress['total'], $progress['complete']]);

        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $this->assertFalse($this->section($this->export_content($course), 1)->spiffyprogress['hasprogress']);
    }

    /**
     * An activity name with an ampersand is escaped once.
     */
    public function test_name_escaped_once(): void {
        global $PAGE;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Q&A']);
        $this->setUser($generator->create_and_enrol($course, 'student'));
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_context(\core\context\course::instance($course->id));
        $html = $PAGE->get_renderer('format_spiffy')->render(
            new \format_spiffy\output\courseformat\content(course_get_format($course))
        );
        $this->assertStringContainsString('Q&amp;A', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * A teacher who is not tracked gets no "Mark as done" button, and a student does.
     */
    public function test_mark_as_done_only_for_tracked_users(): void {
        global $PAGE;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'spiffy', 'numsections' => 1, 'enablecompletion' => 1, 'summary' => '']);
        $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $student = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $PAGE->set_url('/course/view.php', ['id' => $course->id]);
        $PAGE->set_context(\core\context\course::instance($course->id));

        $this->setUser($student);
        $render = fn() => $PAGE->get_renderer('format_spiffy')->render(
            new \format_spiffy\output\courseformat\content(course_get_format($course))
        );
        $this->assertStringContainsString('data-action="toggle-manual-completion"', $render());
        $this->setUser($teacher);
        $this->assertStringNotContainsString('toggle-manual-completion', $render());
    }
}
