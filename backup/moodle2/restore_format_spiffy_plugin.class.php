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
 * Restore of the Spiffy activity settings and, with user data, the students' notes and confidence checks.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the Spiffy settings of an activity and, with user data, the notes and confidence checks of
 * the restored users.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_format_spiffy_plugin extends restore_format_plugin {
    /** @var stdClass|null Settings read from the backup, pending the new course module id. */
    protected $pendingsettings = null;

    /** @var stdClass[] Notes read from the backup, pending the new course module id. */
    protected $pendingnotes = [];

    /** @var stdClass[] Confidence checks read from the backup, pending the new course module id. */
    protected $pendingconfidence = [];

    /**
     * Declare the paths handled at module level.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        $paths = [new restore_path_element('spiffycm', $this->get_pathfor('/spiffycm'))];
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('spiffynote', $this->get_pathfor('/spiffynotes/spiffynote'));
            $paths[] = new restore_path_element('spiffyconfidence', $this->get_pathfor('/spiffyconfidences/spiffyconfidence'));
        }
        return $paths;
    }

    /**
     * Stash the settings until the activity exists.
     *
     * @param array|stdClass $data
     * @return void
     */
    public function process_spiffycm($data) {
        $this->pendingsettings = (object)$data;
    }

    /**
     * Stash a student's note until the activity exists, if the student was restored.
     *
     * @param array|stdClass $data
     * @return void
     */
    public function process_spiffynote($data) {
        $data = (object)$data;
        $userid = $this->get_mappingid('user', $data->userid ?? 0);
        $note = \core_text::substr(
            trim(clean_param((string)($data->note ?? ''), PARAM_TEXT)),
            0,
            \format_spiffy\local\tools::NOTE_MAXLENGTH
        );
        if ($userid && $note !== '') {
            $this->pendingnotes[(int)$userid] = (object)[
                'userid' => (int)$userid,
                'note' => $note,
                'timemodified' => (int)($data->timemodified ?? 0),
            ];
        }
    }

    /**
     * Stash a student's confidence check until the activity exists, if the student was restored.
     *
     * @param array|stdClass $data
     * @return void
     */
    public function process_spiffyconfidence($data) {
        $data = (object)$data;
        $userid = $this->get_mappingid('user', $data->userid ?? 0);
        $confidence = (int)($data->confidence ?? 0);
        if ($userid && $confidence >= 1 && $confidence <= 3) {
            $this->pendingconfidence[(int)$userid] = (object)[
                'userid' => (int)$userid,
                'confidence' => $confidence,
                'timemodified' => (int)($data->timemodified ?? 0),
            ];
        }
    }

    /**
     * Write the stashed settings, notes and confidence checks for the restored activity. The
     * backup is untrusted, so the values are cleaned before they are stored.
     *
     * @return void
     */
    public function after_restore_module() {
        global $DB;
        $courseid = (int)$this->task->get_courseid();
        $cmid = (int)$this->task->get_moduleid();
        if ($this->pendingsettings !== null) {
            \format_spiffy\local\cm_settings::save($courseid, $cmid, $this->pendingsettings);
            $this->pendingsettings = null;
        }
        $tables = ['format_spiffy_note' => $this->pendingnotes, 'format_spiffy_confidence' => $this->pendingconfidence];
        foreach ($tables as $table => $records) {
            foreach ($records as $record) {
                if ($DB->record_exists($table, ['cmid' => $cmid, 'userid' => $record->userid])) {
                    continue;
                }
                $record->courseid = $courseid;
                $record->cmid = $cmid;
                $DB->insert_record($table, $record);
            }
        }
        $this->pendingnotes = [];
        $this->pendingconfidence = [];
    }
}
