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

namespace format_spiffy\privacy;

use core\context;
use core\context\module as context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for format_spiffy.
 *
 * The format stores, per activity, a student's private note, their confidence check and a log of
 * the questions they sent a teacher (who asked whom and when; the text is a Moodle message and
 * belongs to core_message). All of it lives in the activity's context. It also keeps the user's
 * choice of spotlight or list view as a preference.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    #[\Override]
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('format_spiffy_note', [
            'userid' => 'privacy:metadata:note:userid',
            'cmid' => 'privacy:metadata:note:cmid',
            'note' => 'privacy:metadata:note:note',
            'timemodified' => 'privacy:metadata:note:timemodified',
        ], 'privacy:metadata:note');
        $collection->add_database_table('format_spiffy_confidence', [
            'userid' => 'privacy:metadata:confidence:userid',
            'cmid' => 'privacy:metadata:confidence:cmid',
            'confidence' => 'privacy:metadata:confidence:confidence',
            'timemodified' => 'privacy:metadata:confidence:timemodified',
        ], 'privacy:metadata:confidence');
        $collection->add_database_table('format_spiffy_question', [
            'userid' => 'privacy:metadata:question:userid',
            'teacherid' => 'privacy:metadata:question:teacherid',
            'cmid' => 'privacy:metadata:question:cmid',
            'timecreated' => 'privacy:metadata:question:timecreated',
            'timeseen' => 'privacy:metadata:question:timeseen',
        ], 'privacy:metadata:question');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_user_preference('format_spiffy_viewmode', 'privacy:metadata:preference:viewmode');
        return $collection;
    }

    #[\Override]
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid];
        foreach (['format_spiffy_note', 'format_spiffy_confidence'] as $table) {
            $contextlist->add_from_sql("SELECT ctx.id
                                          FROM {{$table}} t
                                          JOIN {context} ctx ON ctx.instanceid = t.cmid AND ctx.contextlevel = :contextlevel
                                         WHERE t.userid = :userid", $params);
        }
        $contextlist->add_from_sql(
            "SELECT ctx.id
                                      FROM {format_spiffy_question} q
                                      JOIN {context} ctx ON ctx.instanceid = q.cmid AND ctx.contextlevel = :contextlevel
                                     WHERE q.userid = :userid OR q.teacherid = :teacherid",
            $params + ['teacherid' => $userid]
        );
        return $contextlist;
    }

    #[\Override]
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        $params = ['cmid' => $context->instanceid];
        $userlist->add_from_sql('userid', 'SELECT userid FROM {format_spiffy_note} WHERE cmid = :cmid', $params);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {format_spiffy_confidence} WHERE cmid = :cmid', $params);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {format_spiffy_question} WHERE cmid = :cmid AND userid > 0', $params);
        $userlist->add_from_sql(
            'teacherid',
            'SELECT teacherid FROM {format_spiffy_question} WHERE cmid = :cmid AND teacherid > 0',
            $params
        );
    }

    #[\Override]
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        $path = [get_string('privacy:path', 'format_spiffy')];
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cmid = (int)$context->instanceid;
            $data = [];

            if ($note = $DB->get_record('format_spiffy_note', ['cmid' => $cmid, 'userid' => $userid])) {
                $data['note'] = (object)[
                    'note' => $note->note,
                    'timemodified' => transform::datetime($note->timemodified),
                ];
            }
            if ($confidence = $DB->get_record('format_spiffy_confidence', ['cmid' => $cmid, 'userid' => $userid])) {
                $value = (int)$confidence->confidence;
                $data['confidence'] = (object)[
                    'confidence' => in_array($value, [1, 2, 3], true) ?
                        get_string('confidence' . $value, 'format_spiffy') : $value,
                    'timemodified' => transform::datetime($confidence->timemodified),
                ];
            }
            $asked = [];
            foreach ($DB->get_records('format_spiffy_question', ['cmid' => $cmid, 'userid' => $userid], 'timecreated') as $q) {
                $asked[] = (object)[
                    'teacher' => transform::user($q->teacherid),
                    'timecreated' => transform::datetime($q->timecreated),
                    'timeseen' => $q->timeseen ? transform::datetime($q->timeseen) : '-',
                ];
            }
            if ($asked) {
                $data['questionsasked'] = $asked;
            }
            $received = [];
            foreach ($DB->get_records('format_spiffy_question', ['cmid' => $cmid, 'teacherid' => $userid], 'timecreated') as $q) {
                $received[] = (object)[
                    'timecreated' => transform::datetime($q->timecreated),
                    'timeseen' => $q->timeseen ? transform::datetime($q->timeseen) : '-',
                ];
            }
            if ($received) {
                $data['questionsreceived'] = $received;
            }

            if ($data) {
                writer::with_context($context)->export_data($path, (object)$data);
            }
        }
    }

    #[\Override]
    public static function export_user_preferences(int $userid): void {
        $viewmode = get_user_preferences('format_spiffy_viewmode', null, $userid);
        if ($viewmode !== null) {
            writer::export_user_preference(
                'format_spiffy',
                'format_spiffy_viewmode',
                $viewmode,
                get_string('privacy:metadata:preference:viewmode', 'format_spiffy')
            );
        }
    }

    #[\Override]
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if (!$context instanceof context_module) {
            return;
        }
        foreach (['format_spiffy_note', 'format_spiffy_confidence', 'format_spiffy_question'] as $table) {
            $DB->delete_records($table, ['cmid' => $context->instanceid]);
        }
    }

    #[\Override]
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cmid = (int)$context->instanceid;
            $DB->delete_records('format_spiffy_note', ['cmid' => $cmid, 'userid' => $userid]);
            $DB->delete_records('format_spiffy_confidence', ['cmid' => $cmid, 'userid' => $userid]);
            \format_spiffy\local\cm_settings::anonymise_questions($userid, $cmid);
        }
    }

    #[\Override]
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$context instanceof context_module || !$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = ['cmid' => $context->instanceid] + $inparams;
        $DB->delete_records_select('format_spiffy_note', "cmid = :cmid AND userid $insql", $params);
        $DB->delete_records_select('format_spiffy_confidence', "cmid = :cmid AND userid $insql", $params);
        foreach ($userids as $userid) {
            \format_spiffy\local\cm_settings::anonymise_questions((int)$userid, (int)$context->instanceid);
        }
    }
}
