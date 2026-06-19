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
 * Action: move the course into the configured category.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_move_to_category extends action_base {
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
        return get_string('act_course_move_to_category', 'tool_automate');
    }

    /**
     * Move the course into the configured category.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $categoryid = (int) ($this->config['categoryid'] ?? 0);
        if (!$categoryid) {
            return get_string('categorygone', 'tool_automate');
        }
        $category = $DB->get_record('course_categories', ['id' => $categoryid]);
        if (!$category) {
            return get_string('categorygone', 'tool_automate');
        }
        if ((int) $subject->category === $categoryid) {
            return get_string('coursealreadyincategory', 'tool_automate', format_string($category->name));
        }
        if ($dryrun) {
            return get_string('coursewouldmove', 'tool_automate', (object) [
                'course'   => format_string($subject->fullname),
                'category' => format_string($category->name),
            ]);
        }
        move_courses([(int) $subject->id], $categoryid);
        return get_string('coursemoved', 'tool_automate', (object) [
            'course'   => format_string($subject->fullname),
            'category' => format_string($category->name),
        ]);
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
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['categoryid' => (int) ($formdata->config_categoryid ?? 0)];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_categoryid' => (int) ($config['categoryid'] ?? 0)];
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
        return get_string('act_course_move_to_category_desc', 'tool_automate', s($name ?: '?'));
    }
}
