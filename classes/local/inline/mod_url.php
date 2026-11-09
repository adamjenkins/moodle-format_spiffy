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

/**
 * URL on a card: a YouTube link plays inside the card.
 *
 * Only links to a YouTube video are shown inside the card, embedded from youtube-nocookie.com with
 * a strictly checked video id; other links open as before. The card shows a play button and the
 * player is loaded only when it is clicked (format_spiffy/inline). "Mark as viewed" records the
 * view through mod_url's own external function.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_url extends handler {
    /** @var string The embed address, followed by the video id. */
    public const EMBED_BASE = 'https://www.youtube-nocookie.com/embed/';

    /** @var \stdClass|null The url record, loaded once. */
    protected ?\stdClass $url = null;

    /**
     * The url record.
     *
     * @return \stdClass
     */
    protected function get_url(): \stdClass {
        global $DB;
        if ($this->url === null) {
            $this->url = $DB->get_record('url', ['id' => $this->cm->instance], 'id, course, name, externalurl', MUST_EXIST);
        }
        return $this->url;
    }

    /**
     * The YouTube video id of a link, if it is a link to one video.
     *
     * Accepts youtube.com/watch?v=ID, youtu.be/ID, youtube.com/shorts/ID and youtube.com/embed/ID
     * (also on www., m. and youtube-nocookie.com), over http or https.
     *
     * @param string $link
     * @return string|null the 11-character id, or null
     */
    public static function youtube_id(string $link): ?string {
        $parts = parse_url(trim(html_entity_decode($link, ENT_QUOTES, 'UTF-8')));
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || !empty($parts['user'])) {
            return null;
        }
        $host = preg_replace('/^(www|m)\./', '', strtolower($parts['host'] ?? ''));
        $path = $parts['path'] ?? '';
        $id = null;
        if ($host === 'youtu.be') {
            $id = substr($path, 1);
        } else if ($host === 'youtube.com' || $host === 'youtube-nocookie.com') {
            if ($path === '/watch' || $path === '/watch/') {
                parse_str($parts['query'] ?? '', $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } else if (preg_match('~^/(shorts|embed)/([^/]+)/?$~', $path, $matches)) {
                $id = $matches[2];
            }
        }
        if ($id === null || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }
        return $id;
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!has_capability('mod/url:view', $this->cm->context)) {
            return false;
        }
        return self::youtube_id((string)$this->get_url()->externalurl) !== null;
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return ['template' => 'format_spiffy/inline/url', 'context' => $this->export_content(false)];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/url', 'context' => $this->export_content(true)];
    }

    /**
     * The template context for the embedded video.
     *
     * @param bool $done whether the user has done the link
     * @return array
     */
    protected function export_content(bool $done): array {
        $url = $this->get_url();
        $context = $this->cm->context;
        return [
            // Plain text for the attributes; the template escapes it.
            'name' => strip_tags(format_string($url->name, true, ['context' => $context, 'escape' => false])),
            'embedurl' => self::EMBED_BASE . self::youtube_id((string)$url->externalurl),
            'canmark' => !$done && mod_page::tracks_views($this->cm),
            'markread' => false,
            'done' => $done,
        ];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        if ($action !== 'view') {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        \mod_url_external::view_url($this->cm->instance);
        return get_string('markedasviewed', 'format_spiffy');
    }
}
