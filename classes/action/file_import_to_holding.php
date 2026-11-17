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

use tool_automate\watch_folder;

/**
 * Action: import a slide deck from a watch folder into the rule's temporary holding course.
 *
 * The holding course is created hidden, in a chosen category, the first time the rule has a
 * deck to import, and reused after that. Automate never deletes it.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_import_to_holding extends file_import_base {
    /** Capability the rule's author needs in the category to have the holding course created. */
    public const CAPABILITY = 'moodle/course:create';

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_file_import_to_holding', 'tool_automate');
    }

    /**
     * The rule's holding course, created now if it does not exist and this is not a dry run.
     *
     * @param bool $dryrun
     * @return array
     */
    protected function resolve_destination(bool $dryrun): array {
        global $DB;
        $fail = fn(string $key): array => ['error' => get_string($key, 'tool_automate'), 'courseid' => 0, 'label' => ''];
        if (empty($this->rule->id)) {
            return $fail('fileimport_unavailable');
        }
        $existing = watch_folder::find_holding_course((int) $this->rule->id);
        if ($existing !== null) {
            return ['error' => '', 'courseid' => (int) $existing->id, 'label' => format_string($existing->fullname)];
        }

        $categoryid = (int) ($this->config['categoryid'] ?? 0);
        $category = $categoryid ? $DB->get_record('course_categories', ['id' => $categoryid]) : false;
        if (!$category) {
            return $fail('categorygone');
        }
        $author = $this->author();
        if ($author === null || !has_capability(self::CAPABILITY, \context_coursecat::instance($category->id), $author)) {
            return $fail('fileimport_notallowed');
        }
        if ($dryrun) {
            $label = get_string('holdingcourse_new', 'tool_automate', format_string($category->name));
            return ['error' => '', 'courseid' => 0, 'label' => $label];
        }
        $course = watch_folder::create_holding_course($this->rule, (int) $category->id);
        return ['error' => '', 'courseid' => (int) $course->id, 'label' => format_string($course->fullname)];
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
            get_string('holdingcourse_category', 'tool_automate'),
            $categories
        );
        $mform->addHelpButton('config_categoryid', 'holdingcourse_category', 'tool_automate');
        self::add_common_form_elements($mform);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['categoryid' => (int) ($formdata->config_categoryid ?? 0)] + self::extract_common_config($formdata);
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_categoryid' => (int) ($config['categoryid'] ?? 0)] + self::common_form_defaults($config);
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
        return get_string('act_file_import_to_holding_desc', 'tool_automate', (object) [
            'type' => self::type_label($config),
            'category' => $name === false ? '?' : format_string($name),
        ]);
    }
}
