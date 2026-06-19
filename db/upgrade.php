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

/**
 * Database upgrades.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run the upgrade steps.
 *
 * @param int $oldversion Previous installed plugin version.
 * @return bool
 */
function xmldb_tool_automate_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026061200) {
        $table = new xmldb_table('tool_automate_rule');

        $field = new xmldb_field(
            'logic',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'all',
            'eventname'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'expression',
            XMLDB_TYPE_TEXT,
            null,
            null,
            null,
            null,
            null,
            'logic'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061200, 'tool', 'automate');
    }

    if ($oldversion < 2026061300) {
        $table = new xmldb_table('tool_automate_rule');

        $field = new xmldb_field(
            'courseid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'expression'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'roleid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'courseid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026061300, 'tool', 'automate');
    }

    if ($oldversion < 2026061900) {
        $ruletable = new xmldb_table('tool_automate_rule');
        $subject = new xmldb_field(
            'subject',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'user',
            'description'
        );
        if (!$dbman->field_exists($ruletable, $subject)) {
            $dbman->add_field($ruletable, $subject);
        }

        $conditiontable = new xmldb_table('tool_automate_condition');
        $polarity = new xmldb_field(
            'polarity',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'match',
            'type'
        );
        if (!$dbman->field_exists($conditiontable, $polarity)) {
            $dbman->add_field($conditiontable, $polarity);
        }

        upgrade_plugin_savepoint(true, 2026061900, 'tool', 'automate');
    }

    return true;
}
