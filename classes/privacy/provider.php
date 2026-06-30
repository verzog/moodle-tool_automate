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

namespace tool_automate\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for tool_automate.
 *
 * Two pieces of user-correlated data live in this plugin, both at the
 * system context:
 *  - tool_automate_rule.usermodified  - which admin authored / last
 *                                       edited each rule, plus the
 *                                       admin-supplied free-text
 *                                       description on that rule;
 *  - tool_automate_log.userid         - the user (or for course-subject
 *                                       rules, the course id) a
 *                                       particular rule run evaluated
 *                                       on; NULL for finalise /
 *                                       aggregate / error rows.
 *
 * Important: the log table's userid column is used by both user-
 * subject and course-subject rules - the latter writes course ids
 * there too. Without an immutable per-row "kind" marker we cannot
 * distinguish a userid=5 row that's about user 5 from a userid=5 row
 * that's about course 5. We deliberately do NOT key the privacy
 * queries off rule.subject because edit.php allows the subject to be
 * changed after the fact (once conditions / actions are cleared),
 * which would silently make pre-change log rows ineligible for
 * export and deletion. Instead we treat every non-null log userid as
 * a user reference. The only false-positive is the userid/courseid
 * id-space collision noted above; that delivers stale course-subject
 * log rows to a user export, which is conservative (it doesn't leak
 * another person's data, just records that "rule X ran on something
 * with id 5") and matches the manager's own ambiguous use of the
 * column.
 *
 * Report files saved by generate_report actions live under
 * tool_automate/reports in the system context, but they're shared
 * system aggregates with no per-user attribution path - we cannot tell
 * which report contains which user's data. So we don't declare them
 * in the metadata link (which would imply a per-user export path that
 * doesn't exist) and we don't try to export them per user; the
 * context-wide purge still deletes the whole area.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what user data this plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'tool_automate_rule',
            [
                'name'         => 'privacy:metadata:rule:name',
                'description'  => 'privacy:metadata:rule:description',
                'usermodified' => 'privacy:metadata:rule:usermodified',
                'timemodified' => 'privacy:metadata:rule:timemodified',
            ],
            'privacy:metadata:rule'
        );
        $collection->add_database_table(
            'tool_automate_log',
            [
                'ruleid'      => 'privacy:metadata:log:ruleid',
                'userid'      => 'privacy:metadata:log:userid',
                'outcome'     => 'privacy:metadata:log:outcome',
                'message'     => 'privacy:metadata:log:message',
                'timecreated' => 'privacy:metadata:log:timecreated',
            ],
            'privacy:metadata:log'
        );
        // An assign_role action records, in its JSON config, the id of the
        // user who configured it - so the run-time role re-check can validate
        // the stored role against that user's assignable set rather than the
        // rule's last editor. That is a stored user reference, so it is
        // declared, discovered and erased here too.
        $collection->add_database_table(
            'tool_automate_action',
            [
                'configdata' => 'privacy:metadata:action:configdata',
            ],
            'privacy:metadata:action'
        );
        return $collection;
    }

    /**
     * The plugin only ever writes at the system context, so a user has
     * data here iff they show up in either of the two tracked tables.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        $hasdata = $DB->record_exists('tool_automate_rule', ['usermodified' => $userid])
            || $DB->record_exists('tool_automate_log', ['userid' => $userid])
            || in_array($userid, self::action_author_map(), true);
        if ($hasdata) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Map of assign_role action id => configurer user id, read out of the
     * actions' JSON config. Only actions that actually record a configurer
     * (authorid > 0) are included. Done in PHP because the id lives inside a
     * JSON blob that no portable SQL can filter on.
     *
     * @return array<int,int> action id => configurer user id
     */
    protected static function action_author_map(): array {
        global $DB;
        $out = [];
        $records = $DB->get_records('tool_automate_action', ['type' => 'assign_role'], '', 'id, configdata');
        foreach ($records as $record) {
            $config = (array) json_decode($record->configdata ?? '{}', true);
            $authorid = (int) ($config['authorid'] ?? 0);
            if ($authorid > 0) {
                $out[(int) $record->id] = $authorid;
            }
        }
        return $out;
    }

    /**
     * Reset the recorded configurer to 0 on every assign_role action
     * configured by one of the given users. The action (rule config) is
     * kept; only the user reference is anonymised. execute() then falls
     * back to the rule's last editor, as for a pre-existing action that
     * never recorded a configurer.
     *
     * @param int[] $userids
     */
    protected static function anonymise_action_authors(array $userids): void {
        global $DB;
        $userids = array_map('intval', $userids);
        if (!$userids) {
            return;
        }
        foreach (self::action_author_map() as $actionid => $authorid) {
            if (!in_array($authorid, $userids, true)) {
                continue;
            }
            $record = $DB->get_record('tool_automate_action', ['id' => $actionid]);
            $config = (array) json_decode($record->configdata ?? '{}', true);
            $config['authorid'] = 0;
            $record->configdata = json_encode($config);
            $DB->update_record('tool_automate_action', $record);
        }
    }

    /**
     * Reverse lookup: which users have data attached at this context?
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT usermodified AS userid FROM {tool_automate_rule}', []);
        $userlist->add_from_sql(
            'userid',
            'SELECT userid FROM {tool_automate_log} WHERE userid IS NOT NULL',
            []
        );
        $authorids = array_values(array_unique(self::action_author_map()));
        if ($authorids) {
            $userlist->add_users($authorids);
        }
    }

    /**
     * Export everything we hold about each user across the requested
     * (already-approved) contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!$contextlist->count()) {
            return;
        }
        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }
            $rules = $DB->get_records('tool_automate_rule', ['usermodified' => $user->id], 'timemodified DESC');
            if ($rules) {
                $rulesexport = array_map(fn($r) => (object) [
                    'name'         => format_string($r->name),
                    'description'  => format_text(
                        (string) ($r->description ?? ''),
                        FORMAT_PLAIN,
                        ['context' => $context]
                    ),
                    'timemodified' => transform::datetime($r->timemodified),
                ], $rules);
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'tool_automate'), get_string('privacy:path:rules', 'tool_automate')],
                    (object) ['rules' => array_values($rulesexport)]
                );
            }

            $logs = $DB->get_records_sql(
                "SELECT l.id, l.ruleid, l.outcome, l.message, l.timecreated, r.name AS rulename
                   FROM {tool_automate_log} l
                   JOIN {tool_automate_rule} r ON r.id = l.ruleid
                  WHERE l.userid = :uid
               ORDER BY l.timecreated DESC",
                ['uid' => $user->id]
            );
            if ($logs) {
                $logsexport = array_map(fn($l) => (object) [
                    'rule'        => format_string($l->rulename),
                    'outcome'     => $l->outcome,
                    'message'     => format_string($l->message),
                    'timecreated' => transform::datetime($l->timecreated),
                ], $logs);
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'tool_automate'), get_string('privacy:path:log', 'tool_automate')],
                    (object) ['log' => array_values($logsexport)]
                );
            }

            // Assign-role actions this user configured (recorded as the
            // action's authorid so the run-time role check can validate
            // against them).
            $authored = [];
            foreach (self::action_author_map() as $actionid => $authorid) {
                if ($authorid !== (int) $user->id) {
                    continue;
                }
                $action = $DB->get_record('tool_automate_action', ['id' => $actionid]);
                if (!$action) {
                    continue;
                }
                $config = (array) json_decode($action->configdata ?? '{}', true);
                $roleid = (int) ($config['roleid'] ?? 0);
                $authored[] = (object) [
                    'rule' => format_string((string) $DB->get_field('tool_automate_rule', 'name', ['id' => $action->ruleid])),
                    'role' => $roleid ? (string) $DB->get_field('role', 'shortname', ['id' => $roleid]) : '',
                ];
            }
            if ($authored) {
                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'tool_automate'), get_string('privacy:path:actions', 'tool_automate')],
                    (object) ['actions' => $authored]
                );
            }
        }
    }

    /**
     * Wipe everything we hold under the given context. For tool_automate
     * the only context that ever holds data is the system context.
     *
     * Rules are config (not personal data) and aren't deleted - we just
     * anonymise the usermodified attribution. Log rows are deleted in
     * their entirety: the user-keyed ones are obviously personal, and
     * the aggregate / finalise / error rows (userid IS NULL) can still
     * carry personal data in their message column (the report-finalise
     * message includes recipient email and report URL, for example),
     * so a context-wide purge has to take them too. Saved report files
     * are likewise deleted - they're system aggregates with no
     * per-user attribution but can include user data inline.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!($context instanceof \context_system)) {
            return;
        }
        $DB->set_field('tool_automate_rule', 'usermodified', 0, []);
        $DB->execute('DELETE FROM {tool_automate_log}');
        self::anonymise_action_authors(array_values(self::action_author_map()));
        get_file_storage()->delete_area_files($context->id, 'tool_automate', 'reports');
    }

    /**
     * Wipe one user's data across the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist as $context) {
            if (!($context instanceof \context_system)) {
                continue;
            }
            $DB->set_field('tool_automate_rule', 'usermodified', 0, ['usermodified' => $user->id]);
            $DB->execute(
                'DELETE FROM {tool_automate_log} WHERE userid = :uid',
                ['uid' => $user->id]
            );
            self::anonymise_action_authors([(int) $user->id]);
        }
    }

    /**
     * Wipe a set of users' data at one context. Same semantics as
     * delete_data_for_user() but driven by an admin-supplied list
     * rather than the user-initiated subject access flow.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if (!($userlist->get_context() instanceof \context_system)) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tau');
        $DB->execute(
            "UPDATE {tool_automate_rule} SET usermodified = 0 WHERE usermodified $insql",
            $params
        );
        $DB->execute(
            "DELETE FROM {tool_automate_log} WHERE userid $insql",
            $params
        );
        self::anonymise_action_authors($userids);
    }
}
