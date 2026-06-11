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
 * The engine. Holds the registry of available condition/action types and
 * runs a rule: find the target users, check the conditions, run the actions,
 * and log everything.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Registry of condition types. To add a condition, add one line here.
     *
     * @return array type => fully qualified class name
     */
    public static function get_condition_types(): array {
        return [
            'email_matches' => \tool_automate\condition\email_matches::class,
        ];
    }

    /**
     * Registry of action types. To add an action, add one line here.
     *
     * @return array type => fully qualified class name
     */
    public static function get_action_types(): array {
        return [
            'add_to_cohort' => \tool_automate\action\add_to_cohort::class,
        ];
    }

    /**
     * Run a rule. Returns a list of result rows for display/logging.
     *
     * @param int $ruleid
     * @param bool $dryrun If true, make no changes.
     * @param int|null $onlyuserid Restrict to a single user (used by event triggers).
     * @return array Array of objects: {userid, fullname, outcome, message}
     */
    public static function run_rule(int $ruleid, bool $dryrun, ?int $onlyuserid = null): array {
        global $DB;

        $rule = $DB->get_record('tool_automate_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $conditions = self::load_conditions($ruleid);
        $actions = self::load_actions($ruleid);
        $users = self::get_target_users($onlyuserid);

        $results = [];
        foreach ($users as $user) {
            // A user must satisfy every condition (logical AND).
            $matched = true;
            foreach ($conditions as $condition) {
                if (!$condition->matches($user)) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            // Run each action and record the outcome.
            foreach ($actions as $action) {
                try {
                    $message = $action->execute($user, $dryrun);
                    $outcome = 'actioned';
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $outcome = 'error';
                }
                self::log($ruleid, $user->id, $dryrun, $outcome, $message);
                $results[] = (object) [
                    'userid'   => $user->id,
                    'fullname' => fullname($user),
                    'outcome'  => $outcome,
                    'message'  => $message,
                ];
            }
        }
        return $results;
    }

    /**
     * Instantiate the condition objects attached to a rule.
     *
     * @param int $ruleid
     * @return condition\condition_base[]
     */
    protected static function load_conditions(int $ruleid): array {
        global $DB;
        $types = self::get_condition_types();
        $objects = [];
        $records = $DB->get_records('tool_automate_condition', ['ruleid' => $ruleid], 'sortorder');
        foreach ($records as $record) {
            if (isset($types[$record->type])) {
                $class = $types[$record->type];
                $config = (array) json_decode($record->configdata ?? '{}', true);
                $objects[] = new $class($config);
            }
        }
        return $objects;
    }

    /**
     * Instantiate the action objects attached to a rule.
     *
     * @param int $ruleid
     * @return action\action_base[]
     */
    protected static function load_actions(int $ruleid): array {
        global $DB;
        $types = self::get_action_types();
        $objects = [];
        $records = $DB->get_records('tool_automate_action', ['ruleid' => $ruleid], 'sortorder');
        foreach ($records as $record) {
            if (isset($types[$record->type])) {
                $class = $types[$record->type];
                $config = (array) json_decode($record->configdata ?? '{}', true);
                $objects[] = new $class($config);
            }
        }
        return $objects;
    }

    /**
     * The set of users a rule is evaluated against.
     *
     * @param int|null $onlyuserid
     * @return \stdClass[]
     */
    protected static function get_target_users(?int $onlyuserid): array {
        global $DB, $CFG;
        if ($onlyuserid) {
            $user = $DB->get_record('user', ['id' => $onlyuserid, 'deleted' => 0]);
            return $user ? [$user] : [];
        }
        // All real, active users. The guest account is excluded.
        $select = 'deleted = 0 AND suspended = 0 AND id <> :guestid';
        return $DB->get_records_select('user', $select, ['guestid' => $CFG->siteguest]);
    }

    /**
     * Write a log row.
     *
     * @param int $ruleid The rule that ran.
     * @param int|null $userid The user the rule acted on.
     * @param bool $dryrun Whether this was a preview run.
     * @param string $outcome Short outcome code ('actioned' or 'error').
     * @param string $message Human-readable detail of what happened.
     */
    protected static function log(int $ruleid, ?int $userid, bool $dryrun, string $outcome, string $message): void {
        global $DB;
        $DB->insert_record('tool_automate_log', (object) [
            'ruleid'      => $ruleid,
            'userid'      => $userid,
            'dryrun'      => $dryrun ? 1 : 0,
            'outcome'     => $outcome,
            'message'     => $message,
            'timecreated' => time(),
        ]);
    }
}
