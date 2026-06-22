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
 *                                       edited each rule;
 *  - tool_automate_log.userid         - the user a particular rule run
 *                                       evaluated or acted on (or null
 *                                       for finalise / aggregate rows).
 *
 * Course-subject rules log course ids in the same userid column when no
 * user is involved, so the privacy code treats userid strictly as
 * "person id" - rows whose context can be proven to be a course (the
 * rule's subject is 'course') are skipped from per-user exports and
 * per-user deletions; they describe a course, not a person.
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
        // CSV / text reports produced by the generate_report actions are
        // stored under the system context's tool_automate/reports file
        // area. They may contain user names and ids - declare the area so
        // the metadata page lists it.
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:filearea:reports');
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
            || $DB->record_exists_select(
                'tool_automate_log',
                'userid = :uid AND ruleid IN (SELECT id FROM {tool_automate_rule} WHERE subject = :subj)',
                ['uid' => $userid, 'subj' => 'user']
            );
        if ($hasdata) {
            $contextlist->add_system_context();
        }
        return $contextlist;
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
            "SELECT l.userid
               FROM {tool_automate_log} l
               JOIN {tool_automate_rule} r ON r.id = l.ruleid
              WHERE r.subject = :subj AND l.userid IS NOT NULL",
            ['subj' => 'user']
        );
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
                  WHERE l.userid = :uid AND r.subject = :subj
               ORDER BY l.timecreated DESC",
                ['uid' => $user->id, 'subj' => 'user']
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
        }
    }

    /**
     * Wipe everything we hold under the given context. For tool_automate
     * the only context that ever holds data is the system context.
     *
     * Rules are config (not personal data) and aren't deleted - we just
     * anonymise the usermodified attribution. Log rows whose userid
     * column was a user reference (subject = 'user') are deleted
     * outright; the row exists *because of* the user, not just
     * incidentally referencing them.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if (!($context instanceof \context_system)) {
            return;
        }
        $DB->set_field('tool_automate_rule', 'usermodified', 0, []);
        $DB->execute(
            "DELETE FROM {tool_automate_log}
              WHERE userid IS NOT NULL
                AND ruleid IN (SELECT id FROM {tool_automate_rule} WHERE subject = :subj)",
            ['subj' => 'user']
        );
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
                "DELETE FROM {tool_automate_log}
                  WHERE userid = :uid
                    AND ruleid IN (SELECT id FROM {tool_automate_rule} WHERE subject = :subj)",
                ['uid' => $user->id, 'subj' => 'user']
            );
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
        $params['subj'] = 'user';
        $DB->execute(
            "DELETE FROM {tool_automate_log}
              WHERE userid $insql
                AND ruleid IN (SELECT id FROM {tool_automate_rule} WHERE subject = :subj)",
            $params
        );
    }
}
