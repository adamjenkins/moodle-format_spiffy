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
 * Backup of the Spiffy activity settings and, with user data, the students' notes and confidence checks.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Attaches the Spiffy settings of an activity to its backup and, when user data is included, the
 * students' private notes and confidence checks. The log of questions sent to teachers is not
 * backed up: the questions themselves are Moodle messages, which are not part of a course.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_format_spiffy_plugin extends backup_format_plugin {
    /**
     * Attach the button label, width, accent and inline mode of an activity to its backup, and
     * with user data the notes and confidence checks.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element(null, $this->get_format_condition(), 'spiffy');

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $settings = new backup_nested_element('spiffycm', null, ['buttonlabel', 'width', 'accent', 'inlinemode']);
        $pluginwrapper->add_child($settings);
        $settings->set_source_table('format_spiffy_cm', ['cmid' => backup::VAR_MODID]);

        if ($this->get_setting_value('userinfo')) {
            $notes = new backup_nested_element('spiffynotes');
            $note = new backup_nested_element('spiffynote', ['id'], ['userid', 'note', 'timemodified']);
            $pluginwrapper->add_child($notes);
            $notes->add_child($note);
            $note->set_source_table('format_spiffy_note', ['cmid' => backup::VAR_MODID]);
            $note->annotate_ids('user', 'userid');

            $checks = new backup_nested_element('spiffyconfidences');
            $check = new backup_nested_element('spiffyconfidence', ['id'], ['userid', 'confidence', 'timemodified']);
            $pluginwrapper->add_child($checks);
            $checks->add_child($check);
            $check->set_source_table('format_spiffy_confidence', ['cmid' => backup::VAR_MODID]);
            $check->annotate_ids('user', 'userid');
        }

        return $plugin;
    }
}
