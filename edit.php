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
use tool_automate\form\logic_form;
use tool_automate\manager;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url(new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]));

$mform = new rule_form();
$logicform = $id ? new logic_form() : null;

if ($id) {
    $rule = $DB->get_record('tool_automate_rule', ['id' => $id], '*', MUST_EXIST);
    $mform->set_data((array) $rule);
    $logicform->set_data([
        'ruleid'     => $rule->id,
        'logic'      => $rule->logic ?? 'all',
        'expression' => $rule->expression ?? '',
    ]);
}

// Logic-only submit: update logic/expression and reload.
if ($logicform && ($logicdata = $logicform->get_data()) && !empty($logicdata->updatelogic)) {
    $DB->update_record('tool_automate_rule', (object) [
        'id'           => (int) $logicdata->ruleid,
        'logic'        => $logicdata->logic,
        'expression'   => $logicdata->logic === 'expression' ? trim($logicdata->expression) : null,
        'usermodified' => $USER->id,
        'timemodified' => time(),
    ]);
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => (int) $logicdata->ruleid]));
}

if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if ($formdata = $mform->get_data()) {
    $now = time();
    $isevent = $formdata->triggertype === 'event';
    $eventname = $isevent ? $formdata->eventname : null;
    $record = (object) [
        'name'         => $formdata->name,
        'description'  => $formdata->description ?? '',
        'triggertype'  => $formdata->triggertype,
        'eventname'    => $eventname,
        'courseid'     => ($isevent && $eventname === '\\core\\event\\course_completed')
            ? (int) ($formdata->courseid ?? 0) : 0,
        'roleid'       => ($isevent && $eventname === '\\core\\event\\role_assigned')
            ? (int) ($formdata->roleid ?? 0) : 0,
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
        $record->logic = 'all';
        $ruleid = $DB->insert_record('tool_automate_rule', $record);
    }
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => $ruleid]));
}

echo $OUTPUT->header();
$mform->display();

if ($id) {
    $condtypes = manager::get_condition_types();
    $acttypes = manager::get_action_types();
    $conditions = $DB->get_records('tool_automate_condition', ['ruleid' => $id], 'sortorder, id');
    $actions = $DB->get_records('tool_automate_action', ['ruleid' => $id], 'sortorder, id');
    $showlogic = count($conditions) >= 2;

    // Render the Conditions column.
    ob_start();
    echo $OUTPUT->heading(get_string('conditionheading', 'tool_automate'), 3);
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
    $addcondurl = new moodle_url('/admin/tool/automate/condition_edit.php', ['ruleid' => $id]);
    echo html_writer::start_tag('form', [
        'action' => $addcondurl->out_omit_querystring(), 'method' => 'get',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'ruleid', 'value' => $id]);
    $opts = ['' => get_string('addcondition', 'tool_automate')];
    foreach ($condtypes as $type => $class) {
        $opts[$type] = $class::get_name();
    }
    echo html_writer::select($opts, 'type', '', false);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
    ]);
    echo html_writer::end_tag('form');
    $condcol = ob_get_clean();

    // Render the Logic column (only when meaningful).
    $logiccol = '';
    if ($showlogic) {
        ob_start();
        echo $OUTPUT->heading(get_string('logicheading', 'tool_automate'), 3);
        $logicform->display();
        $logiccol = ob_get_clean();
    }

    // Render the Actions column.
    ob_start();
    echo $OUTPUT->heading(get_string('actionheading', 'tool_automate'), 3);
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
    $opts = ['' => get_string('addaction', 'tool_automate')];
    foreach ($acttypes as $type => $class) {
        $opts[$type] = $class::get_name();
    }
    echo html_writer::select($opts, 'type', '', false);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
    ]);
    echo html_writer::end_tag('form');
    $actioncol = ob_get_clean();

    // The page reads: Conditions -> Logic -> Actions. Three even columns
    // when a rule has 2+ conditions, otherwise the logic column is empty
    // and the other two split half and half. Wraps to a single column on
    // narrow screens via Bootstrap's responsive grid.
    $colwidth = $showlogic ? 'col-md-4' : 'col-md-6';
    echo html_writer::start_tag('div', ['class' => 'row tool_automate_rule_flow']);
    echo html_writer::tag('div', $condcol, ['class' => $colwidth]);
    if ($showlogic) {
        echo html_writer::tag('div', $logiccol, ['class' => $colwidth]);
    }
    echo html_writer::tag('div', $actioncol, ['class' => $colwidth]);
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
