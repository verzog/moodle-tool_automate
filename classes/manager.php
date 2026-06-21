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
 * runs a rule: find target users (or courses), evaluate conditions per
 * the rule's logic, run actions, log everything.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** Subject discriminator: rule operates on users. */
    public const SUBJECT_USER = 'user';

    /** Subject discriminator: rule operates on courses. */
    public const SUBJECT_COURSE = 'course';

    /** Polarity: condition matches if its test is true. */
    public const POLARITY_MATCH = 'match';

    /** Polarity: condition matches if its test is false. */
    public const POLARITY_NOTMATCH = 'notmatch';

    /**
     * Registry of condition types (all subjects).
     *
     * @return array Type code => fully qualified class name.
     */
    public static function get_condition_types(): array {
        return [
            'email_matches'        => condition\email_matches::class,
            'inactive_for_days'    => condition\inactive_for_days::class,
            'profile_field'        => condition\profile_field::class,
            'auth_method'          => condition\auth_method::class,
            'cohort_membership'    => condition\cohort_membership::class,
            'account_age'          => condition\account_age::class,
            'custom_profile_field' => condition\custom_profile_field::class,
            'enrolled_in_course'   => condition\enrolled_in_course::class,
            'user_name_contains'   => condition\user_name_contains::class,
            'user_name_matches'    => condition\user_name_matches::class,
            'user_username_contains' => condition\user_username_contains::class,
            'user_username_matches' => condition\user_username_matches::class,
            'course_visibility'    => condition\course_visibility::class,
            'course_in_category'   => condition\course_in_category::class,
            'course_idnumber_matches' => condition\course_idnumber_matches::class,
            'course_name_contains' => condition\course_name_contains::class,
            'course_name_matches'  => condition\course_name_matches::class,
            'course_no_activity_days' => condition\course_no_activity_days::class,
            'course_startdate_between' => condition\course_startdate_between::class,
            'course_completion_rate' => condition\course_completion_rate::class,
        ];
    }

    /**
     * Registry of condition types filtered by subject.
     *
     * @param string $subject 'user' or 'course'.
     * @return array Type code => fully qualified class name.
     */
    public static function get_condition_types_for_subject(string $subject): array {
        $out = [];
        foreach (self::get_condition_types() as $code => $class) {
            if ($class::get_subject() === $subject) {
                $out[$code] = $class;
            }
        }
        return $out;
    }

    /**
     * Registry of action types (all subjects).
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
            'enrol_in_course'    => action\enrol_in_course::class,
            'add_to_group'       => action\add_to_group::class,
            'send_email'         => action\send_email::class,
            'set_profile_field'  => action\set_profile_field::class,
            'generate_report'    => action\generate_report::class,
            'course_set_visibility'  => action\course_set_visibility::class,
            'course_move_to_category' => action\course_move_to_category::class,
            'course_email_teachers'   => action\course_email_teachers::class,
            'course_generate_report'  => action\course_generate_report::class,
            'course_copy'             => action\course_copy::class,
        ];
    }

    /**
     * Registry of action types filtered by subject.
     *
     * @param string $subject 'user' or 'course'.
     * @return array Type code => fully qualified class name.
     */
    public static function get_action_types_for_subject(string $subject): array {
        $out = [];
        foreach (self::get_action_types() as $code => $class) {
            if ($class::get_subject() === $subject) {
                $out[$code] = $class;
            }
        }
        return $out;
    }

    /**
     * Run a rule.
     *
     * @param int $ruleid
     * @param bool $dryrun
     * @param int|null $onlysubjectid Restrict to one subject record (used
     *                                 by event triggers).
     * @return array Result rows {userid, fullname, outcome, message}.
     */
    public static function run_rule(int $ruleid, bool $dryrun, ?int $onlysubjectid = null): array {
        global $DB;

        $rule = $DB->get_record('tool_automate_rule', ['id' => $ruleid], '*', MUST_EXIST);
        $subject = $rule->subject ?? self::SUBJECT_USER;
        $logic = $rule->logic ?? 'all';
        $conditions = self::load_conditions($ruleid);
        $actions = self::load_actions($ruleid);
        $subjects = $subject === self::SUBJECT_COURSE
            ? self::get_target_courses($conditions, $logic, $onlysubjectid)
            : self::get_target_users($conditions, $logic, $onlysubjectid);

        $results = [];
        foreach ($subjects as $record) {
            if (!self::evaluate_conditions($rule, $conditions, $record)) {
                continue;
            }
            if (empty($actions)) {
                continue;
            }
            foreach ($actions as $action) {
                try {
                    $message = $action->execute($record, $dryrun);
                    $outcome = 'actioned';
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    $outcome = 'error';
                }
                $subjectid = (int) $record->id;
                // Only user IDs go into the log's userid column. For
                // course-subject rules, the id is reflected in the
                // message; the log column stays null so the privacy
                // provider doesn't treat course ids as user ids.
                $logsubjectid = $subject === self::SUBJECT_USER ? $subjectid : null;
                self::log($ruleid, $logsubjectid, $dryrun, $outcome, $message);
                $results[] = (object) [
                    'userid'   => $subjectid,
                    'fullname' => self::describe_subject($subject, $record),
                    'outcome'  => $outcome,
                    'message'  => $message,
                ];
            }
        }

        // Aggregating actions (e.g. generate_report) emit their result now.
        foreach ($actions as $action) {
            try {
                $message = $action->finalise($dryrun);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                self::log($ruleid, null, $dryrun, 'error', $message);
                $results[] = (object) [
                    'userid'   => 0,
                    'fullname' => '-',
                    'outcome'  => 'error',
                    'message'  => $message,
                ];
                continue;
            }
            if ($message === null) {
                continue;
            }
            self::log($ruleid, null, $dryrun, 'finalised', $message);
            $results[] = (object) [
                'userid'   => 0,
                'fullname' => '-',
                'outcome'  => 'finalised',
                'message'  => $message,
                'url'      => $action->get_result_url(),
            ];
        }

        return $results;
    }

    /**
     * Subject-appropriate display label for a result row.
     *
     * @param string $subject
     * @param \stdClass $record
     * @return string
     */
    protected static function describe_subject(string $subject, \stdClass $record): string {
        if ($subject === self::SUBJECT_COURSE) {
            return format_string($record->fullname ?? ('#' . $record->id));
        }
        return fullname($record);
    }

    /**
     * Evaluate a rule's conditions for one subject record.
     *
     * @param \stdClass $rule
     * @param array $conditions Output of self::load_conditions().
     * @param \stdClass $subject
     * @return bool
     */
    protected static function evaluate_conditions(\stdClass $rule, array $conditions, \stdClass $subject): bool {
        if (empty($conditions)) {
            return true;
        }
        $logic = $rule->logic ?? 'all';

        if ($logic === 'expression' && !empty($rule->expression)) {
            return self::evaluate_expression((string) $rule->expression, $conditions, $subject);
        }

        if ($logic === 'any') {
            foreach ($conditions as $entry) {
                if (self::condition_matches($entry, $subject)) {
                    return true;
                }
            }
            return false;
        }

        // Default logic is "all" - every condition must match.
        foreach ($conditions as $entry) {
            if (!self::condition_matches($entry, $subject)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Run a single condition's test, then invert if polarity is "notmatch".
     *
     * @param array $entry ['record' => stdClass, 'object' => condition_base]
     * @param \stdClass $subject
     * @return bool
     */
    protected static function condition_matches(array $entry, \stdClass $subject): bool {
        $raw = (bool) $entry['object']->matches($subject);
        $polarity = $entry['record']->polarity ?? self::POLARITY_MATCH;
        return $polarity === self::POLARITY_NOTMATCH ? !$raw : $raw;
    }

    /**
     * Evaluate the boolean expression against the per-condition results.
     * Identifiers (c1, c2, ...) honour each condition's polarity.
     *
     * @param string $expression
     * @param array $conditions
     * @param \stdClass $subject
     * @return bool
     */
    protected static function evaluate_expression(string $expression, array $conditions, \stdClass $subject): bool {
        $values = [];
        foreach ($conditions as $i => $entry) {
            $values['c' . ($i + 1)] = self::condition_matches($entry, $subject);
        }
        try {
            return expression::evaluate($expression, $values);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Load conditions for a rule. Returns both the DB record (so callers
     * can read configdata for SQL pre-filters and polarity) and the
     * instantiated object.
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
     * Is it safe to use a condition's SQL pre-filter? A pre-filter narrows
     * the candidate set before condition_matches() runs in PHP. That is
     * sound only when:
     *   - The rule's logic is "all" (every condition must match, so an
     *     AND of pre-filters cannot drop a record that should be in the
     *     final set), AND
     *   - The condition's polarity is "match" (the pre-filter selects
     *     records that satisfy the condition; for "notmatch" it would
     *     select the records we're about to reject).
     *
     * @param string $logic
     * @param string $polarity
     * @return bool
     */
    protected static function prefilter_is_safe(string $logic, string $polarity): bool {
        return $logic === 'all' && $polarity === self::POLARITY_MATCH;
    }

    /**
     * Build the candidate user set for a user-subject rule.
     *
     * For event/manual single-user runs this is just that one user. For
     * cron and manual all-user runs we start from all real, active users
     * and let each condition apply an optional SQL pre-filter (when safe
     * for the rule's logic and the condition's polarity).
     *
     * @param array $conditions Pre-loaded conditions.
     * @param string $logic Rule logic ('all'|'any'|'expression').
     * @param int|null $onlyuserid
     * @return \stdClass[]
     */
    protected static function get_target_users(array $conditions, string $logic, ?int $onlyuserid): array {
        global $DB, $CFG;
        if ($onlyuserid) {
            $user = $DB->get_record('user', ['id' => $onlyuserid, 'deleted' => 0]);
            return $user ? [$user] : [];
        }

        $where = ['u.deleted = 0', 'u.id <> :guestid'];
        $params = ['guestid' => $CFG->siteguest];

        foreach ($conditions as $entry) {
            $class = get_class($entry['object']);
            if ($class::get_subject() !== self::SUBJECT_USER) {
                continue;
            }
            $polarity = $entry['record']->polarity ?? self::POLARITY_MATCH;
            if (!self::prefilter_is_safe($logic, $polarity)) {
                continue;
            }
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
     * Build the candidate course set for a course-subject rule.
     *
     * The site course (SITEID) is always excluded. SQL pre-filters are
     * only applied when safe (see prefilter_is_safe()).
     *
     * @param array $conditions Pre-loaded conditions.
     * @param string $logic Rule logic ('all'|'any'|'expression').
     * @param int|null $onlycourseid
     * @return \stdClass[]
     */
    protected static function get_target_courses(array $conditions, string $logic, ?int $onlycourseid): array {
        global $DB;
        if ($onlycourseid) {
            $course = $DB->get_record('course', ['id' => $onlycourseid]);
            return $course && (int) $course->id !== SITEID ? [$course] : [];
        }

        $where = ['c.id <> :siteid'];
        $params = ['siteid' => SITEID];

        foreach ($conditions as $entry) {
            $class = get_class($entry['object']);
            if ($class::get_subject() !== self::SUBJECT_COURSE) {
                continue;
            }
            $polarity = $entry['record']->polarity ?? self::POLARITY_MATCH;
            if (!self::prefilter_is_safe($logic, $polarity)) {
                continue;
            }
            $config = (array) json_decode($entry['record']->configdata ?? '{}', true);
            [$sql, $sqlparams] = $class::get_course_sql_filter($config);
            if ($sql !== '') {
                $where[] = $sql;
                $params += $sqlparams;
            }
        }

        $sql = 'SELECT c.* FROM {course} c WHERE ' . implode(' AND ', $where);
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
