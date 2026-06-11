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
 * Action: add the user to a course group.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_to_group extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_add_to_group', 'tool_automate');
    }

    /**
     * Add.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/group/lib.php');
        $groupid = (int) ($this->config['groupid'] ?? 0);
        $group = $DB->get_record('groups', ['id' => $groupid]);
        if (!$group) {
            return get_string('groupgone', 'tool_automate');
        }
        $groupname = format_string($group->name);
        if (groups_is_member($groupid, $user->id)) {
            return get_string('groupalready', 'tool_automate', $groupname);
        }
        if (!is_enrolled(\context_course::instance((int) $group->courseid), $user)) {
            return get_string('groupnotenrolled', 'tool_automate', $groupname);
        }
        if ($dryrun) {
            return get_string('groupwouldadd', 'tool_automate', $groupname);
        }
        groups_add_member($groupid, $user->id);
        return get_string('groupadded', 'tool_automate', $groupname);
    }

    /**
     * Form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $sql = "SELECT g.id, " . $DB->sql_concat('c.fullname', "' / '", 'g.name') . " AS label
                  FROM {groups} g
                  JOIN {course} c ON c.id = g.courseid
              ORDER BY c.fullname, g.name";
        $groups = $DB->get_records_sql_menu($sql, [], 0, 500);
        if (empty($groups)) {
            $mform->addElement('static', 'config_nogroups', '',
                get_string('nogroups', 'tool_automate'));
            return;
        }
        $mform->addElement('select', 'config_groupid', get_string('group', 'tool_automate'), $groups);
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['groupid' => (int) ($formdata->config_groupid ?? 0)];
    }

    /**
     * Defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_groupid' => (int) ($config['groupid'] ?? 0)];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        global $DB;
        $group = $DB->get_record('groups', ['id' => (int) ($config['groupid'] ?? 0)]);
        $coursename = $group ? $DB->get_field('course', 'fullname', ['id' => $group->courseid]) : '';
        $label = $group ? "$coursename / $group->name" : '?';
        return get_string('act_add_to_group_desc', 'tool_automate', s($label));
    }
}
