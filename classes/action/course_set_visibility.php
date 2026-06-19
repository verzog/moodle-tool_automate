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
 * Action: show or hide a course.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_set_visibility extends action_base {
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
        return get_string('act_course_set_visibility', 'tool_automate');
    }

    /**
     * Show or hide the course.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $target = (int) ($this->config['visible'] ?? 1);
        if ((int) $subject->visible === $target) {
            return get_string(
                $target ? 'coursealreadyshown' : 'coursealreadyhidden',
                'tool_automate',
                format_string($subject->fullname)
            );
        }
        if ($dryrun) {
            return get_string(
                $target ? 'coursewouldshow' : 'coursewouldhide',
                'tool_automate',
                format_string($subject->fullname)
            );
        }
        if ($target) {
            course_change_visibility((int) $subject->id, true);
        } else {
            course_change_visibility((int) $subject->id, false);
        }
        return get_string(
            $target ? 'courseshown' : 'coursehidden',
            'tool_automate',
            format_string($subject->fullname)
        );
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
            ? 'act_course_set_visibility_desc_show'
            : 'act_course_set_visibility_desc_hide';
        return get_string($key, 'tool_automate');
    }
}
