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
 * Forum on a card: the opening post and the latest replies of a simple discussion, with a reply box.
 *
 * Only forums with one discussion are done inline: a "single simple discussion" forum, or a
 * standard forum that has exactly one discussion. Other forum types open their page. Replies go
 * through mod_forum_external::add_discussion_post(), so the forum's capability, group, lock,
 * cut-off and posting threshold checks apply unchanged. The views use mod_forum's lib functions:
 * an external function resets the page output, so it cannot be called while the page renders.
 *
 * A forum stays interactive once done: the done view is the same discussion and reply box.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_forum extends handler {
    /** @var int How many of the latest replies the card shows. */
    public const MAX_REPLIES = 3;

    /** @var int Longest reply accepted from the card, in characters. */
    public const MAX_LENGTH = 10000;

    /** @var stdClass|null The forum record, loaded once. */
    protected ?stdClass $forum = null;

    /** @var stdClass|false|null The one discussion, false when the forum has none or several; loaded once. */
    protected stdClass|false|null $discussion = null;

    /**
     * The forum record.
     *
     * @return stdClass
     */
    protected function get_forum(): stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        if ($this->forum === null) {
            $this->forum = $DB->get_record('forum', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->forum;
    }

    /**
     * The discussion the card shows: the only discussion of a single or standard forum.
     *
     * @return stdClass|null the forum_discussions record, or null when the forum is not simple
     */
    protected function get_discussion(): ?stdClass {
        global $DB;
        if ($this->discussion === null) {
            $this->discussion = false;
            $forum = $this->get_forum();
            if (in_array($forum->type, ['single', 'general'], true)) {
                $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'id ASC', '*', 0, 2);
                if (count($discussions) === 1) {
                    $this->discussion = reset($discussions);
                }
            }
        }
        return $this->discussion ?: null;
    }

    #[\Override]
    protected function supports_inline(): bool {
        $context = $this->cm->context;
        $discussion = $this->get_discussion();
        if (!$discussion || !has_capability('mod/forum:replypost', $context)) {
            return false;
        }
        return forum_user_can_see_discussion($this->get_forum(), $discussion, $context);
    }

    /**
     * Whether the current user can reply to the discussion now (dates, lock, groups, threshold).
     *
     * @param stdClass $discussion
     * @return bool
     */
    protected function can_reply(stdClass $discussion): bool {
        global $USER;
        $forum = $this->get_forum();
        $course = $this->cm->get_course();
        if (!forum_user_can_post($forum, $discussion, $USER, $this->cm, $course, $this->cm->context)) {
            return false;
        }
        $warning = forum_check_throttling($forum, $this->cm);
        return !$warning || !empty($warning->canpost);
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return $this->export_discussion($output, false);
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return $this->export_discussion($output, true);
    }

    /**
     * The discussion view, shared by the available and done states.
     *
     * @param renderer_base $output
     * @param bool $done whether the user has done the activity
     * @return array|null
     */
    protected function export_discussion(renderer_base $output, bool $done): ?array {
        $discussion = $this->get_discussion();
        if (!$discussion) {
            return null;
        }
        $first = $this->get_posts($discussion, true);
        if (!$first) {
            return null;
        }
        $replies = array_reverse($this->get_posts($discussion, false, self::MAX_REPLIES));
        $replycount = $this->count_replies($discussion);
        $forum = $this->get_forum();
        $canreply = $this->can_reply($discussion);

        $closed = '';
        if (!$canreply) {
            if (forum_discussion_is_locked($forum, $discussion)) {
                $closed = get_string('discussionlocked', 'forum');
            } else if (forum_is_cutoff_date_reached($forum)) {
                $closed = get_string('cutoffdatereached', 'forum');
            } else {
                $closed = get_string('forum_cannotreply', 'format_spiffy');
            }
        }

        $exported = array_map(fn(stdClass $post) => $this->export_post($post, $output, false), $replies);
        return ['template' => 'format_spiffy/inline/forum', 'context' => [
            'done' => $done,
            'first' => $this->export_post(reset($first), $output, $forum->type !== 'single'),
            'replies' => $exported,
            'hasreplies' => !empty($exported),
            'replycount' => $replycount,
            'hasmore' => $replycount > count($exported),
            'discussionurl' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussion->id]))->out(false),
            'canreply' => $canreply,
            'closed' => $closed,
            'maxlength' => self::MAX_LENGTH,
        ]];
    }

    /**
     * SQL restricting posts to those the current user may see: not deleted, and private replies
     * only to their author and recipient unless the user may read all of them.
     *
     * @param stdClass $discussion
     * @return array [where, params]
     */
    protected function visible_posts_sql(stdClass $discussion): array {
        global $USER;
        $where = 'p.discussion = :discussion AND p.deleted = 0';
        $params = ['discussion' => $discussion->id, 'firstpost' => $discussion->firstpost];
        if (!has_capability('mod/forum:readprivatereplies', $this->cm->context)) {
            $where .= ' AND (p.privatereplyto = 0 OR p.privatereplyto = :me1 OR p.userid = :me2)';
            $params['me1'] = $USER->id;
            $params['me2'] = $USER->id;
        }
        return [$where, $params];
    }

    /**
     * The opening post, or the latest replies, with their authors.
     *
     * @param stdClass $discussion
     * @param bool $first true for the opening post, false for the replies
     * @param int $limit how many replies, newest first
     * @return stdClass[]
     */
    protected function get_posts(stdClass $discussion, bool $first, int $limit = 0): array {
        global $DB;
        [$where, $params] = $this->visible_posts_sql($discussion);
        $where .= $first ? ' AND p.id = :firstpost' : ' AND p.id <> :firstpost';
        $userfields = \core_user\fields::for_userpic()->get_sql('u', false, 'u_', 'u_id', false)->selects;
        $sql = "SELECT p.id, p.userid, p.created, p.subject, p.message, p.messageformat, p.messagetrust,
                       p.privatereplyto, $userfields
                  FROM {forum_posts} p
                  JOIN {user} u ON u.id = p.userid
                 WHERE $where
              ORDER BY p.created DESC, p.id DESC";
        return array_values($DB->get_records_sql($sql, $params, 0, $first ? 1 : $limit));
    }

    /**
     * How many replies the current user can see.
     *
     * @param stdClass $discussion
     * @return int
     */
    protected function count_replies(stdClass $discussion): int {
        global $DB;
        [$where, $params] = $this->visible_posts_sql($discussion);
        return $DB->count_records_sql("SELECT COUNT(1) FROM {forum_posts} p WHERE $where AND p.id <> :firstpost", $params);
    }

    /**
     * Export one post for the template.
     *
     * @param stdClass $post a row of get_posts()
     * @param renderer_base $output
     * @param bool $showsubject whether to show the subject (only the opening post of a standard forum)
     * @return array
     */
    protected function export_post(stdClass $post, renderer_base $output, bool $showsubject): array {
        $context = $this->cm->context;
        $user = \core\output\user_picture::unalias($post, null, 'u_id', 'u_');
        $message = file_rewrite_pluginfile_urls($post->message, 'pluginfile.php', $context->id, 'mod_forum', 'post', $post->id);
        return [
            'id' => (int)$post->id,
            'author' => fullname($user, has_capability('moodle/site:viewfullnames', $context)),
            'picture' => $output->user_picture($user, ['size' => 35, 'courseid' => $this->cm->course, 'link' => false,
                'alttext' => false]),
            'time' => userdate($post->created, get_string('strftimedatetimeshort', 'langconfig')),
            'isotime' => date('c', (int)$post->created),
            'subject' => format_string($post->subject, true, ['context' => $context]),
            'showsubject' => $showsubject,
            'message' => format_text($message, $post->messageformat, ['context' => $context, 'trusted' => $post->messagetrust]),
            'private' => !empty($post->privatereplyto),
        ];
    }

    #[\Override]
    public function get_badges(): array {
        $badges = parent::get_badges();
        if (!$this->cm->uservisible || !$this->cm->available || !isloggedin() || isguestuser()) {
            return $badges;
        }
        $forum = $this->get_forum();
        if (forum_tp_can_track_forums($forum) && forum_tp_is_tracked($forum)) {
            $unread = (int)forum_tp_count_forum_unread_posts($this->cm, $this->cm->get_course());
            if ($unread > 0) {
                $badges[] = ['text' => get_string('forum_badgeunread', 'format_spiffy', $unread), 'type' => 'info'];
            }
        }
        return $badges;
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        global $CFG, $USER;
        if ($action !== 'reply') {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $discussion = $this->get_discussion();
        if (!$discussion || !$this->supports_inline()) {
            throw new \moodle_exception('nopostforum', 'forum');
        }
        $message = trim(clean_param((string)($data['message'] ?? ''), PARAM_RAW));
        if ($message === '') {
            throw new \moodle_exception('forum_emptyreply', 'format_spiffy');
        }
        if (\core_text::strlen($message) > self::MAX_LENGTH) {
            throw new \moodle_exception('forum_toolong', 'format_spiffy', '', self::MAX_LENGTH);
        }

        require_once($CFG->dirroot . '/mod/forum/externallib.php');
        $first = $this->get_posts($discussion, true);
        $parent = $first ? reset($first) : null;
        if (!$parent) {
            throw new \moodle_exception('invalidparentpostid', 'forum');
        }
        // A reply keeps the subject of the post it answers, as core's reply form does since the
        // mod_forum "re" string was deprecated (mod_forum/classes/local/exporters/post.php "replysubject").
        $subject = $parent->subject;
        // Core's checks apply: capability, groups, lock, cut-off date and posting threshold.
        \mod_forum_external::add_discussion_post(
            $parent->id,
            \core_text::substr($subject, 0, 255),
            $message,
            [],
            FORMAT_MOODLE
        );

        // Replying means the user has read the discussion.
        $forum = $this->get_forum();
        if (forum_tp_can_track_forums($forum) && forum_tp_is_tracked($forum)) {
            forum_tp_mark_discussion_read($USER, $discussion->id);
        }
        return get_string('forum_replysent', 'format_spiffy');
    }
}
