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
 * Admin settings for format_spiffy.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    require_once($CFG->dirroot . '/course/format/spiffy/lib.php');

    $settings->add(new admin_setting_configselect(
        'format_spiffy/enablelinearnav',
        new lang_string('linearnavigationsettings', 'core_courseformat'),
        new lang_string('linearnavigationsettings_help', 'core_courseformat'),
        1,
        [1 => new lang_string('yes'), 0 => new lang_string('no')],
    ));

    // Defaults of the course settings: new courses start with these, and courses that never saved
    // a setting follow them.
    $settings->add(new admin_setting_heading(
        'format_spiffy/defaultsheading',
        new lang_string('defaultsheading', 'format_spiffy'),
        new lang_string('defaultsheading_desc', 'format_spiffy'),
    ));
    $defaults = format_spiffy::builtin_defaults();
    $choices = format_spiffy::display_choices();
    foreach ($defaults as $name => $default) {
        $label = new lang_string($name, 'format_spiffy');
        $help = new lang_string($name . '_help', 'format_spiffy');
        if ($name === 'columns') {
            $settings->add(new admin_setting_configselect(
                'format_spiffy/default_columns',
                $label,
                $help,
                $default,
                [1 => 1, 2 => 2, 3 => 3, 4 => 4]
            ));
        } else if (isset($choices[$name])) {
            $settings->add(new admin_setting_configselect(
                'format_spiffy/default_' . $name,
                $label,
                $help,
                $default,
                format_spiffy::option_labels($name, $choices[$name])
            ));
        } else if ($name === 'sectionword') {
            $settings->add(new admin_setting_configtext(
                'format_spiffy/default_sectionword',
                $label,
                $help,
                $default,
                PARAM_TEXT,
                20
            ));
        } else {
            $settings->add(new admin_setting_configcheckbox('format_spiffy/default_' . $name, $label, $help, $default));
        }
    }
}
