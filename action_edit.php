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
 * Add/edit/delete an action attached to a rule.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\form\action_form;
use tool_automate\manager;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$ruleid = required_param('ruleid', PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_ALPHANUMEXT);
$delete = optional_param('delete', 0, PARAM_BOOL);

$rule = $DB->get_record('tool_automate_rule', ['id' => $ruleid], '*', MUST_EXIST);
$ruleurl = new moodle_url('/admin/tool/automate/edit.php', ['id' => $ruleid]);
$PAGE->set_url(new moodle_url('/admin/tool/automate/action_edit.php',
    ['ruleid' => $ruleid, 'id' => $id]));

$existing = null;
if ($id) {
    $existing = $DB->get_record('tool_automate_action',
        ['id' => $id, 'ruleid' => $ruleid], '*', MUST_EXIST);
    $type = $existing->type;
}

if ($delete && $id && confirm_sesskey()) {
    $DB->delete_records('tool_automate_action', ['id' => $id, 'ruleid' => $ruleid]);
    redirect($ruleurl);
}

$types = manager::get_action_types();
if (!isset($types[$type])) {
    redirect($ruleurl, get_string('chooseatype', 'tool_automate'), null,
        \core\output\notification::NOTIFY_WARNING);
}
$class = $types[$type];

$mform = new action_form(null, ['type' => $type]);

if ($existing) {
    $config = (array) json_decode($existing->configdata ?? '{}', true);
    $defaults = $class::config_to_form_defaults($config);
    $defaults['id'] = $existing->id;
    $defaults['ruleid'] = $ruleid;
    $defaults['type'] = $type;
    $mform->set_data($defaults);
} else {
    $mform->set_data(['ruleid' => $ruleid, 'type' => $type]);
}

if ($mform->is_cancelled()) {
    redirect($ruleurl);
} else if ($formdata = $mform->get_data()) {
    $config = $class::extract_config($formdata);
    if ($existing) {
        $existing->configdata = json_encode($config);
        $DB->update_record('tool_automate_action', $existing);
    } else {
        $maxsort = (int) $DB->get_field('tool_automate_action', 'COALESCE(MAX(sortorder), -1)',
            ['ruleid' => $ruleid]);
        $DB->insert_record('tool_automate_action', (object) [
            'ruleid'     => $ruleid,
            'type'       => $type,
            'configdata' => json_encode($config),
            'sortorder'  => $maxsort + 1,
        ]);
    }
    redirect($ruleurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($class::get_name());
$mform->display();
echo $OUTPUT->footer();
