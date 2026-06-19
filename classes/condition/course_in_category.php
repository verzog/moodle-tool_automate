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
 * Condition: the course belongs to the configured category. When the
 * "include subcategories" option is on, descendants count too.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_in_category extends condition_base {
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
        return get_string('cond_course_in_category', 'tool_automate');
    }

    /**
     * Is the course in the configured category (optionally including
     * descendants)?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        global $DB;
        $categoryid = (int) ($this->config['categoryid'] ?? 0);
        if (!$categoryid) {
            return false;
        }
        if ((int) $subject->category === $categoryid) {
            return true;
        }
        if (empty($this->config['includesub'])) {
            return false;
        }
        $parent = $DB->get_record('course_categories', ['id' => $categoryid], 'id, path');
        if (!$parent) {
            return false;
        }
        $coursecat = $DB->get_record('course_categories', ['id' => (int) $subject->category], 'id, path');
        if (!$coursecat) {
            return false;
        }
        return strpos($coursecat->path . '/', $parent->path . '/') === 0;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $categories = $DB->get_records_menu('course_categories', null, 'name', 'id, name');
        $mform->addElement(
            'select',
            'config_categoryid',
            get_string('category', 'tool_automate'),
            $categories
        );
        $mform->addElement(
            'advcheckbox',
            'config_includesub',
            get_string('includesubcategories', 'tool_automate')
        );
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'categoryid' => (int) ($formdata->config_categoryid ?? 0),
            'includesub' => !empty($formdata->config_includesub) ? 1 : 0,
        ];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_categoryid' => (int) ($config['categoryid'] ?? 0),
            'config_includesub' => !empty($config['includesub']) ? 1 : 0,
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        global $DB;
        $name = $DB->get_field('course_categories', 'name', ['id' => (int) ($config['categoryid'] ?? 0)]);
        $key = !empty($config['includesub'])
            ? 'cond_course_in_category_desc_inc'
            : 'cond_course_in_category_desc';
        return get_string($key, 'tool_automate', s($name ?: '?'));
    }
}
