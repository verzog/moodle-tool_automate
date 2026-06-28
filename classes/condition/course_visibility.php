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
 * Condition: the course's visibility setting matches the configured value.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_visibility extends condition_base {
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
        return get_string('cond_course_visibility', 'tool_automate');
    }

    /**
     * Does the course's visibility match?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $want = (int) ($this->config['visible'] ?? 1);
        return (int) ($subject->visible ?? 1) === $want;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $options = [
            1 => get_string('cond_course_visibility_shown', 'tool_automate'),
            0 => get_string('cond_course_visibility_hidden', 'tool_automate'),
        ];
        $mform->addElement('select', 'config_visible', get_string('visible', 'tool_automate'), $options);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['visible' => (int) ($formdata->config_visible ?? 1)];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_visible' => (int) ($config['visible'] ?? 1)];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $key = ((int) ($config['visible'] ?? 1)) === 1
            ? 'cond_course_visibility_desc_shown'
            : 'cond_course_visibility_desc_hidden';
        return get_string($key, 'tool_automate');
    }

    /**
     * SQL pre-filter on the {course} table.
     *
     * @param array $config
     * @return array
     */
    public static function get_course_sql_filter(array $config): array {
        static $n = 0;
        $want = (int) ($config['visible'] ?? 1);
        // Unique placeholder per call so two visibility conditions on one
        // rule don't collide on a shared :cvis_want.
        $param = 'cvis_want_' . (++$n);
        return ['c.visible = :' . $param, [$param => $want]];
    }
}
