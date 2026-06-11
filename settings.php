<?php
// This file is part of Moodle - http://moodle.org/
// (GPL v3 or later - full header omitted for brevity in this listing.)

/**
 * Admin settings - adds the management page to the admin tree.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('tools', new admin_externalpage(
        'tool_automate',
        get_string('pluginname', 'tool_automate'),
        new moodle_url('/admin/tool/automate/index.php'),
        'tool/automate:manage'
    ));
}
