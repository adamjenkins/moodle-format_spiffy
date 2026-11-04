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

namespace format_spiffy\local\inline;

use renderer_base;
use stdClass;

/**
 * Database on a card: an entry form for simple databases, the user's latest entry, and peer reactions.
 *
 * A database is simple when it has at most MAX_FIELDS fields, all of a type a plain form can fill
 * in (text, text area, number, URL, menu, radio buttons, checkboxes). Entries go through
 * mod_data_external::add_entry(), so the capability, entry limit, group, availability dates and
 * field validation apply unchanged. Classmates' latest entries can be rated and commented on
 * when the database uses ratings or comments (the helpers are shared with mod_glossary).
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_data extends handler {
    /** @var string[] Field types the card's form can fill in. */
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'url', 'menu', 'radiobutton', 'checkbox'];

    /** @var int Most fields a database may have to be filled in on the card. */
    public const MAX_FIELDS = 6;

    /** @var int How many classmates' latest entries the card shows. */
    public const MAX_PEERS = 3;

    /** @var int Longest value accepted for one field, in characters. */
    public const MAX_LENGTH = 10000;

    /** @var stdClass|null The database record, loaded once. */
    protected ?stdClass $data = null;

    /** @var stdClass[]|null The fields of the database by id, loaded once. */
    protected ?array $fields = null;

    /** @var bool|null Whether the user can add an entry now, worked out once. */
    protected ?bool $canadd = null;

    /**
     * The database record.
     *
     * @return stdClass
     */
    protected function get_data(): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/data/lib.php');
        require_once($CFG->dirroot . '/mod/data/locallib.php');
        if ($this->data === null) {
            $this->data = $DB->get_record('data', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->data;
    }

    /**
     * The fields of the database.
     *
     * @return stdClass[] data_fields records by id, in creation order
     */
    protected function get_fields(): array {
        global $DB;
        if ($this->fields === null) {
            $this->fields = $DB->get_records('data_fields', ['dataid' => $this->cm->instance], 'id ASC');
        }
        return $this->fields;
    }

    /**
     * Whether the database is simple enough for the card's form.
     *
     * @return bool
     */
    public function is_simple(): bool {
        $fields = $this->get_fields();
        if (!$fields || count($fields) > self::MAX_FIELDS) {
            return false;
        }
        foreach ($fields as $field) {
            if (!in_array($field->type, self::FIELD_TYPES, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether the current user may add an entry now: capability, entry limit, read-only period,
     * group and availability dates.
     *
     * @return bool
     */
    protected function can_add(): bool {
        if ($this->canadd === null) {
            $data = $this->get_data();
            $context = $this->cm->context;
            $groupmode = groups_get_activity_groupmode($this->cm);
            $group = $groupmode ? (int)groups_get_activity_group($this->cm) : 0;
            [$open] = data_get_time_availability_status($data, null, $context);
            $this->canadd = isloggedin() && !isguestuser() && $open
                && data_user_can_add_entry($data, $group, $groupmode, $context);
        }
        return $this->canadd;
    }

    /**
     * The user's latest entry.
     *
     * @return stdClass|null data_records record
     */
    protected function get_my_latest(): ?stdClass {
        global $DB, $USER;
        $records = $DB->get_records(
            'data_records',
            ['dataid' => $this->cm->instance, 'userid' => $USER->id],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );
        return $records ? reset($records) : null;
    }

    /**
     * Whether comments are on for this database.
     *
     * @return bool
     */
    protected function comments_on(): bool {
        global $CFG;
        return !empty($CFG->usecomments) && !empty($this->get_data()->comments);
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!$this->is_simple() || !has_capability('mod/data:viewentry', $this->cm->context)) {
            return false;
        }
        return $this->can_add() || $this->get_my_latest() !== null;
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return $this->export_view($output, false);
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return $this->export_view($output, true);
    }

    /**
     * The card view, shared by the available and done states.
     *
     * @param renderer_base $output
     * @param bool $done whether the user has done the activity
     * @return array
     */
    protected function export_view(renderer_base $output, bool $done): array {
        global $DB, $USER;
        $data = $this->get_data();
        $context = $this->cm->context;
        $canadd = $this->can_add();

        $mine = $this->get_my_latest();
        $peers = $this->get_peer_records();
        $records = $peers;
        if ($mine) {
            $records[$mine->id] = $mine;
        }
        $contents = [];
        if ($records) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($records));
            foreach ($DB->get_recordset_select('data_content', "recordid $insql", $params) as $content) {
                $contents[$content->recordid][$content->fieldid] = $content;
            }
        }

        $exportedpeers = [];
        if ($peers) {
            $reactions = mod_glossary::export_reactions(
                $this->cm,
                'mod_data',
                'database_entry',
                $data,
                $this->comments_on(),
                $peers,
                $output
            );
            foreach ($peers as $record) {
                $fields = $this->export_contents($contents[$record->id] ?? []);
                $exportedpeers[] = mod_glossary::export_peer($this->cm, $record, $output) + [
                    'title' => '',
                    'body' => '',
                    'fields' => $fields,
                    'hasfields' => !empty($fields),
                    'url' => (new \moodle_url('/mod/data/view.php', ['d' => $data->id, 'rid' => $record->id]))->out(false),
                ] + $reactions[$record->id];
            }
        }

        $exportedmine = null;
        if ($mine) {
            $fields = $this->export_contents($contents[$mine->id] ?? []);
            $exportedmine = [
                'fields' => $fields,
                'approved' => empty($data->approval) || !empty($mine->approved),
                'url' => (new \moodle_url('/mod/data/view.php', ['d' => $data->id, 'rid' => $mine->id]))->out(false),
            ];
        }

        $remaining = '';
        if ($canadd && !empty($data->maxentries) && !has_capability('mod/data:manageentries', $context)) {
            $left = max(0, (int)$data->maxentries - (int)data_numentries($data, $USER->id));
            $remaining = get_string('data_remaining', 'format_spiffy', $left);
        }

        return ['template' => 'format_spiffy/inline/data', 'context' => [
            'done' => $done,
            'canadd' => $canadd,
            'fields' => $canadd ? $this->export_form_fields() : [],
            'needsapproval' => !empty($data->approval) && !has_capability('mod/data:approve', $context),
            'remaining' => $remaining,
            'hasmine' => $exportedmine !== null,
            'mine' => $exportedmine ?? false,
            'peers' => $exportedpeers,
            'haspeers' => !empty($exportedpeers),
            'maxlength' => self::MAX_LENGTH,
        ]];
    }

    /**
     * Classmates' latest entries the current user may see, with their authors.
     *
     * Follows data_can_view_record(): approved entries when the database needs approval, the
     * user's group and entries for all groups, and only once the user has added the entries
     * the database asks for before others' can be seen.
     *
     * @return stdClass[] data_records rows with the user picture fields prefixed u_ (id as u_id), by id
     */
    protected function get_peer_records(): array {
        global $DB, $USER;
        $data = $this->get_data();
        $context = $this->cm->context;
        if (!has_capability('mod/data:viewentry', $context)) {
            return [];
        }
        $canmanage = has_capability('mod/data:manageentries', $context);
        if (
            !$canmanage && !empty($data->requiredentriestoview)
                && data_numentries($data, $USER->id) < $data->requiredentriestoview
        ) {
            return [];
        }
        // As mod/data/view.php: nobody but managers sees the entries outside the open dates.
        if (!$canmanage && !data_get_time_availability_status($data, null, $context)[0]) {
            return [];
        }
        $where = 'r.dataid = :dataid AND r.userid <> :userid';
        $params = ['dataid' => $data->id, 'userid' => $USER->id];
        if (!$canmanage && !empty($data->approval)) {
            $where .= ' AND r.approved = 1';
        }
        $groupmode = (int)groups_get_activity_groupmode($this->cm);
        $group = $groupmode ? (int)groups_get_activity_group($this->cm) : 0;
        if ($group) {
            $where .= ' AND (r.groupid = 0 OR r.groupid = :groupid)';
            $params['groupid'] = $group;
        } else if ($groupmode === SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            // In no group under separate groups: entries for all groups only, as data_search_entries().
            $where .= ' AND r.groupid = 0';
        }
        $userfields = \core_user\fields::for_userpic()->get_sql('u', false, 'u_', 'u_id', false)->selects;
        return $DB->get_records_sql(
            "SELECT r.id, r.userid, r.groupid, r.timecreated, r.approved, $userfields
               FROM {data_records} r
               JOIN {user} u ON u.id = r.userid
              WHERE $where
           ORDER BY r.timecreated DESC, r.id DESC",
            $params,
            0,
            self::MAX_PEERS
        );
    }

    /**
     * The values of an entry, as field name and HTML value pairs in field order.
     *
     * @param stdClass[] $contents data_content records of the entry by field id
     * @return array[]
     */
    protected function export_contents(array $contents): array {
        $context = $this->cm->context;
        $result = [];
        foreach ($this->get_fields() as $field) {
            $content = $contents[$field->id] ?? null;
            if (!$content || (string)$content->content === '') {
                continue;
            }
            switch ($field->type) {
                case 'textarea':
                    $text = file_rewrite_pluginfile_urls(
                        $content->content,
                        'pluginfile.php',
                        $context->id,
                        'mod_data',
                        'content',
                        $content->id
                    );
                    $value = format_text($text, (int)$content->content1, ['context' => $context, 'para' => false]);
                    break;
                case 'url':
                    $url = clean_param($content->content, PARAM_URL);
                    if ($url === '') {
                        continue 2;
                    }
                    $label = trim((string)$content->content1) !== '' ? $content->content1 : $url;
                    // PARAM_URL lets some javascript: values through, and the field may ask not to be linked.
                    // Unlinked, it shows the address, as the field does in core.
                    $linked = !empty($field->param1) && preg_match('~^(https?://|ftp://|/(?!/))~i', $url);
                    $value = $linked ? \html_writer::link($url, s($label)) : s($url);
                    break;
                case 'checkbox':
                    $values = array_filter(explode('##', $content->content), fn($v) => $v !== '');
                    $value = implode(', ', array_map(fn($v) => format_string($v, true, ['context' => $context]), $values));
                    break;
                default:
                    $value = format_string($content->content, true, ['context' => $context]);
            }
            if ($value === '') {
                continue;
            }
            $result[] = [
                'name' => format_string($field->name, true, ['context' => $context]),
                'value' => $value,
            ];
        }
        return $result;
    }

    /**
     * The options of a menu, radio button or checkbox field.
     *
     * @param stdClass $field
     * @return string[]
     */
    protected static function field_options(stdClass $field): array {
        $options = array_map('trim', explode("\n", (string)$field->param1));
        return array_values(array_filter($options, fn($option) => $option !== ''));
    }

    /**
     * The fields of the entry form.
     *
     * @return array[]
     */
    protected function export_form_fields(): array {
        $context = $this->cm->context;
        $result = [];
        foreach ($this->get_fields() as $field) {
            $options = [];
            if (in_array($field->type, ['menu', 'radiobutton', 'checkbox'], true)) {
                foreach (self::field_options($field) as $index => $option) {
                    $options[] = [
                        'index' => $index,
                        'value' => $option,
                        'label' => format_string($option, true, ['context' => $context]),
                    ];
                }
            }
            $description = trim(format_string((string)$field->description, true, ['context' => $context]));
            $result[] = [
                'id' => (int)$field->id,
                'name' => format_string($field->name, true, ['context' => $context]),
                'description' => $description,
                'hasdescription' => $description !== '',
                'required' => !empty($field->required),
                'istext' => $field->type === 'text',
                'isnumber' => $field->type === 'number',
                'isurl' => $field->type === 'url',
                'istextarea' => $field->type === 'textarea',
                'ismenu' => $field->type === 'menu',
                'isradio' => $field->type === 'radiobutton',
                'ischeckbox' => $field->type === 'checkbox',
                'isgroup' => in_array($field->type, ['radiobutton', 'checkbox'], true),
                'options' => $options,
            ];
        }
        return $result;
    }

    #[\Override]
    public function get_badges(): array {
        global $DB, $USER;
        $badges = parent::get_badges();
        if (!$this->cm->uservisible || !$this->cm->available || !isloggedin() || isguestuser()) {
            return $badges;
        }
        if (!empty($this->get_data()->approval)) {
            $pending = $DB->count_records(
                'data_records',
                ['dataid' => $this->cm->instance, 'userid' => $USER->id, 'approved' => 0]
            );
            if ($pending) {
                $badges[] = ['text' => get_string('peer_badgepending', 'format_spiffy', $pending), 'type' => 'warning'];
            }
        }
        return $badges;
    }

    /**
     * Turn the card's form fields into mod_data_external::add_entry() data.
     *
     * Values of menu, radio button and checkbox fields must be one of the field's options; empty
     * values are left out, so core's "required" check reports them.
     *
     * @param array $data the submitted fields: f<fieldid> => string, or string[] for checkboxes
     * @return array[] each ['fieldid', 'subfield', 'value' (JSON)]
     */
    protected function entry_data(array $data): array {
        $entry = [];
        foreach ($this->get_fields() as $field) {
            $raw = $data['f' . $field->id] ?? null;
            if ($field->type === 'checkbox') {
                $values = array_values(array_intersect(self::field_options($field), array_map('strval', (array)($raw ?? []))));
                if ($values) {
                    $entry[] = ['fieldid' => $field->id, 'subfield' => '', 'value' => json_encode($values)];
                }
                continue;
            }
            $value = is_string($raw) ? trim($raw) : '';
            if ($value === '') {
                continue;
            }
            if (\core_text::strlen($value) > self::MAX_LENGTH) {
                throw new \moodle_exception('peer_toolong', 'format_spiffy', '', self::MAX_LENGTH);
            }
            switch ($field->type) {
                case 'menu':
                case 'radiobutton':
                    if (in_array($value, self::field_options($field), true)) {
                        $entry[] = ['fieldid' => $field->id, 'subfield' => '', 'value' => json_encode($value)];
                    }
                    break;
                case 'url':
                    $entry[] = ['fieldid' => $field->id, 'subfield' => '0', 'value' => json_encode($value)];
                    break;
                case 'textarea':
                    // A plain text area: the text, its format and no draft files.
                    $entry[] = ['fieldid' => $field->id, 'subfield' => 'content1', 'value' => json_encode(FORMAT_MOODLE)];
                    $entry[] = ['fieldid' => $field->id, 'subfield' => 'itemid', 'value' => json_encode(0)];
                    $entry[] = ['fieldid' => $field->id, 'subfield' => '', 'value' => json_encode($value)];
                    break;
                default:
                    $entry[] = ['fieldid' => $field->id, 'subfield' => '', 'value' => json_encode($value)];
            }
        }
        return $entry;
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        global $DB;
        $database = $this->get_data();
        switch ($action) {
            case 'add':
                if (!$this->is_simple()) {
                    throw new \moodle_exception('noaccess', 'data');
                }
                // Core's checks apply: capability, entry limit, group, dates, required fields and validation.
                $result = \mod_data_external::add_entry($database->id, 0, $this->entry_data($data));
                if (empty($result['newentryid'])) {
                    $messages = $result['generalnotifications'];
                    foreach ($result['fieldnotifications'] as $notification) {
                        $messages[] = $notification['fieldname'] . ': ' . $notification['notification'];
                    }
                    $messages = array_map(fn($message) => clean_param($message, PARAM_NOTAGS), $messages);
                    throw new \moodle_exception('data_notsaved', 'format_spiffy', '', implode(' ', $messages));
                }
                $approved = $DB->get_field('data_records', 'approved', ['id' => $result['newentryid']]);
                return get_string(empty($database->approval) || $approved ? 'data_added' : 'data_addedpending', 'format_spiffy');
            case 'rate':
            case 'comment':
                $entry = $DB->get_record(
                    'data_records',
                    ['id' => (int)($data['itemid'] ?? 0), 'dataid' => $database->id],
                    'id, userid, approved'
                );
                if (!$entry || (!empty($database->approval) && empty($entry->approved))) {
                    throw new \moodle_exception('peer_invalidentry', 'format_spiffy');
                }
                return mod_glossary::process_reaction(
                    $this->cm,
                    'mod_data',
                    'database_entry',
                    $database,
                    $this->comments_on(),
                    $action,
                    $data,
                    $entry
                );
        }
        throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
    }
}
