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
 * Action: enrol the user in a course via the manual enrolment plugin.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_in_course extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_enrol_in_course', 'tool_automate');
    }

    /**
     * Enrol.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $DB;
        $courseid = (int) ($this->config['courseid'] ?? 0);
        $roleid = (int) ($this->config['roleid'] ?? 0);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            return get_string('coursegone', 'tool_automate');
        }
        $coursename = format_string($course->fullname);
        if (is_enrolled(\context_course::instance($courseid), $user, '', true)) {
            return get_string('enrolalready', 'tool_automate', $coursename);
        }
        if ($dryrun) {
            return get_string('enrolwould', 'tool_automate', $coursename);
        }
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        if (!$instance) {
            return get_string('manualenrolmissing', 'tool_automate', $coursename);
        }
        $plugin = enrol_get_plugin('manual');
        $plugin->enrol_user($instance, $user->id, $roleid ?: null);
        return get_string('enrolled', 'tool_automate', $coursename);
    }

    /**
     * Form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $courses = $DB->get_records_menu('course', null, 'fullname',
            'id, fullname', 0, 500);
        unset($courses[SITEID]);
        $roles = role_get_names(\context_system::instance(), ROLENAME_ALIAS, true);
        $roleoptions = [0 => get_string('defaultrole', 'tool_automate')];
        foreach ($roles as $r) {
            $roleoptions[$r->id] = $r->localname;
        }
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
        $name = $DB->get_field('course', 'fullname', ['id' => (int) ($config['courseid'] ?? 0)]);
        return get_string('act_enrol_in_course_desc', 'tool_automate', s($name ?: '?'));
    }
}
