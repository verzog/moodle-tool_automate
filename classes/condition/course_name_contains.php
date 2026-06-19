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

namespace tool_automate\condition;

/**
 * Condition: the course's fullname contains the configured substring
 * (case-insensitive). For wildcard matching use course_name_matches.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_name_contains extends condition_base {
    /**
     * Subject discriminator.
     *
     * @return string
     */
    public static function get_subject(): string {
        return 'course';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_course_name_contains', 'tool_automate');
    }

    /**
     * Does the course's fullname contain the configured substring?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $needle = trim((string) ($this->config['needle'] ?? ''));
        if ($needle === '') {
            return false;
        }
        $haystack = (string) ($subject->fullname ?? '');
        return stripos($haystack, $needle) !== false;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'text',
            'config_needle',
            get_string('substring', 'tool_automate'),
            ['size' => 40]
        );
        $mform->setType('config_needle', PARAM_TEXT);
        $mform->addRule('config_needle', null, 'required', null, 'client');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['needle' => trim((string) ($formdata->config_needle ?? ''))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_needle' => $config['needle'] ?? ''];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('cond_course_name_contains_desc', 'tool_automate', s($config['needle'] ?? ''));
    }

    /**
     * SQL pre-filter on the {course} table.
     *
     * @param array $config
     * @return array
     */
    public static function get_course_sql_filter(array $config): array {
        global $DB;
        $needle = trim((string) ($config['needle'] ?? ''));
        if ($needle === '') {
            return ['', []];
        }
        $like = $DB->sql_like('c.fullname', ':cnc_needle', false);
        return [$like, ['cnc_needle' => '%' . $DB->sql_like_escape($needle) . '%']];
    }
}
