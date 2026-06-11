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
 * Action: revoke a system-context role from the user. Only revokes
 * assignments this plugin created (component = 'tool_automate'), so it
 * won't silently undo assignments configured elsewhere.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_role extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_revoke_role', 'tool_automate');
    }

    /**
     * Revoke.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $DB;
        $roleid = (int) ($this->config['roleid'] ?? 0);
        if (!$roleid || !$DB->record_exists('role', ['id' => $roleid])) {
            return get_string('rolegone', 'tool_automate');
        }
        $rolename = role_get_name($DB->get_record('role', ['id' => $roleid]));
        $context = \context_system::instance();

        $params = [
            'roleid'    => $roleid,
            'userid'    => $user->id,
            'contextid' => $context->id,
            'component' => 'tool_automate',
        ];
        if (!$DB->record_exists('role_assignments', $params)) {
            return get_string('rolenotassigned', 'tool_automate', $rolename);
        }
        if ($dryrun) {
            return get_string('rolewouldrevoke', 'tool_automate', $rolename);
        }
        role_unassign($roleid, $user->id, $context->id, 'tool_automate');
        return get_string('rolerevoked', 'tool_automate', $rolename);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $context = \context_system::instance();
        $roles = role_get_names($context, ROLENAME_ALIAS);
        $options = [];
        foreach ($roles as $r) {
            $options[$r->id] = $r->localname;
        }
        $mform->addElement('select', 'config_roleid', get_string('role', 'tool_automate'), $options);
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['roleid' => (int) ($formdata->config_roleid ?? 0)];
    }

    /**
     * Defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_roleid' => (int) ($config['roleid'] ?? 0)];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        global $DB;
        $role = $DB->get_record('role', ['id' => (int) ($config['roleid'] ?? 0)]);
        $name = $role ? role_get_name($role) : '?';
        return get_string('act_revoke_role_desc', 'tool_automate', s($name));
    }
}
