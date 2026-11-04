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

/**
 * "Force sequential": make every activity of a section (or subsection) after the first wait for
 * the previous activity with completion; "Remove sequential" (action=remove) undoes it. Linked from
 * the section's edit menu, which asks for confirmation in a modal and links here with confirm=1;
 * without it (no JavaScript) this page asks.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

$sectionid = required_param('id', PARAM_INT);
$returnsection = optional_param('returnsection', 0, PARAM_INT);
$action = optional_param('action', 'apply', PARAM_ALPHA) === 'remove' ? 'remove' : 'apply';
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
$course = get_course($section->course);
$context = \core\context\course::instance($course->id);
$params = ['id' => $sectionid, 'action' => $action, 'returnsection' => $returnsection];
$PAGE->set_url(new moodle_url('/course/format/spiffy/sequential.php', $params));
require_login($course);
require_sesskey();
require_capability('moodle/course:manageactivities', $context);
if ($course->format !== 'spiffy' || !\format_spiffy\local\sequential::is_available()) {
    throw new moodle_exception('invalidcourseformat', 'format_spiffy');
}

$sectioninfo = get_fast_modinfo($course)->get_section_info_by_id($sectionid, MUST_EXIST);
$sectionname = get_section_name($course, $sectioninfo);
$string = $action === 'remove' ? 'unsequential' : 'forcesequential';

if ($returnsection) {
    $returnurl = new moodle_url('/course/section.php', ['id' => $returnsection]);
} else {
    $returnurl = course_get_url($course);
    $delegate = $sectioninfo->get_component_instance();
    $returnurl->set_anchor($delegate instanceof \core_courseformat\sectiondelegatemodule ?
        'module-' . $delegate->get_cm()->id : 'section-' . $sectioninfo->section);
}

if (!$confirm) {
    $PAGE->set_title(get_string($string, 'format_spiffy'));
    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string($string . 'confirm', 'format_spiffy', $sectionname),
        new moodle_url($PAGE->url, ['confirm' => 1, 'sesskey' => sesskey()]),
        $returnurl
    );
    echo $OUTPUT->footer();
    die();
}

if ($action === 'remove') {
    $changed = \format_spiffy\local\sequential::remove($sectioninfo);
} else {
    $changed = \format_spiffy\local\sequential::apply($sectioninfo);
}
$a = ['count' => count($changed), 'section' => $sectionname];
if ($changed) {
    \core\notification::success(get_string($string . 'done', 'format_spiffy', $a));
} else if ($action === 'apply' && empty($course->enablecompletion)) {
    \core\notification::warning(get_string('forcesequentialnocompletion', 'format_spiffy'));
} else {
    \core\notification::info(get_string($string . 'none', 'format_spiffy', $a));
}
redirect($returnurl);
