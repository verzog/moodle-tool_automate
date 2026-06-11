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
 * runs a rule: find target users, evaluate conditions per the rule's
 * logic, run actions, log everything.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Registry of condition types.
     *
     * @return array Type code => fully qualified class name.
     */
    public static function get_condition_types(): array {
        return [
            'email_matches'      => condition\email_matches::class,
            'inactive_for_days'  => condition\inactive_for_days::class,
            'profile_field'      => condition\profile_field::class,
            'auth_method'        => condition\auth_method::class,
            'cohort_membership'  => condition\cohort_membership::class,
        ];
    }

    /**
     * Registry of action types.
     *
     * @return array Type code => fully qualified class name.
     */
    public static function get_action_types(): array {
        return [
            'add_to_cohort'      => action\add_to_cohort::class,
            'remove_from_cohort' => action\remove_from_cohort::class,
            'suspend_user'       => action\suspend_user::class,
            'unsuspend_user'     => action\unsuspend_user::class,
            'assign_role'        => action\assign_role::class,
            'revoke_role'        => action\revoke_role::class,
        ];
    }

    /**
     * Run a rule.
     *
     * @param int $ruleid
     * @param bool $dryrun
     * @param int|null $onlyuserid Restrict to one user (used by event triggers).
     * @return array Result rows {userid, fullname, outcome, message}.
     */
    public static function run_rule(int $ruleid, bool $dryrun, ?int $onlyuserid = null): array {
        global $DB;

        $rule = $DB->get_record('tool_automate_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $conditions = self::load_conditions($ruleid);
        $actions = self::load_actions($ruleid);
        $users = self::get_target_users($ruleid, $onlyuserid);

        $results = [];
        foreach ($users as $user) {
            if (!self::evaluate_conditions($rule, $conditions, $user)) {
                continue;
            }
            if (empty($actions)) {
                continue;
            }
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
     * Evaluate a rule's conditions for one user.
     *
     * @param \stdClass $rule
     * @param array $conditions Output of self::load_conditions().
     * @param \stdClass $user
     * @return bool
     */
    protected static function evaluate_conditions(\stdClass $rule, array $conditions, \stdClass $user): bool {
        if (empty($conditions)) {
            return true;
        }
        $logic = $rule->logic ?? 'all';

        if ($logic === 'expression' && !empty($rule->expression)) {
            $values = [];
            foreach ($conditions as $i => $entry) {
                $key = 'c' . ($i + 1);
                $values[$key] = (bool) $entry['object']->matches($user);
            }
            try {
                return expression::evaluate($rule->expression, $values);
            } catch (\Throwable $e) {
                return false;
            }
        }

        if ($logic === 'any') {
            foreach ($conditions as $entry) {
                if ($entry['object']->matches($user)) {
                    return true;
                }
            }
            return false;
        }

        // Default logic is "all" - every condition must match.
        foreach ($conditions as $entry) {
            if (!$entry['object']->matches($user)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Load conditions for a rule. Returns both the DB record (so callers
     * can read configdata for SQL pre-filters) and the instantiated object.
     *
     * @param int $ruleid
     * @return array List of ['record' => stdClass, 'object' => condition_base].
     */
    public static function load_conditions(int $ruleid): array {
        global $DB;
        $types = self::get_condition_types();
        $out = [];
        $records = $DB->get_records('tool_automate_condition', ['ruleid' => $ruleid], 'sortorder, id');
        foreach ($records as $record) {
            if (!isset($types[$record->type])) {
                continue;
            }
            $class = $types[$record->type];
            $config = (array) json_decode($record->configdata ?? '{}', true);
            $out[] = ['record' => $record, 'object' => new $class($config)];
        }
        return $out;
    }

    /**
     * Load actions for a rule.
     *
     * @param int $ruleid
     * @return action\action_base[]
     */
    public static function load_actions(int $ruleid): array {
        global $DB;
        $types = self::get_action_types();
        $out = [];
        $records = $DB->get_records('tool_automate_action', ['ruleid' => $ruleid], 'sortorder, id');
        foreach ($records as $record) {
            if (!isset($types[$record->type])) {
                continue;
            }
            $class = $types[$record->type];
            $config = (array) json_decode($record->configdata ?? '{}', true);
            $out[] = new $class($config);
        }
        return $out;
    }

    /**
     * Build the candidate user set for a rule.
     *
     * For event/manual single-user runs this is just that one user. For
     * cron and manual all-user runs we start from all real, active users
     * and let each condition apply an optional SQL pre-filter.
     *
     * @param int $ruleid
     * @param int|null $onlyuserid
     * @return \stdClass[]
     */
    protected static function get_target_users(int $ruleid, ?int $onlyuserid): array {
        global $DB, $CFG;
        if ($onlyuserid) {
            $user = $DB->get_record('user', ['id' => $onlyuserid, 'deleted' => 0]);
            return $user ? [$user] : [];
        }

        $where = ['u.deleted = 0', 'u.id <> :guestid'];
        $params = ['guestid' => $CFG->siteguest];

        foreach (self::load_conditions($ruleid) as $entry) {
            $class = get_class($entry['object']);
            $config = (array) json_decode($entry['record']->configdata ?? '{}', true);
            [$sql, $sqlparams] = $class::get_user_sql_filter($config);
            if ($sql !== '') {
                $where[] = $sql;
                $params += $sqlparams;
            }
        }

        $sql = 'SELECT u.* FROM {user} u WHERE ' . implode(' AND ', $where);
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Write a log row.
     *
     * @param int $ruleid
     * @param int|null $userid
     * @param bool $dryrun
     * @param string $outcome
     * @param string $message
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
