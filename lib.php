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
 * Main class for the Spiffy course format.
 *
 * @package    format_spiffy
 * @copyright  2012 Marina Glancy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/course/format/lib.php');

use core\lang_string;
use core\output\inplace_editable;

/**
 * Main class for the Spiffy course format.
 *
 * Section 0 becomes the page introduction and every other section a step card. Cards sit in
 * columns or span the full width, chosen per section.
 *
 * @package    format_spiffy
 * @copyright  2012 Marina Glancy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class format_spiffy extends core_courseformat\base {
    /** @var string[] Accent colours an activity can pick; "auto" cycles through the first three. */
    public const ACCENTS = ['green', 'amber', 'coral', 'blue', 'purple', 'teal', 'grey'];

    /** @var string Activity width: steps sit in the column grid, text and media spans the row. */
    public const WIDTH_AUTO = 'auto';

    /** @var string Activity width: a card in the column grid. */
    public const WIDTH_COLUMN = 'column';

    /** @var string Activity width: spanning the whole row. */
    public const WIDTH_FULL = 'full';

    /** @var string Step numbers: only on steps that unlock or are unlocked by another step. */
    public const NUMBERS_AUTO = 'auto';

    /** @var string Step numbers: on every step. */
    public const NUMBERS_ALWAYS = 'always';

    /** @var string Step numbers: never. */
    public const NUMBERS_NEVER = 'never';

    /** @var string[] Card designs: soft (tinted, accent on top), outline, flat (accent stripe) and lifted. */
    public const CARD_STYLES = ['soft', 'outline', 'flat', 'lifted'];

    /** @var string[] Page widths: Moodle's standard course width, wide, or the full window. */
    public const PAGE_WIDTHS = ['standard', 'wide', 'full'];

    /** @var string[] Hint arrows point from the step to do first to the locked step, or back, or are not drawn. */
    public const ARROW_DIRECTIONS = ['forwards', 'backwards', 'none'];

    /** @var string[] The label above a section's name: word and number, number only, or none. */
    public const SECTION_LABELS = ['word', 'number', 'none'];

    /** @var int The window width, in pixels, from which the blocks drawer is kept open (setting blocksopen). */
    public const BLOCKS_OPEN_MINWIDTH = 1400;

    /**
     * @var string[] Features a course can switch off, each a yes/no course setting "feature<name>":
     * the spotlight view, activities on the card, "Mark as done", My notes, Ask my teacher, the
     * confidence check, the section celebration, What's next, unlock hints and the teacher tools.
     */
    public const FEATURES = [
        'spotlight', 'inline', 'markdone', 'notes', 'askteacher', 'confidence', 'celebrate', 'whatsnext', 'unlockhints',
        'teachertools',
    ];

    /** @var int Longest word before the section number. */
    public const SECTIONWORD_MAXLENGTH = 50;

    /** @var int Longest label of a section. */
    public const EYEBROW_MAXLENGTH = 100;

    /** @var string[] Course settings chosen from a list, with their choices. */
    protected const SELECT_OPTIONS = ['cardstyle', 'pagewidth', 'arrows', 'sectionlabel'];

    /**
     * The built-in defaults of the course settings (the site's defaults replace them,
     * format_spiffy/default_<name>).
     *
     * @return array setting name => value
     */
    public static function builtin_defaults(): array {
        $defaults = [
            'columns' => 3,
            'cardstyle' => 'soft',
            'pagewidth' => 'wide',
            'arrows' => 'forwards',
            'courseindex' => 1,
            'blocksopen' => 0,
            'generalopen' => 0,
            'sectionlabel' => 'word',
            'sectionword' => '',
        ];
        foreach (self::FEATURES as $feature) {
            $defaults['feature' . $feature] = 1;
        }
        return $defaults;
    }

    /**
     * The site's default for a course setting (Site administration > Plugins > Course formats >
     * Spiffy), or the built-in one.
     *
     * @param string $name
     * @return mixed
     */
    public static function site_default(string $name) {
        $builtin = self::builtin_defaults()[$name];
        $value = get_config('format_spiffy', 'default_' . $name);
        if ($value === false || $value === null) {
            return $builtin;
        }
        if (in_array($name, self::SELECT_OPTIONS, true)) {
            return in_array($value, self::display_choices()[$name], true) ? $value : $builtin;
        }
        return is_int($builtin) ? (int)$value : (string)$value;
    }

    /**
     * Whether one of the course's features is on (see FEATURES).
     *
     * @param string $feature
     * @return bool
     */
    public function feature_enabled(string $feature): bool {
        $name = 'feature' . $feature;
        $options = $this->get_courseid() ? $this->get_format_options() : [];
        return !empty($options[$name] ?? self::site_default($name));
    }

    #[\Override]
    public function uses_sections() {
        return true;
    }

    /**
     * Whether the course index drawer is shown: a course setting, on by default.
     *
     * @return bool
     */
    #[\Override]
    public function uses_course_index() {
        return !$this->get_courseid() || !empty($this->get_format_options()['courseindex'] ?? 1);
    }

    #[\Override]
    public function uses_indentation(): bool {
        return false;
    }

    #[\Override]
    public static function uses_linear_navigation(): bool {
        return true;
    }

    #[\Override]
    public function get_section_name($section) {
        $section = $this->get_section($section);
        if ((string)$section->name !== '') {
            return format_string($section->name, true, ['context' => context_course::instance($this->courseid)]);
        }
        return $this->get_default_section_name($section);
    }

    #[\Override]
    public function get_default_section_name($section) {
        $section = $this->get_section($section);
        if ($section->sectionnum == 0) {
            return get_string('section0name', 'format_spiffy');
        }
        return get_string('newsection', 'format_spiffy');
    }

    #[\Override]
    public function page_title(): string {
        return get_string('sectionoutline');
    }

    #[\Override]
    public function get_view_url($section, $options = []) {
        $course = $this->get_course();
        $section = (is_null($section) || $section instanceof section_info) ?
            $section
            : $this->get_section($section, IGNORE_MISSING);

        if (array_key_exists('pagesectionid', $options)) {
            $pagesectionid = $options['pagesectionid'] ?? null;
            $pagesection = $pagesectionid ? $this->get_modinfo()->get_section_info_by_id($pagesectionid, IGNORE_MISSING) : null;
        } else if (array_key_exists('sr', $options)) {
            // TODO: Remove with core's own 'sr' support in Moodle 7.0 (MDL-88498).
            $pagesection = !is_null($options['sr']) ? $this->get_section($options['sr'], IGNORE_MISSING) : null;
        } else if ($options['navigation'] ?? false) {
            $pagesection = ($section && $section->get_component_instance()) ?
                $section->get_component_instance()->get_parent_section()
                : $section;
        } else {
            $pagesection = null;
        }

        if (is_null($pagesection)) {
            $url = new moodle_url('/course/view.php', ['id' => $course->id]);
        } else {
            $url = new moodle_url('/course/section.php', ['id' => $pagesection->id]);
        }
        if ($section && ($section->id != $pagesection?->id)) {
            $url->set_anchor('section-' . $section->section);
        }
        return $url;
    }

    #[\Override]
    public function supports_ajax() {
        $ajaxsupport = new stdClass();
        $ajaxsupport->capable = true;
        return $ajaxsupport;
    }

    #[\Override]
    public function supports_components() {
        return true;
    }

    #[\Override]
    public function get_course_display(): int {
        // The card layout is a single page; sections still have their own page via course/section.php.
        return COURSE_DISPLAY_SINGLEPAGE;
    }

    #[\Override]
    public function get_default_blocks() {
        return [
            BLOCK_POS_LEFT => [],
            BLOCK_POS_RIGHT => [],
        ];
    }

    /**
     * Course options: hidden sections, the layout and display settings, and the features that can
     * be switched off. Defaults come from the site's settings (site_default()).
     *
     * @param bool $foreditform
     * @return array of options
     */
    #[\Override]
    public function course_format_options($foreditform = false) {
        $courseconfig = get_config('moodlecourse');
        $types = [
            'columns' => PARAM_INT,
            'cardstyle' => PARAM_ALPHA,
            'pagewidth' => PARAM_ALPHA,
            'arrows' => PARAM_ALPHA,
            'courseindex' => PARAM_BOOL,
            'blocksopen' => PARAM_BOOL,
            'generalopen' => PARAM_BOOL,
            'sectionlabel' => PARAM_ALPHA,
            'sectionword' => PARAM_TEXT,
        ];
        $courseformatoptions = ['hiddensections' => ['default' => $courseconfig->hiddensections, 'type' => PARAM_INT]];
        foreach (self::builtin_defaults() as $name => $unused) {
            $courseformatoptions[$name] = ['default' => self::site_default($name), 'type' => $types[$name] ?? PARAM_BOOL];
        }
        if ($foreditform) {
            $hiddensectionslist = new core\output\choicelist();
            $hiddensectionslist->set_allow_empty(false);
            $hiddensectionslist->add_option(
                1,
                new lang_string('hiddensectionsinvisible'),
                ['description' => new lang_string('hiddensectionsinvisible_description')]
            );
            $hiddensectionslist->add_option(
                0,
                new lang_string('hiddensectionscollapsed'),
                ['description' => new lang_string('hiddensectionscollapsed_description')]
            );

            $courseformatoptionsedit = [
                'hiddensections' => [
                    'label' => new lang_string('hiddensections'),
                    'element_type' => 'choicedropdown',
                    'element_attributes' => [$hiddensectionslist],
                ],
                'columns' => [
                    'label' => new lang_string('columns', 'format_spiffy'),
                    'element_type' => 'select',
                    'element_attributes' => [[1 => 1, 2 => 2, 3 => 3, 4 => 4]],
                    'help' => 'columns',
                    'help_component' => 'format_spiffy',
                ],
                'generalopen' => [
                    'label' => new lang_string('generalopen', 'format_spiffy'),
                    'element_type' => 'selectyesno',
                    'help' => 'generalopen',
                    'help_component' => 'format_spiffy',
                ],
                'sectionlabel' => [
                    'label' => new lang_string('sectionlabel', 'format_spiffy'),
                    'element_type' => 'select',
                    'element_attributes' => [self::option_labels('sectionlabel', self::SECTION_LABELS)],
                    'help' => 'sectionlabel',
                    'help_component' => 'format_spiffy',
                ],
                'sectionword' => [
                    'label' => new lang_string('sectionword', 'format_spiffy'),
                    'element_type' => 'text',
                    'element_attributes' => [['size' => 20, 'maxlength' => self::SECTIONWORD_MAXLENGTH]],
                    'help' => 'sectionword',
                    'help_component' => 'format_spiffy',
                ],
                'cardstyle' => [
                    'label' => new lang_string('cardstyle', 'format_spiffy'),
                    'element_type' => 'select',
                    'element_attributes' => [self::option_labels('cardstyle', self::CARD_STYLES)],
                    'help' => 'cardstyle',
                    'help_component' => 'format_spiffy',
                ],
                'pagewidth' => [
                    'label' => new lang_string('pagewidth', 'format_spiffy'),
                    'element_type' => 'select',
                    'element_attributes' => [self::option_labels('pagewidth', self::PAGE_WIDTHS)],
                    'help' => 'pagewidth',
                    'help_component' => 'format_spiffy',
                ],
                'courseindex' => [
                    'label' => new lang_string('courseindex', 'format_spiffy'),
                    'element_type' => 'selectyesno',
                    'help' => 'courseindex',
                    'help_component' => 'format_spiffy',
                ],
                'blocksopen' => [
                    'label' => new lang_string('blocksopen', 'format_spiffy'),
                    'element_type' => 'selectyesno',
                    'help' => 'blocksopen',
                    'help_component' => 'format_spiffy',
                ],
                'arrows' => [
                    'label' => new lang_string('arrows', 'format_spiffy'),
                    'element_type' => 'select',
                    'element_attributes' => [self::option_labels('arrows', self::ARROW_DIRECTIONS)],
                    'help' => 'arrows',
                    'help_component' => 'format_spiffy',
                ],
            ];
            foreach (self::FEATURES as $feature) {
                $courseformatoptionsedit['feature' . $feature] = [
                    'label' => new lang_string('feature' . $feature, 'format_spiffy'),
                    'element_type' => 'selectyesno',
                    'help' => 'feature' . $feature,
                    'help_component' => 'format_spiffy',
                ];
            }
            $courseformatoptions = array_merge_recursive($courseformatoptions, $courseformatoptionsedit);
        }
        return $courseformatoptions;
    }

    /**
     * Section options: a label above the section title and how steps are numbered.
     *
     * @param bool $foreditform
     * @return array of options
     */
    #[\Override]
    public function section_format_options($foreditform = false) {
        $options = [
            'eyebrow' => ['default' => '', 'type' => PARAM_TEXT],
            'numbers' => ['default' => self::NUMBERS_AUTO, 'type' => PARAM_ALPHA],
        ];
        if ($foreditform) {
            $options['eyebrow'] += [
                'label' => new lang_string('eyebrow', 'format_spiffy'),
                'element_type' => 'text',
                'element_attributes' => [['size' => 40, 'maxlength' => self::EYEBROW_MAXLENGTH]],
                'help' => 'eyebrow',
                'help_component' => 'format_spiffy',
            ];
            $options['numbers'] += [
                'label' => new lang_string('numbers', 'format_spiffy'),
                'element_type' => 'select',
                'element_attributes' => [[
                    self::NUMBERS_AUTO => new lang_string('numbers_auto', 'format_spiffy'),
                    self::NUMBERS_ALWAYS => new lang_string('numbers_always', 'format_spiffy'),
                    self::NUMBERS_NEVER => new lang_string('numbers_never', 'format_spiffy'),
                ]],
                'help' => 'numbers',
                'help_component' => 'format_spiffy',
            ];
        }
        return $options;
    }

    #[\Override]
    public function create_edit_form_elements(&$mform, $forsection = false) {
        global $COURSE;
        $elements = parent::create_edit_form_elements($mform, $forsection);

        if (!$forsection && (empty($COURSE->id) || $COURSE->id == SITEID)) {
            // Custom sections are always created with the default number of sections.
            $courseconfig = get_config('moodlecourse');
            $element = $mform->addElement('hidden', 'numsections');
            $mform->setType('numsections', PARAM_INT);
            $mform->setDefault('numsections', $courseconfig->numsections);
            array_unshift($elements, $element);
        }
        return $elements;
    }

    /**
     * Updates format options for a course, keeping options of the previous format that this one shares.
     *
     * @param stdClass|array $data return value from moodleform::get_data() or array with data
     * @param stdClass $oldcourse if called from update_course(), the course before the update
     * @return bool whether there were any changes to the options values
     */
    #[\Override]
    public function update_course_format_options($data, $oldcourse = null) {
        $data = (array)$data;
        if ($oldcourse !== null) {
            $oldcourse = (array)$oldcourse;
            foreach ($this->course_format_options() as $key => $unused) {
                if (!array_key_exists($key, $data) && array_key_exists($key, $oldcourse)) {
                    $data[$key] = $oldcourse[$key];
                }
            }
        }
        if (isset($data['columns'])) {
            $data['columns'] = min(4, max(1, (int)$data['columns']));
        }
        if (isset($data['sectionword'])) {
            $data['sectionword'] = core_text::substr((string)$data['sectionword'], 0, self::SECTIONWORD_MAXLENGTH);
        }
        $choices = self::display_choices();
        foreach ($choices as $key => $allowed) {
            if (isset($data[$key]) && !in_array($data[$key], $allowed, true)) {
                unset($data[$key]);
            }
        }
        return $this->update_format_options($data);
    }

    #[\Override]
    public function update_section_format_options($data) {
        $data = (array)$data;
        if (isset($data['eyebrow'])) {
            $data['eyebrow'] = core_text::substr((string)$data['eyebrow'], 0, self::EYEBROW_MAXLENGTH);
        }
        return parent::update_section_format_options($data);
    }

    /**
     * The allowed values of the course's display settings.
     *
     * @return string[][] keyed by setting name
     */
    public static function display_choices(): array {
        return [
            'cardstyle' => self::CARD_STYLES,
            'pagewidth' => self::PAGE_WIDTHS,
            'arrows' => self::ARROW_DIRECTIONS,
            'sectionlabel' => self::SECTION_LABELS,
        ];
    }

    /**
     * The automatic label above a section's name, from the course settings "Section label" and
     * "Word before the section number".
     *
     * @param int $number the section number
     * @return string HTML-safe text, empty for none
     */
    public function get_section_label(int $number): string {
        $mode = $this->get_display_option('sectionlabel');
        if ($mode === 'none') {
            return '';
        }
        if ($mode === 'number') {
            return (string)$number;
        }
        $word = trim((string)($this->get_format_options()['sectionword'] ?? ''));
        $word = $word === '' ? get_string('sectionname', 'format_spiffy')
            : format_string($word, true, ['context' => context_course::instance($this->get_courseid())]);
        return get_string('sectionlabelnumbered', 'format_spiffy', ['word' => $word, 'number' => $number]);
    }

    /**
     * The labels of a select setting's choices, keyed by value.
     *
     * @param string $setting the setting name, the prefix of its lang strings (cardstyle_soft, ...)
     * @param string[] $values
     * @return lang_string[]
     */
    public static function option_labels(string $setting, array $values): array {
        $labels = [];
        foreach ($values as $value) {
            $labels[$value] = new lang_string($setting . '_' . $value, 'format_spiffy');
        }
        return $labels;
    }

    /**
     * One of the course's display settings, falling back to its default for an unknown value.
     *
     * @param string $name cardstyle, pagewidth, arrows or sectionlabel
     * @return string
     */
    public function get_display_option(string $name): string {
        $allowed = self::display_choices()[$name];
        $value = (string)($this->get_format_options()[$name] ?? '');
        return in_array($value, $allowed, true) ? $value : $this->course_format_options()[$name]['default'];
    }

    /**
     * Mark the course page and its section pages with the course's page width, so the stylesheet
     * can widen the content column that course/view.php and course/section.php limit ("limitedwidth"),
     * and, if the course keeps the blocks drawer open, load the script that opens it.
     *
     * @param moodle_page $page
     * @return void
     */
    #[\Override]
    public function page_set_course(moodle_page $page) {
        parent::page_set_course($page);
        // Only the course page and its section pages: activity pages keep Moodle's width.
        $coursepage = str_starts_with($page->pagetype, 'course-view') || str_starts_with($page->pagetype, 'course-section');
        if ($coursepage && $this->get_courseid() && $page->state < moodle_page::STATE_PRINTING_HEADER) {
            $width = $this->get_display_option('pagewidth');
            $page->add_body_class('spiffy-width-' . $width);
            if ($width !== 'standard') {
                $page->add_body_class('spiffy-widened');
            }
            if (!empty($this->get_format_options()['blocksopen'])) {
                $page->requires->js_call_amd('format_spiffy/blocks', 'init', [self::BLOCKS_OPEN_MINWIDTH]);
            }
        }
    }

    #[\Override]
    public function can_delete_section($section) {
        return true;
    }

    #[\Override]
    public function supports_news() {
        return true;
    }

    #[\Override]
    public function allow_stealth_module_visibility($cm, $section) {
        // Allow the third visibility state inside visible sections or in section 0.
        return !$section->section || $section->visible;
    }

    #[\Override]
    public function section_action($section, $action, $sr) {
        global $PAGE;

        if ($section->section && ($action === 'setmarker' || $action === 'removemarker')) {
            require_capability('moodle/course:setcurrentsection', context_course::instance($this->courseid));
            if ($action === 'setmarker') {
                $sectioninfo = get_fast_modinfo($this->courseid)->get_section_info($section->section);
                \core_courseformat\formatactions::section($this->courseid)->set_marker($sectioninfo, true);
            } else {
                \core_courseformat\formatactions::section($this->courseid)->remove_all_markers();
            }
            return null;
        }

        // For show/hide actions call the parent method and return the new content for .section_availability element.
        $rv = parent::section_action($section, $action, $sr);
        $renderer = $PAGE->get_renderer('format_spiffy');
        if (!($section instanceof section_info)) {
            $section = course_modinfo::instance($this->courseid)->get_section_info($section->section);
        }
        $elementclass = $this->get_output_classname('content\\section\\availability');
        $availability = new $elementclass($this, $section);
        $rv['section_availability'] = $renderer->render($availability);
        return $rv;
    }

    #[\Override]
    public function get_config_for_external() {
        return $this->get_format_options();
    }

    #[\Override]
    public function get_required_jsfiles(): array {
        return [];
    }

    /**
     * Number of card columns, clamped to 1-4.
     *
     * @return int
     */
    public function get_columns(): int {
        return min(4, max(1, (int)($this->get_course()->columns ?? 3)));
    }
}

/**
 * Implements callback inplace_editable() allowing to edit values in-place.
 *
 * @param string $itemtype
 * @param int $itemid
 * @param mixed $newvalue
 * @return inplace_editable
 */
function format_spiffy_inplace_editable($itemtype, $itemid, $newvalue) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/lib.php');
    if ($itemtype === 'sectionname' || $itemtype === 'sectionnamenl') {
        $section = $DB->get_record_sql(
            'SELECT s.* FROM {course_sections} s JOIN {course} c ON s.course = c.id WHERE s.id = ? AND c.format = ?',
            [$itemid, 'spiffy'],
            MUST_EXIST
        );
        return course_get_format($section->course)->inplace_editable_update_section_name($section, $itemtype, $newvalue);
    }
}

/**
 * Add the Spiffy activity settings (button label, width, accent) to the activity form.
 *
 * @param moodleform_mod $formwrapper
 * @param MoodleQuickForm $mform
 * @return void
 */
function format_spiffy_coursemodule_standard_elements($formwrapper, $mform) {
    $course = $formwrapper->get_course();
    if (($course->format ?? '') !== 'spiffy') {
        return;
    }
    $current = $formwrapper->get_current();
    $settings = \format_spiffy\local\cm_settings::get((int)($current->coursemodule ?? 0));

    $mform->addElement('header', 'format_spiffy_header', get_string('activitysettings', 'format_spiffy'));
    // Open, so that the pencil menu's "Spiffy settings" link shows the settings it promises.
    $mform->setExpanded('format_spiffy_header');
    if (!plugin_supports('mod', $current->modulename, FEATURE_NO_VIEW_LINK, false)) {
        $mform->addElement('text', 'format_spiffy_buttonlabel', get_string('buttonlabel', 'format_spiffy'), ['size' => 40]);
        $mform->setType('format_spiffy_buttonlabel', PARAM_TEXT);
        $mform->addRule('format_spiffy_buttonlabel', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('format_spiffy_buttonlabel', 'buttonlabel', 'format_spiffy');
        $mform->setDefault('format_spiffy_buttonlabel', $settings->buttonlabel);
    }
    $mform->addElement('select', 'format_spiffy_width', get_string('width', 'format_spiffy'), [
        format_spiffy::WIDTH_AUTO => get_string('width_auto', 'format_spiffy'),
        format_spiffy::WIDTH_COLUMN => get_string('width_column', 'format_spiffy'),
        format_spiffy::WIDTH_FULL => get_string('width_full', 'format_spiffy'),
    ]);
    $mform->addHelpButton('format_spiffy_width', 'width', 'format_spiffy');
    $mform->setDefault('format_spiffy_width', $settings->width);
    $accents = ['auto' => get_string('accent_auto', 'format_spiffy')];
    foreach (format_spiffy::ACCENTS as $accent) {
        $accents[$accent] = get_string('accent_' . $accent, 'format_spiffy');
    }
    $mform->addElement('select', 'format_spiffy_accent', get_string('accent', 'format_spiffy'), $accents);
    $mform->setDefault('format_spiffy_accent', $settings->accent);

    $handlerclass = \format_spiffy\local\inline\registry::get_class($current->modulename);
    if ($handlerclass) {
        $default = $handlerclass::default_inline() ? 'inlinemode_inline' : 'inlinemode_page';
        $mform->addElement('select', 'format_spiffy_inlinemode', get_string('inlinemode', 'format_spiffy'), [
            \format_spiffy\local\cm_settings::INLINE_AUTO => get_string(
                'inlinemode_auto',
                'format_spiffy',
                get_string($default, 'format_spiffy')
            ),
            \format_spiffy\local\cm_settings::INLINE_INLINE => get_string('inlinemode_inline', 'format_spiffy'),
            \format_spiffy\local\cm_settings::INLINE_PAGE => get_string('inlinemode_page', 'format_spiffy'),
        ]);
        $mform->addHelpButton('format_spiffy_inlinemode', 'inlinemode', 'format_spiffy');
        $mform->setDefault('format_spiffy_inlinemode', $settings->inlinemode);
    }
}

/**
 * Save the Spiffy activity settings after the activity form is submitted.
 *
 * @param stdClass $data the submitted form data
 * @param stdClass $course
 * @return stdClass
 */
function format_spiffy_coursemodule_edit_post_actions($data, $course) {
    global $DB;
    if (($course->format ?? '') !== 'spiffy' || !isset($data->format_spiffy_width) || empty($data->coursemodule)) {
        return $data;
    }
    // The form's coursemodule field comes from the browser: it must be an activity of this course.
    if (!$DB->record_exists('course_modules', ['id' => (int)$data->coursemodule, 'course' => (int)$course->id])) {
        return $data;
    }
    \format_spiffy\local\cm_settings::save((int)$course->id, (int)$data->coursemodule, (object)[
        'buttonlabel' => $data->format_spiffy_buttonlabel ?? '',
        'width' => $data->format_spiffy_width,
        'accent' => $data->format_spiffy_accent ?? 'auto',
        'inlinemode' => $data->format_spiffy_inlinemode ?? \format_spiffy\local\cm_settings::INLINE_AUTO,
    ]);
    return $data;
}

/**
 * User preferences the Spiffy format lets the user set over web services.
 *
 * @return array[]
 */
function format_spiffy_user_preferences(): array {
    return [
        'format_spiffy_viewmode' => [
            'type' => PARAM_ALPHA,
            'null' => NULL_NOT_ALLOWED,
            'default' => 'spotlight',
            'choices' => ['spotlight', 'list'],
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
