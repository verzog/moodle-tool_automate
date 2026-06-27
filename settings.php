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
 * Admin settings - adds the management page to the admin tree.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Group the management page and the settings page together under a single
    // Automate category in the admin tree, rather than leaving them as two
    // unrelated siblings under Admin tools.
    $ADMIN->add('tools', new admin_category(
        'tool_automate_category',
        get_string('pluginname', 'tool_automate')
    ));

    $ADMIN->add('tool_automate_category', new admin_externalpage(
        'tool_automate',
        get_string('rules', 'tool_automate'),
        new moodle_url('/admin/tool/automate/index.php'),
        'tool/automate:manage'
    ));

    // Bulk restore from repository, as its own menu node next to the rules
    // overview (it is also linked as a button from that overview). Reached
    // only with the same manage capability.
    $ADMIN->add('tool_automate_category', new admin_externalpage(
        'tool_automate_restore',
        get_string('restoretitle', 'tool_automate'),
        new moodle_url('/admin/tool/automate/restore.php'),
        'tool/automate:manage'
    ));

    // Site-level toggles. The destructive course_delete action is
    // off by default - a site admin has to opt in here before it
    // shows up in the action picker or will run on an existing rule.
    $settings = new admin_settingpage(
        'tool_automate_settings',
        get_string('settings', 'tool_automate')
    );
    $settings->add(new admin_setting_configcheckbox(
        'tool_automate/allow_course_delete',
        get_string('setting_allow_course_delete', 'tool_automate'),
        get_string('setting_allow_course_delete_desc', 'tool_automate'),
        0
    ));
    // Concurrency cap for the course-delete adhoc task. Keeps a
    // large bulk-delete rule from monopolising the cron worker pool
    // and starving other adhoc tasks.
    $settings->add(new admin_setting_configtext(
        'tool_automate/course_delete_concurrency',
        get_string('setting_course_delete_concurrency', 'tool_automate'),
        get_string('setting_course_delete_concurrency_desc', 'tool_automate'),
        2,
        PARAM_INT
    ));

    // Bulk restore from a repository directory. Off by default - it
    // creates courses in bulk, so a site admin opts in here before the
    // restore page or CLI will queue anything.
    $settings->add(new admin_setting_configcheckbox(
        'tool_automate/allow_bulk_restore',
        get_string('setting_allow_bulk_restore', 'tool_automate'),
        get_string('setting_allow_bulk_restore_desc', 'tool_automate'),
        0
    ));
    // Server directory the restore page / CLI reads .mbz backups from. The
    // custom setting class appends a live "readable / not readable" status so
    // an admin can tell at a glance whether Moodle can see the directory.
    $settings->add(new \tool_automate\admin\setting_restore_source_dir(
        'tool_automate/restore_source_dir',
        get_string('setting_restore_source_dir', 'tool_automate'),
        get_string('setting_restore_source_dir_desc', 'tool_automate'),
        '',
        PARAM_RAW_TRIMMED
    ));
    // Concurrency cap for the restore adhoc task, mirroring the
    // course-delete cap - a directory of large backups can queue many
    // heavy restores.
    $settings->add(new admin_setting_configtext(
        'tool_automate/restore_concurrency',
        get_string('setting_restore_concurrency', 'tool_automate'),
        get_string('setting_restore_concurrency_desc', 'tool_automate'),
        2,
        PARAM_INT
    ));
    $ADMIN->add('tool_automate_category', $settings);
}
