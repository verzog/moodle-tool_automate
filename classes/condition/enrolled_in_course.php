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
 * Condition: the user is enrolled in a specific course, optionally with a
 * specific role.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrolled_in_course extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_enrolled_in_course', 'tool_automate');
    }

    /**
     * Match.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        global $DB;
        $courseid = (int) ($this->config['courseid'] ?? 0);
        $roleid = (int) ($this->config['roleid'] ?? 0);
        if (!$courseid || !$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $user)) {
            return false;
        }
        if ($roleid === 0) {
            return true;
        }
        // Direct check that this user has the role assigned in this course context.
        return $DB->record_exists('role_assignments', [
            'roleid'    => $roleid,
            'userid'    => $user->id,
            'contextid' => $context->id,
        ]);
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
        $roles = role_get_names(\context_system::instance(), ROLENAME_ALIAS, true);
        $roleoptions = [0 => get_string('anyrole', 'tool_automate')] + $roles;
        $mform->addElement('select', 'config_courseid', get_string('course', 'tool_automate'), $courses);
        $mform->addElement('select', 'config_roleid', get_string('role', 'tool_automate'), $roleoptions);
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'courseid' => (int) ($formdata->config_courseid ?? 0),
            'roleid'   => (int) ($formdata->config_roleid ?? 0),
        ];
    }

    /**
     * Defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_courseid' => (int) ($config['courseid'] ?? 0),
            'config_roleid'   => (int) ($config['roleid'] ?? 0),
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
        $coursename = $DB->get_field('course', 'fullname', ['id' => (int) ($config['courseid'] ?? 0)]) ?: '?';
        $roleid = (int) ($config['roleid'] ?? 0);
        if ($roleid === 0) {
            return get_string('cond_enrolled_in_course_desc_any', 'tool_automate', s($coursename));
        }
        $role = $DB->get_record('role', ['id' => $roleid]);
        $rolename = $role ? role_get_name($role) : '?';
        $a = (object) ['course' => s($coursename), 'role' => s($rolename)];
        return get_string('cond_enrolled_in_course_desc_role', 'tool_automate', $a);
    }
}
