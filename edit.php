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
 * Add/edit a rule and its attached conditions and actions.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\form\rule_form;
use tool_automate\manager;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url(new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]));

$mform = new rule_form();

if ($id) {
    $rule = $DB->get_record('tool_automate_rule', ['id' => $id], '*', MUST_EXIST);
    $mform->set_data((array) $rule);
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
        'logic'        => $formdata->logic,
        'expression'   => $formdata->logic === 'expression' ? trim($formdata->expression) : null,
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
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => $ruleid]));
}

echo $OUTPUT->header();
$mform->display();

if ($id) {
    // Conditions list.
    echo $OUTPUT->heading(get_string('conditionheading', 'tool_automate'), 3);
    $condtypes = manager::get_condition_types();
    $conditions = $DB->get_records('tool_automate_condition', ['ruleid' => $id], 'sortorder, id');
    if ($conditions) {
        $table = new html_table();
        $table->head = [
            get_string('label', 'tool_automate'),
            get_string('conditiontype', 'tool_automate'),
            get_string('summary', 'tool_automate'),
            get_string('actions', 'tool_automate'),
        ];
        $i = 1;
        foreach ($conditions as $c) {
            $class = $condtypes[$c->type] ?? null;
            $config = (array) json_decode($c->configdata ?? '{}', true);
            $editurl = new moodle_url('/admin/tool/automate/condition_edit.php', [
                'ruleid' => $id, 'id' => $c->id,
            ]);
            $deleteurl = new moodle_url('/admin/tool/automate/condition_edit.php', [
                'ruleid' => $id, 'id' => $c->id, 'delete' => 1, 'sesskey' => sesskey(),
            ]);
            $links = html_writer::link($editurl, get_string('edit', 'tool_automate'))
                . ' | '
                . html_writer::link($deleteurl, get_string('delete', 'tool_automate'));
            $table->data[] = [
                'c' . $i,
                $class ? $class::get_name() : s($c->type),
                $class ? $class::describe($config) : '',
                $links,
            ];
            $i++;
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noconditions', 'tool_automate'), 'info');
    }

    // Add condition dropdown.
    $addcondurl = new moodle_url('/admin/tool/automate/condition_edit.php', ['ruleid' => $id]);
    echo html_writer::start_tag('form', [
        'action' => $addcondurl->out_omit_querystring(), 'method' => 'get',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruleid', 'value' => $id]);
    $options = ['' => get_string('addcondition', 'tool_automate')];
    foreach ($condtypes as $type => $class) {
        $options[$type] = $class::get_name();
    }
    echo html_writer::select($options, 'type', '', false);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
    ]);
    echo html_writer::end_tag('form');

    // Actions list.
    echo $OUTPUT->heading(get_string('actionheading', 'tool_automate'), 3);
    $acttypes = manager::get_action_types();
    $actions = $DB->get_records('tool_automate_action', ['ruleid' => $id], 'sortorder, id');
    if ($actions) {
        $table = new html_table();
        $table->head = [
            get_string('actiontype', 'tool_automate'),
            get_string('summary', 'tool_automate'),
            get_string('actions', 'tool_automate'),
        ];
        foreach ($actions as $a) {
            $class = $acttypes[$a->type] ?? null;
            $config = (array) json_decode($a->configdata ?? '{}', true);
            $editurl = new moodle_url('/admin/tool/automate/action_edit.php', [
                'ruleid' => $id, 'id' => $a->id,
            ]);
            $deleteurl = new moodle_url('/admin/tool/automate/action_edit.php', [
                'ruleid' => $id, 'id' => $a->id, 'delete' => 1, 'sesskey' => sesskey(),
            ]);
            $links = html_writer::link($editurl, get_string('edit', 'tool_automate'))
                . ' | '
                . html_writer::link($deleteurl, get_string('delete', 'tool_automate'));
            $table->data[] = [
                $class ? $class::get_name() : s($a->type),
                $class ? $class::describe($config) : '',
                $links,
            ];
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noactions', 'tool_automate'), 'info');
    }

    $addacturl = new moodle_url('/admin/tool/automate/action_edit.php', ['ruleid' => $id]);
    echo html_writer::start_tag('form', [
        'action' => $addacturl->out_omit_querystring(), 'method' => 'get',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruleid', 'value' => $id]);
    $options = ['' => get_string('addaction', 'tool_automate')];
    foreach ($acttypes as $type => $class) {
        $options[$type] = $class::get_name();
    }
    echo html_writer::select($options, 'type', '', false);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
