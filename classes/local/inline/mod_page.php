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

use completion_info;
use renderer_base;

/**
 * Page on a card: a short page is read inside the card, with a "Read more" expander.
 *
 * The content is prepared as mod/page/view.php does. Long pages stay on their own page. When the
 * page completes on view, a "Mark as read" button at the end of the content records the view
 * through mod_page's own external function, so its events and completion apply unchanged.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_page extends handler {
    /** @var int Longest text, in characters, that is read inside the card. */
    public const MAX_LENGTH = 1500;

    /** @var int Longest text, in characters, shown in full without the "Read more" expander. */
    public const PREVIEW_LENGTH = 300;

    /** @var int Length of the teaser shown on the collapsed expander, in characters. */
    public const EXCERPT_LENGTH = 160;

    /** @var \stdClass|null The page record, loaded once. */
    protected ?\stdClass $page = null;

    /**
     * The page record.
     *
     * @return \stdClass
     */
    protected function get_page(): \stdClass {
        global $DB;
        if ($this->page === null) {
            $this->page = $DB->get_record('page', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->page;
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!has_capability('mod/page:view', $this->cm->context)) {
            return false;
        }
        return self::plain_length((string)$this->get_page()->content) <= self::MAX_LENGTH;
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return ['template' => 'format_spiffy/inline/page', 'context' => $this->export_content(false)];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/page', 'context' => $this->export_content(true)];
    }

    /**
     * The template context for the page content.
     *
     * @param bool $done whether the user has done the page
     * @return array
     */
    protected function export_content(bool $done): array {
        $page = $this->get_page();
        $context = $this->cm->context;
        // As mod/page/view.php.
        $content = file_rewrite_pluginfile_urls(
            $page->content,
            'pluginfile.php',
            $context->id,
            'mod_page',
            'content',
            $page->revision
        );
        $content = format_text($content, $page->contentformat, ['noclean' => true, 'overflowdiv' => true,
            'context' => $context]);
        return [
            'name' => format_string($page->name, true, ['context' => $context]),
            'content' => $content,
            'excerpt' => self::excerpt((string)$page->content),
            'collapsible' => self::plain_length((string)$page->content) > self::PREVIEW_LENGTH,
            'canmark' => !$done && self::tracks_views($this->cm),
            'markread' => true,
            'done' => $done,
        ];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        if ($action !== 'view') {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        \mod_page_external::view_page($this->cm->instance);
        return get_string('markedasread', 'format_spiffy');
    }

    /**
     * The plain text of some HTML: tags removed, entities decoded, white space collapsed.
     *
     * @param string $html
     * @return string
     */
    public static function plain_text(string $html): string {
        // Keep words in separate blocks apart.
        $html = preg_replace('~<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)\b[^>]*>~i', '$0 ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The length of the plain text of some HTML, in characters.
     *
     * @param string $html
     * @return int
     */
    public static function plain_length(string $html): int {
        return \core_text::strlen(self::plain_text($html));
    }

    /**
     * A short plain-text teaser of some HTML.
     *
     * @param string $html
     * @return string plain text, to be escaped by the template
     */
    public static function excerpt(string $html): string {
        return shorten_text(self::plain_text($html), self::EXCERPT_LENGTH);
    }

    /**
     * Whether an activity completes when viewed, so that "Mark as read" means something to the user.
     *
     * @param \cm_info $cm
     * @return bool
     */
    public static function tracks_views(\cm_info $cm): bool {
        global $USER;
        $completion = new completion_info($cm->get_course());
        return $completion->is_tracked_user($USER->id)
            && $completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC
            && !empty($cm->completionview);
    }
}
