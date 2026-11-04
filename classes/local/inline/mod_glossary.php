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

use cm_info;
use renderer_base;
use stdClass;

/**
 * Glossary on a card: a quick "add a term" form, the user's own terms, and peer reactions.
 *
 * New terms go through mod_glossary_external::add_entry(), so the capability, duplicate and
 * approval rules apply unchanged. Classmates' latest approved terms can be rated (when the
 * glossary uses ratings and the user may rate) and commented on (when comments are on), through
 * core_rating_external::add_rating() and core_comment_external::add_comments().
 *
 * The peer reaction helpers (export_reactions(), process_reaction()) are shared with the
 * Database card (mod_data).
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_glossary extends handler {
    /** @var int How many of the user's own latest terms the card shows. */
    public const MAX_MINE = 3;

    /** @var int How many classmates' latest entries the card shows. */
    public const MAX_PEERS = 3;

    /** @var int How many of the latest comments an entry shows. */
    public const MAX_COMMENTS = 2;

    /** @var int Longest definition or comment accepted from the card, in characters. */
    public const MAX_LENGTH = 10000;

    /** @var int Longest concept accepted, in characters (the glossary_entries.concept column). */
    public const MAX_CONCEPT = 255;

    /** @var stdClass|null The glossary record, loaded once. */
    protected ?stdClass $glossary = null;

    /**
     * The glossary record.
     *
     * @return stdClass
     */
    protected function get_glossary(): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/glossary/lib.php');
        if ($this->glossary === null) {
            $this->glossary = $DB->get_record('glossary', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->glossary;
    }

    /**
     * Whether the current user may add terms.
     *
     * @return bool
     */
    protected function can_add(): bool {
        return isloggedin() && !isguestuser() && has_capability('mod/glossary:write', $this->cm->context);
    }

    /**
     * Whether comments are on for this glossary.
     *
     * @return bool
     */
    protected function comments_on(): bool {
        global $CFG;
        return !empty($CFG->usecomments) && !empty($this->get_glossary()->allowcomments);
    }

    /**
     * Whether classmates' entries can get reactions here: ratings or comments are on.
     *
     * @return bool
     */
    protected function reactions_on(): bool {
        return !empty($this->get_glossary()->assessed) || $this->comments_on();
    }

    #[\Override]
    protected function supports_inline(): bool {
        if ($this->can_add()) {
            return true;
        }
        return has_capability('mod/glossary:view', $this->cm->context) && $this->reactions_on();
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
     * @return array|null
     */
    protected function export_view(renderer_base $output, bool $done): ?array {
        global $DB, $USER;
        $glossary = $this->get_glossary();
        $context = $this->cm->context;

        $mine = [];
        $records = $DB->get_records(
            'glossary_entries',
            ['glossaryid' => $glossary->id, 'userid' => $USER->id],
            'timecreated DESC, id DESC',
            'id, concept, definition, definitionformat, definitiontrust, approved',
            0,
            self::MAX_MINE
        );
        foreach ($records as $entry) {
            $mine[] = [
                'id' => (int)$entry->id,
                'concept' => format_string($entry->concept, true, ['context' => $context]),
                'definition' => $this->format_definition($entry),
                'approved' => !empty($entry->approved),
            ];
        }

        $peers = [];
        if (has_capability('mod/glossary:view', $context)) {
            $userfields = \core_user\fields::for_userpic()->get_sql('u', false, 'u_', 'u_id', false)->selects;
            $records = $DB->get_records_sql(
                "SELECT e.id, e.userid, e.timecreated, e.concept, e.definition, e.definitionformat, e.definitiontrust,
                        $userfields
                   FROM {glossary_entries} e
                   JOIN {user} u ON u.id = e.userid
                  WHERE e.glossaryid = :glossaryid AND e.approved = 1 AND e.userid <> :userid
               ORDER BY e.timecreated DESC, e.id DESC",
                ['glossaryid' => $glossary->id, 'userid' => $USER->id],
                0,
                self::MAX_PEERS
            );
            $reactions = self::export_reactions(
                $this->cm,
                'mod_glossary',
                'glossary_entry',
                $glossary,
                $this->comments_on(),
                $records,
                $output
            );
            // Only these two display formats show the author on the glossary's own page.
            $showauthor = in_array($glossary->displayformat, ['fullwithauthor', 'encyclopedia'], true);
            foreach ($records as $entry) {
                $peer = self::export_peer($this->cm, $entry, $output);
                if (!$showauthor) {
                    $peer['author'] = '';
                    $peer['picture'] = '';
                }
                $peers[] = $peer + [
                    'title' => format_string($entry->concept, true, ['context' => $context]),
                    'body' => $this->format_definition($entry),
                    'fields' => [],
                    'hasfields' => false,
                    'url' => (new \moodle_url('/mod/glossary/showentry.php', ['eid' => $entry->id]))->out(false),
                ] + $reactions[$entry->id];
            }
        }

        $canadd = $this->can_add();
        if (!$canadd && !$peers && !$mine) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/glossary', 'context' => [
            'done' => $done,
            'canadd' => $canadd,
            'needsapproval' => empty($glossary->defaultapproval) && !has_capability('mod/glossary:approve', $context),
            'maxconcept' => self::MAX_CONCEPT,
            'maxlength' => self::MAX_LENGTH,
            'mine' => $mine,
            'hasmine' => !empty($mine),
            'peers' => $peers,
            'haspeers' => !empty($peers),
        ]];
    }

    /**
     * A glossary definition as HTML, with its embedded files.
     *
     * @param stdClass $entry with id, definition, definitionformat, definitiontrust
     * @return string
     */
    protected function format_definition(stdClass $entry): string {
        $context = $this->cm->context;
        $text = file_rewrite_pluginfile_urls(
            $entry->definition,
            'pluginfile.php',
            $context->id,
            'mod_glossary',
            'entry',
            $entry->id
        );
        return format_text($text, $entry->definitionformat, ['context' => $context, 'trusted' => $entry->definitiontrust]);
    }

    #[\Override]
    public function get_badges(): array {
        global $DB, $USER;
        $badges = parent::get_badges();
        if (!$this->cm->uservisible || !$this->cm->available || !isloggedin() || isguestuser()) {
            return $badges;
        }
        $pending = $DB->count_records(
            'glossary_entries',
            ['glossaryid' => $this->cm->instance, 'userid' => $USER->id, 'approved' => 0]
        );
        if ($pending) {
            $badges[] = ['text' => get_string('peer_badgepending', 'format_spiffy', $pending), 'type' => 'warning'];
        }
        return $badges;
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        global $CFG, $DB;
        $glossary = $this->get_glossary();
        switch ($action) {
            case 'add':
                $concept = trim(clean_param((string)($data['concept'] ?? ''), PARAM_TEXT));
                $definition = trim(clean_param((string)($data['definition'] ?? ''), PARAM_RAW));
                if ($concept === '' || $definition === '') {
                    throw new \moodle_exception('glossary_empty', 'format_spiffy');
                }
                if (\core_text::strlen($concept) > self::MAX_CONCEPT || \core_text::strlen($definition) > self::MAX_LENGTH) {
                    throw new \moodle_exception('peer_toolong', 'format_spiffy', '', self::MAX_LENGTH);
                }
                require_once($CFG->dirroot . '/mod/glossary/lib.php');
                // Core's checks apply: the write capability and duplicate concepts.
                $result = \mod_glossary_external::add_entry($glossary->id, $concept, $definition, FORMAT_MOODLE);
                $approved = $DB->get_field('glossary_entries', 'approved', ['id' => $result['entryid']]);
                return get_string($approved ? 'glossary_added' : 'glossary_addedpending', 'format_spiffy');
            case 'rate':
            case 'comment':
                $entry = $DB->get_record(
                    'glossary_entries',
                    ['id' => (int)($data['itemid'] ?? 0), 'glossaryid' => $glossary->id, 'approved' => 1],
                    'id, userid'
                );
                if (!$entry) {
                    throw new \moodle_exception('peer_invalidentry', 'format_spiffy');
                }
                return self::process_reaction(
                    $this->cm,
                    'mod_glossary',
                    'glossary_entry',
                    $glossary,
                    $this->comments_on(),
                    $action,
                    $data,
                    $entry
                );
        }
        throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
    }

    /**
     * Author and time of a classmate's entry, for the peer template.
     *
     * @param cm_info $cm
     * @param stdClass $entry with id, timecreated and the user picture fields prefixed u_ (id as u_id)
     * @param renderer_base $output
     * @return array
     */
    public static function export_peer(cm_info $cm, stdClass $entry, renderer_base $output): array {
        $user = \core\output\user_picture::unalias($entry, null, 'u_id', 'u_');
        return [
            'id' => (int)$entry->id,
            'author' => fullname($user, has_capability('moodle/site:viewfullnames', $cm->context)),
            'picture' => $output->user_picture($user, ['size' => 35, 'courseid' => $cm->course, 'link' => false,
                'alttext' => false]),
            'time' => userdate($entry->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'isotime' => date('c', (int)$entry->timecreated),
        ];
    }

    /**
     * Ratings and comments on classmates' entries, for the template format_spiffy/inline/glossary_reactions.
     *
     * Uses the rating manager and the comment manager directly, as an external function cannot be
     * called while the page renders.
     *
     * @param cm_info $cm the glossary or database
     * @param string $component mod_glossary or mod_data
     * @param string $commentarea glossary_entry or database_entry
     * @param stdClass $instance the activity record: assessed, scale, assesstimestart, assesstimefinish
     * @param bool $commentson whether comments are on for the activity
     * @param stdClass[] $entries the entries, each with id, userid and timecreated
     * @param renderer_base $output
     * @return array[] by entry id: ['rating' => array|false, 'comments' => array|false]
     */
    public static function export_reactions(
        cm_info $cm,
        string $component,
        string $commentarea,
        stdClass $instance,
        bool $commentson,
        array $entries,
        renderer_base $output
    ): array {
        global $CFG, $USER;
        $result = [];
        foreach ($entries as $entry) {
            $result[$entry->id] = ['rating' => false, 'comments' => false];
        }
        if (!$entries) {
            return $result;
        }

        if (!empty($instance->assessed)) {
            require_once($CFG->dirroot . '/rating/lib.php');
            $items = [];
            foreach ($entries as $entry) {
                $items[$entry->id] = (object)['id' => $entry->id, 'userid' => $entry->userid, 'timecreated' => $entry->timecreated];
            }
            $manager = new \rating_manager();
            $rated = $manager->get_ratings((object)[
                'context' => $cm->context,
                'component' => $component,
                'ratingarea' => 'entry',
                'items' => $items,
                'aggregate' => $instance->assessed,
                'scaleid' => $instance->scale,
                'userid' => $USER->id,
                'returnurl' => $cm->url ? $cm->url->out(false) : '',
                'assesstimestart' => $instance->assesstimestart,
                'assesstimefinish' => $instance->assesstimefinish,
            ]);
            foreach ($rated as $item) {
                $rating = $item->rating ?? null;
                if (!$rating instanceof \rating) {
                    continue;
                }
                $canrate = $rating->user_can_rate();
                $canview = $rating->user_can_view_aggregate();
                if (!$canrate && !$canview) {
                    continue;
                }
                $scale = [];
                foreach ($rating->settings->scale->scaleitems as $value => $label) {
                    $scale[] = [
                        'value' => (int)$value,
                        'label' => format_string((string)$label, true, ['context' => $cm->context]),
                        'selected' => $rating->rating !== null && (int)$rating->rating === (int)$value,
                    ];
                }
                $summary = '';
                if ($canview && $rating->count > 0) {
                    $summary = get_string(
                        'peer_ratingsummary',
                        'format_spiffy',
                        ['aggregate' => $rating->get_aggregate_string(), 'count' => (int)$rating->count]
                    );
                }
                $result[$item->id]['rating'] = [
                    'canrate' => $canrate,
                    'scale' => $scale,
                    'summary' => $summary,
                    'hassummary' => $summary !== '',
                ];
            }
        }

        if ($commentson && !empty($CFG->usecomments)) {
            $course = $cm->get_course();
            foreach ($entries as $entry) {
                try {
                    $manager = new \core_comment\manager((object)[
                        'context' => $cm->context,
                        'course' => $course,
                        'cm' => $cm,
                        'component' => $component,
                        'area' => $commentarea,
                        'itemid' => $entry->id,
                    ]);
                    if (!$manager->can_view()) {
                        continue;
                    }
                    $count = (int)$manager->count();
                    $list = [];
                    if ($count) {
                        $comments = array_slice($manager->get_comments(0, 'DESC') ?: [], 0, self::MAX_COMMENTS);
                        foreach (array_reverse($comments) as $comment) {
                            $list[] = [
                                'author' => $comment->fullname,
                                'time' => userdate($comment->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
                                'isotime' => date('c', (int)$comment->timecreated),
                                'content' => $comment->content,
                            ];
                        }
                    }
                    $result[$entry->id]['comments'] = [
                        'count' => $count,
                        'list' => $list,
                        'hascomments' => !empty($list),
                        'more' => $count > count($list),
                        'canpost' => $manager->can_post(),
                        'maxlength' => self::MAX_LENGTH,
                    ];
                } catch (\moodle_exception $e) {
                    // The module refuses comments on this entry (e.g. not approved, other group): show none.
                    continue;
                }
            }
        }
        return $result;
    }

    /**
     * Rate or comment on a classmate's entry, through core's external functions so their checks apply.
     *
     * @param cm_info $cm the glossary or database
     * @param string $component mod_glossary or mod_data
     * @param string $commentarea glossary_entry or database_entry
     * @param stdClass $instance the activity record: assessed, scale
     * @param bool $commentson whether comments are on for the activity
     * @param string $action rate or comment
     * @param array $data the submitted fields: rating, or content
     * @param stdClass $entry the entry, checked to belong to the activity: id, userid
     * @return string the message to show
     */
    public static function process_reaction(
        cm_info $cm,
        string $component,
        string $commentarea,
        stdClass $instance,
        bool $commentson,
        string $action,
        array $data,
        stdClass $entry
    ): string {
        global $CFG;
        if ($action === 'rate') {
            if (empty($instance->assessed)) {
                throw new \moodle_exception('peer_noratings', 'format_spiffy');
            }
            $raw = trim((string)($data['rating'] ?? ''));
            if ($raw === '' || !is_numeric($raw)) {
                throw new \moodle_exception('peer_chooserating', 'format_spiffy');
            }
            require_once($CFG->dirroot . '/rating/lib.php');
            // Core's checks apply: the rate capabilities, the scale, the assessment times and own items.
            \core_rating_external::add_rating(
                'module',
                $cm->id,
                $component,
                'entry',
                (int)$entry->id,
                (int)$instance->scale,
                (int)$raw,
                (int)$entry->userid,
                (int)$instance->assessed
            );
            return get_string('peer_ratingsaved', 'format_spiffy');
        }
        if ($action === 'comment') {
            if (!$commentson) {
                throw new \moodle_exception('commentsnotenabled', 'moodle');
            }
            $content = trim(clean_param((string)($data['content'] ?? ''), PARAM_RAW));
            if ($content === '') {
                throw new \moodle_exception('peer_emptycomment', 'format_spiffy');
            }
            if (\core_text::strlen($content) > self::MAX_LENGTH) {
                throw new \moodle_exception('peer_toolong', 'format_spiffy', '', self::MAX_LENGTH);
            }
            // Core's checks apply: comments enabled, the comment capability and the module's own rules.
            \core_comment_external::add_comments([[
                'contextlevel' => 'module',
                'instanceid' => $cm->id,
                'component' => $component,
                'content' => $content,
                'itemid' => (int)$entry->id,
                'area' => $commentarea,
            ]]);
            return get_string('peer_commentsaved', 'format_spiffy');
        }
        throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
    }
}
