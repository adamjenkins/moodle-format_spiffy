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

use moodle_url;
use renderer_base;
use stored_file;

/**
 * File on a card: a PDF preview or the image itself, with a download link.
 *
 * Only a file whose main file is a PDF or a web image is shown inside the card, and not when the
 * teacher set the file to be downloaded. The main file is found as mod/resource/view.php finds
 * it. "Mark as viewed" records the view through mod_resource's own external function.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_resource extends handler {
    /** @var string Preview kind: a PDF in an object element. */
    public const KIND_PDF = 'pdf';

    /** @var string Preview kind: an image. */
    public const KIND_IMAGE = 'image';

    /** @var \stdClass|null The resource record, loaded once. */
    protected ?\stdClass $resource = null;

    /** @var stored_file|null The main file, once looked up. */
    protected ?stored_file $file = null;

    /** @var bool Whether the main file was looked up. */
    protected bool $fileloaded = false;

    /**
     * The resource record.
     *
     * @return \stdClass
     */
    protected function get_resource(): \stdClass {
        global $DB;
        if ($this->resource === null) {
            $this->resource = $DB->get_record(
                'resource',
                ['id' => $this->cm->instance],
                'id, course, name, display, revision, tobemigrated',
                MUST_EXIST
            );
        }
        return $this->resource;
    }

    /**
     * The main file, as mod/resource/view.php picks it.
     *
     * @return stored_file|null
     */
    protected function get_main_file(): ?stored_file {
        if (!$this->fileloaded) {
            $files = get_file_storage()->get_area_files(
                $this->cm->context->id,
                'mod_resource',
                'content',
                0,
                'sortorder DESC, id ASC',
                false,
                0,
                0,
                1
            );
            $this->file = $files ? reset($files) : null;
            $this->fileloaded = true;
        }
        return $this->file;
    }

    /**
     * How the main file is shown inside the card.
     *
     * @param string $mimetype
     * @return string|null one of the KIND_ constants, or null when it is not shown inside the card
     */
    public static function preview_kind(string $mimetype): ?string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        if ($mimetype === 'application/pdf') {
            return self::KIND_PDF;
        }
        if (file_mimetype_in_typegroup($mimetype, 'web_image')) {
            return self::KIND_IMAGE;
        }
        return null;
    }

    #[\Override]
    protected function supports_inline(): bool {
        global $CFG;
        require_once($CFG->libdir . '/resourcelib.php');
        if (!has_capability('mod/resource:view', $this->cm->context)) {
            return false;
        }
        $resource = $this->get_resource();
        if (!empty($resource->tobemigrated) || (int)$resource->display === RESOURCELIB_DISPLAY_DOWNLOAD) {
            return false;
        }
        $file = $this->get_main_file();
        return $file && self::preview_kind($file->get_mimetype()) !== null;
    }

    #[\Override]
    protected function export_available(renderer_base $output): ?array {
        return ['template' => 'format_spiffy/inline/resource', 'context' => $this->export_content(false)];
    }

    #[\Override]
    protected function export_done(renderer_base $output): ?array {
        if (!$this->supports_inline()) {
            return null;
        }
        return ['template' => 'format_spiffy/inline/resource', 'context' => $this->export_content(true)];
    }

    /**
     * The template context for the preview.
     *
     * @param bool $done whether the user has done the file
     * @return array
     */
    protected function export_content(bool $done): array {
        $resource = $this->get_resource();
        $file = $this->get_main_file();
        $context = $this->cm->context;
        $kind = self::preview_kind($file->get_mimetype());
        // As mod/resource/view.php.
        $url = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_resource',
            'content',
            $resource->revision,
            $file->get_filepath(),
            $file->get_filename()
        );
        $download = moodle_url::make_pluginfile_url(
            $context->id,
            'mod_resource',
            'content',
            $resource->revision,
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
        $filename = $file->get_filename();
        return [
            // Plain text for the attributes; the template escapes it.
            'name' => strip_tags(format_string($resource->name, true, ['context' => $context, 'escape' => false])),
            'ispdf' => $kind === self::KIND_PDF,
            'isimage' => $kind === self::KIND_IMAGE,
            'url' => $url->out(false),
            'downloadurl' => $download->out(false),
            'downloadlabel' => get_string('downloadfile', 'format_spiffy', $filename),
            'size' => display_size($file->get_filesize()),
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
        \mod_resource_external::view_resource($this->cm->instance);
        return get_string('markedasviewed', 'format_spiffy');
    }
}
