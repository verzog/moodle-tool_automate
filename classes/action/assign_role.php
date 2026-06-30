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
 * Action: assign a system-context role to the user.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_role extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_assign_role', 'tool_automate');
    }

    /**
     * High risk: grants a role at system context.
     *
     * @return bool
     */
    public static function is_high_risk(): bool {
        return true;
    }

    /**
     * Assign.
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

        // Defence in depth for stored config. extract_config() gates roles
        // written through the current form, but a rule saved by the old
        // picker (which listed every role) or by direct DB tampering would
        // otherwise reach role_assign() unchecked. Re-validate at run time
        // that the rule's author may actually assign this role at system
        // context; anything outside their assignable set is skipped, never
        // granted.
        $authorid = (int) ($this->rule->usermodified ?? 0);
        if ($authorid > 0) {
            // A specific author is recorded: check against them, and fail
            // closed if that account no longer exists rather than falling
            // through to the (possibly more privileged) current user.
            $author = \core_user::get_user($authorid);
            if (!$author) {
                return get_string('rolenotassignable', 'tool_automate', $rolename);
            }
        } else {
            // No author recorded (e.g. a direct call outside the engine):
            // fall back to the current user.
            $author = null;
        }
        $assignable = get_assignable_roles($context, ROLENAME_ALIAS, false, $author);
        if (!isset($assignable[$roleid])) {
            return get_string('rolenotassignable', 'tool_automate', $rolename);
        }

        if (user_has_role_assignment($user->id, $roleid, $context->id)) {
            return get_string('rolealready', 'tool_automate', $rolename);
        }
        if ($dryrun) {
            return get_string('rolewouldassign', 'tool_automate', $rolename);
        }
        role_assign($roleid, $user->id, $context->id, 'tool_automate');
        return get_string('roleassigned', 'tool_automate', $rolename);
    }

    /**
     * Form fields.
     *
     * Only roles the configuring admin is actually allowed to assign at
     * system context are offered - never the full role list. Listing
     * every role (role_get_names) would let a tool/automate:manage holder
     * pick a role they cannot otherwise assign (e.g. Manager, or any role
     * carrying moodle/site:config) and have the rule grant it at system
     * context, escalating privilege past Moodle's role_allow_assign matrix.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $options = get_assignable_roles(\context_system::instance(), ROLENAME_ALIAS);
        $mform->addElement('select', 'config_roleid', get_string('role', 'tool_automate'), $options);
    }

    /**
     * Extract.
     *
     * Re-checks the submitted role against the assignable set server-side:
     * the form picker only constrains the browser, so a crafted POST could
     * otherwise smuggle in a role the admin may not assign. Anything not in
     * the assignable set is dropped to 0, which execute() treats as a
     * no-op ("role gone").
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        $roleid = (int) ($formdata->config_roleid ?? 0);
        $assignable = get_assignable_roles(\context_system::instance(), ROLENAME_ALIAS);
        if (!isset($assignable[$roleid])) {
            $roleid = 0;
        }
        return ['roleid' => $roleid];
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
        return get_string('act_assign_role_desc', 'tool_automate', s($name));
    }
}
