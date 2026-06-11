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
 * Add/edit a rule.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\form\rule_form;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url(new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]));

$mform = new rule_form();

// Load existing values into the form.
if ($id) {
    $rule = $DB->get_record('tool_automate_rule', ['id' => $id], '*', MUST_EXIST);
    $data = (array) $rule;
    if ($cond = $DB->get_record('tool_automate_condition', ['ruleid' => $id], '*', IGNORE_MULTIPLE)) {
        $config = (array) json_decode($cond->configdata ?? '{}', true);
        $data['conditiontype'] = $cond->type;
        $data['emailpattern'] = $config['pattern'] ?? '';
    }
    if ($act = $DB->get_record('tool_automate_action', ['ruleid' => $id], '*', IGNORE_MULTIPLE)) {
        $config = (array) json_decode($act->configdata ?? '{}', true);
        $data['actiontype'] = $act->type;
        $data['cohortid'] = $config['cohortid'] ?? 0;
    }
    $mform->set_data($data);
}

if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if ($formdata = $mform->get_data()) {
    $now = time();
    $record = (object) [
        'name'         => $formdata->name,
        'description'  => $formdata->description ?? '',
        'triggertype'  => $formdata->triggertype,
        'eventname'    => $formdata->triggertype === 'event' ? $formdata->eventname : null,
        'enabled'      => $formdata->enabled,
        'usermodified' => $USER->id,
        'timemodified' => $now,
    ];
    if ($formdata->id) {
        $record->id = $formdata->id;
        $DB->update_record('tool_automate_rule', $record);
        $ruleid = $record->id;
    } else {
        $record->timecreated = $now;
        $ruleid = $DB->insert_record('tool_automate_rule', $record);
    }

    // Replace the rule's single condition and action (v0 keeps it to one of each).
    $DB->delete_records('tool_automate_condition', ['ruleid' => $ruleid]);
    $DB->insert_record('tool_automate_condition', (object) [
        'ruleid'     => $ruleid,
        'type'       => $formdata->conditiontype,
        'configdata' => json_encode(['pattern' => trim($formdata->emailpattern)]),
        'sortorder'  => 0,
    ]);

    $DB->delete_records('tool_automate_action', ['ruleid' => $ruleid]);
    $DB->insert_record('tool_automate_action', (object) [
        'ruleid'     => $ruleid,
        'type'       => $formdata->actiontype,
        'configdata' => json_encode(['cohortid' => (int) $formdata->cohortid]),
        'sortorder'  => 0,
    ]);

    redirect($baseurl);
}

echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();
