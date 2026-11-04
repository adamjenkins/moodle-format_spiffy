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
 * Book on a card: a short book is read inside the card, when the teacher sets it to inline.
 *
 * A book opens its own page by default. Set to inline, a short book (all visible chapters
 * together no longer than a short page) shows its chapter list and its chapters behind a "Read
 * more" expander that starts with the first chapter. "Mark as read" records the views through
 * mod_book's own external function: the book, then each visible chapter in order, as a reader
 * paging through it would, so viewing the last chapter completes a book that completes on view.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_book extends handler {
    /** @var int Most visible chapters a book read inside the card may have. */
    public const MAX_CHAPTERS = 10;

    /** @var \stdClass|null The book record, loaded once. */
    protected ?\stdClass $book = null;

    /** @var \stdClass[]|null The visible chapters in order, loaded once. */
    protected ?array $chapters = null;

    #[\Override]
    public static function default_inline(): bool {
        return false;
    }

    /**
     * The book record.
     *
     * @return \stdClass
     */
    protected function get_book(): \stdClass {
        global $DB;
        if ($this->book === null) {
            $this->book = $DB->get_record('book', ['id' => $this->cm->instance], '*', MUST_EXIST);
        }
        return $this->book;
    }

    /**
     * The chapters a student sees, in order: hidden chapters (and the subchapters of hidden
     * chapters) are left out.
     *
     * @return \stdClass[]
     */
    protected function get_chapters(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        if ($this->chapters === null) {
            $this->chapters = array_values(array_filter(
                book_preload_chapters($this->get_book()),
                fn($chapter) => empty($chapter->hidden)
            ));
        }
        return $this->chapters;
    }

    #[\Override]
    protected function supports_inline(): bool {
        if (!has_capability('mod/book:read', $this->cm->context)) {
            return false;
        }
        $chapters = $this->get_chapters();
        if (!$chapters || count($chapters) > self::MAX_CHAPTERS) {
            return false;
        }
        $length = 0;
        foreach ($chapters as $chapter) {
            $length += mod_page::plain_length((string)$chapter->title) + mod_page::plain_length((string)$chapter->content);
        }
        return $length <= mod_page::MAX_LENGTH;
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return ['template' => 'format_spiffy/inline/book', 'context' => $this->export_content(false)];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/book', 'context' => $this->export_content(true)];
    }

    /**
     * The template context for the chapter list and the chapters.
     *
     * @param bool $done whether the user has done the book
     * @return array
     */
    protected function export_content(bool $done): array {
        $book = $this->get_book();
        $context = $this->cm->context;
        $chapters = [];
        foreach ($this->get_chapters() as $chapter) {
            // As mod/book/view.php.
            $text = file_rewrite_pluginfile_urls(
                $chapter->content,
                'pluginfile.php',
                $context->id,
                'mod_book',
                'chapter',
                $chapter->id
            );
            $chapters[] = [
                'id' => (int)$chapter->id,
                'title' => format_string($chapter->title, true, ['context' => $context]),
                'sub' => !empty($chapter->subchapter),
                'content' => format_text($text, $chapter->contentformat, ['noclean' => true, 'overflowdiv' => true,
                    'context' => $context]),
            ];
        }
        $first = $this->get_chapters()[0];
        return [
            'name' => format_string($book->name, true, ['context' => $context]),
            'chapters' => $chapters,
            'showtoc' => count($chapters) > 1,
            'excerpt' => mod_page::excerpt((string)$first->content),
            'canmark' => !$done && mod_page::tracks_views($this->cm),
            'markread' => true,
            'done' => $done,
        ];
    }

    #[\Override]
    public function process_action(string $action, array $data): ?string {
        if ($action !== 'view') {
            throw new \moodle_exception('invalidaction', 'format_spiffy', '', $action);
        }
        $bookid = (int)$this->cm->instance;
        // The book was opened (which also views its first chapter), then each further chapter
        // read in order; the last one completes the book.
        \mod_book_external::view_book($bookid, 0);
        foreach (array_slice(array_values($this->get_chapters()), 1) as $chapter) {
            \mod_book_external::view_book($bookid, (int)$chapter->id);
        }
        return get_string('markedasread', 'format_spiffy');
    }
}
