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

namespace tool_automate;

/**
 * Event observers. When a watched event fires, run any enabled "event"
 * rules for the user involved.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Run matching rules against a user for the given event name.
     *
     * @param string $eventname Fully qualified event class.
     * @param int $userid The user the event acted on.
     * @param array $extra Extra filters: ['courseid' => int] / ['roleid' => int].
     */
    protected static function dispatch(string $eventname, int $userid, array $extra = []): void {
        global $DB;
        if (!$userid) {
            return;
        }
        $where = ['enabled' => 1, 'triggertype' => 'event', 'eventname' => $eventname];
        foreach (['courseid', 'roleid'] as $field) {
            if (array_key_exists($field, $extra)) {
                $where[$field] = (int) $extra[$field];
            }
        }
        $rules = $DB->get_records('tool_automate_rule', $where);
        foreach ($rules as $rule) {
            manager::run_rule((int) $rule->id, false, $userid);
        }
    }

    /**
     * Handle a new user being created.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        self::dispatch('\\core\\event\\user_created', (int) $event->objectid);
    }

    /**
     * Handle a user being updated.
     *
     * @param \core\event\user_updated $event
     */
    public static function user_updated(\core\event\user_updated $event): void {
        self::dispatch('\\core\\event\\user_updated', (int) $event->objectid);
    }

    /**
     * Handle a user logging in.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        self::dispatch('\\core\\event\\user_loggedin', (int) $event->objectid);
    }

    /**
     * Handle a user completing a course. Only rules pinned to that course fire.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        $userid = (int) ($event->relateduserid ?: $event->userid);
        self::dispatch('\\core\\event\\course_completed', $userid, ['courseid' => (int) $event->courseid]);
    }

    /**
     * Handle a role being assigned. Only rules pinned to that role fire.
     *
     * @param \core\event\role_assigned $event
     */
    public static function role_assigned(\core\event\role_assigned $event): void {
        $userid = (int) ($event->relateduserid ?: $event->userid);
        $roleid = (int) ($event->other['id'] ?? 0);
        self::dispatch('\\core\\event\\role_assigned', $userid, ['roleid' => $roleid]);
    }
}
