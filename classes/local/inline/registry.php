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
use stdClass;

/**
 * Which activity types have an inline handler.
 *
 * @package    format_spiffy
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /** @var string[] Core modules with a handler; the class is format_spiffy\local\inline\mod_<name>. */
    private const MODULES = [
        'assign', 'book', 'choice', 'data', 'feedback', 'forum', 'glossary', 'lesson', 'page', 'quiz', 'resource', 'url',
    ];

    /**
     * The handler class of a module, if it has one.
     *
     * @param string $modname
     * @return string|null class name
     */
    public static function get_class(string $modname): ?string {
        if (!in_array($modname, self::MODULES, true)) {
            return null;
        }
        $class = __NAMESPACE__ . '\\mod_' . $modname;
        return class_exists($class) && is_subclass_of($class, handler::class) ? $class : null;
    }

    /**
     * The handler of an activity, if its type has one.
     *
     * @param cm_info $cm from the current user's modinfo
     * @param stdClass $settings the activity's Spiffy settings
     * @return handler|null
     */
    public static function get_handler(cm_info $cm, stdClass $settings): ?handler {
        $class = self::get_class($cm->modname);
        return $class ? new $class($cm, $settings) : null;
    }
}
