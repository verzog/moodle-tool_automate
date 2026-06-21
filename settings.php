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
    $ADMIN->add('tools', new admin_externalpage(
        'tool_automate',
        get_string('pluginname', 'tool_automate'),
        new moodle_url('/admin/tool/automate/index.php'),
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
    $ADMIN->add('tools', $settings);
}
