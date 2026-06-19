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
 * Legacy redirect. Conditions are now edited inline on edit.php so the
 * admin never leaves the rule page; this thin wrapper just forwards any
 * old URLs (e.g. bookmarks) to the equivalent inline-edit query string.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('tool/automate:manage', context_system::instance());

$ruleid = required_param('ruleid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHANUMEXT);
$delete = optional_param('delete', 0, PARAM_BOOL);

$params = ['id' => $ruleid];
if ($delete && $id) {
    // Confirm the inbound request already carries a valid sesskey before
    // forwarding it as a delete. Minting a fresh sesskey here would
    // accept any GET request as authorised once the admin is logged in,
    // creating a CSRF path.
    require_sesskey();
    $params['delcondition'] = $id;
    $params['sesskey'] = sesskey();
} else if ($id) {
    $params['editcondition'] = $id;
} else if ($type !== '') {
    $params['addcondition'] = $type;
}
redirect(new moodle_url('/admin/tool/automate/edit.php', $params));
