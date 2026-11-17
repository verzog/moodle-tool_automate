<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace tool_automate\action;

/**
 * Action: import a slide deck from a watch folder into a chosen, existing course.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_import_to_course extends file_import_base {
    /** Capability the rule's author needs in the destination course. */
    public const CAPABILITY = 'moodle/course:manageactivities';

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_file_import_to_course', 'tool_automate');
    }

    /**
     * The configured course, if it still exists and the author may still add activities to it.
     *
     * @param bool $dryrun
     * @return array
     */
    protected function resolve_destination(bool $dryrun): array {
        global $DB;
        unset($dryrun); // Nothing is created either way.
        $courseid = (int) ($this->config['courseid'] ?? 0);
        $course = $courseid ? $DB->get_record('course', ['id' => $courseid]) : false;
        if (!$course || (int) $course->id === (int) SITEID) {
            return ['error' => get_string('coursegone', 'tool_automate'), 'courseid' => 0, 'label' => ''];
        }
        $author = $this->author();
        if ($author === null || !has_capability(self::CAPABILITY, \context_course::instance($course->id), $author)) {
            return ['error' => get_string('fileimport_notallowed', 'tool_automate'), 'courseid' => 0, 'label' => ''];
        }
        return ['error' => '', 'courseid' => (int) $course->id, 'label' => format_string($course->fullname)];
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $courses = $DB->get_records_menu('course', null, 'fullname', 'id, fullname', 0, 500);
        unset($courses[SITEID]);
        $mform->addElement('select', 'config_courseid', get_string('course', 'tool_automate'), $courses);
        self::add_common_form_elements($mform);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['courseid' => (int) ($formdata->config_courseid ?? 0)] + self::extract_common_config($formdata);
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_courseid' => (int) ($config['courseid'] ?? 0)] + self::common_form_defaults($config);
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        global $DB;
        $name = $DB->get_field('course', 'fullname', ['id' => (int) ($config['courseid'] ?? 0)]);
        return get_string('act_file_import_to_course_desc', 'tool_automate', (object) [
            'type' => self::type_label($config),
            'course' => $name === false ? '?' : format_string($name),
        ]);
    }
}
