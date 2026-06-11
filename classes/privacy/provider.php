<?php
namespace tool_automate\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\approved_userlist;

/**
 * Privacy provider - the only personal data this plugin stores is the run log,
 * which records which user a rule acted on. Held at system context.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_automate_log', [
            'userid'      => 'privacy:metadata:log:userid',
            'ruleid'      => 'privacy:metadata:log:ruleid',
            'outcome'     => 'privacy:metadata:log:outcome',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists('tool_automate_log', ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context instanceof \context_system) {
            $userlist->add_from_sql('userid', 'SELECT userid FROM {tool_automate_log} WHERE userid IS NOT NULL', []);
        }
    }

    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(CONTEXT_SYSTEM, array_map(fn($c) => $c->contextlevel, $contextlist->get_contexts()))) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $records = $DB->get_records('tool_automate_log', ['userid' => $userid]);
        if ($records) {
            \core_privacy\local\request\writer::with_context(\context_system::instance())
                ->export_data([get_string('pluginname', 'tool_automate')], (object) ['logs' => array_values($records)]);
        }
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context instanceof \context_system) {
            $DB->delete_records('tool_automate_log');
        }
    }

    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $DB->delete_records('tool_automate_log', ['userid' => $userid]);
    }

    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        if ($userlist->get_context() instanceof \context_system) {
            [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $DB->delete_records_select('tool_automate_log', "userid $insql", $params);
        }
    }
}
