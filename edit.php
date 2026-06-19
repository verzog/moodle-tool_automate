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
 * Add/edit a rule and its attached conditions and actions. The page
 * presents the new-rule sequence in order:
 *   1. Name
 *   2. Description
 *   3. Subject (find users who... / choose courses that...) and conditions
 *   4. Actions
 *   5. When should this run (trigger).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\form\rule_form;
use tool_automate\form\logic_form;
use tool_automate\form\trigger_form;
use tool_automate\manager;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url(new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]));

$rule = null;
$conditions = [];
$actions = [];
$rulesubject = 'user';
if ($id) {
    $rule = $DB->get_record('tool_automate_rule', ['id' => $id], '*', MUST_EXIST);
    $conditions = $DB->get_records('tool_automate_condition', ['ruleid' => $id], 'sortorder, id');
    $actions = $DB->get_records('tool_automate_action', ['ruleid' => $id], 'sortorder, id');
    $rulesubject = $rule->subject ?? 'user';
}

$lockedsubject = $id && (count($conditions) > 0 || count($actions) > 0);
$mform = new rule_form(null, ['lockedsubject' => $lockedsubject]);
$logicform = $id ? new logic_form() : null;
$triggerform = $id ? new trigger_form(null, ['subject' => $rulesubject]) : null;

if ($rule) {
    $mform->set_data((array) $rule);
    $logicform->set_data([
        'ruleid'     => $rule->id,
        'logic'      => $rule->logic ?? 'all',
        'expression' => $rule->expression ?? '',
    ]);
    $triggerform->set_data([
        'ruleid'      => $rule->id,
        'triggertype' => $rule->triggertype,
        'eventname'   => $rule->eventname,
        'courseid'    => $rule->courseid,
        'roleid'      => $rule->roleid,
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

// Trigger-only submit: update when-should-this-run fields.
if ($triggerform && ($triggerdata = $triggerform->get_data()) && !empty($triggerdata->updatetrigger)) {
    $isevent = $triggerdata->triggertype === 'event';
    $eventname = $isevent ? ($triggerdata->eventname ?? null) : null;
    $DB->update_record('tool_automate_rule', (object) [
        'id'           => (int) $triggerdata->ruleid,
        'triggertype'  => $triggerdata->triggertype,
        'eventname'    => $eventname,
        'courseid'     => ($isevent && $eventname === '\\core\\event\\course_completed')
            ? (int) ($triggerdata->courseid ?? 0) : 0,
        'roleid'       => ($isevent && $eventname === '\\core\\event\\role_assigned')
            ? (int) ($triggerdata->roleid ?? 0) : 0,
        'usermodified' => $USER->id,
        'timemodified' => time(),
    ]);
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => (int) $triggerdata->ruleid]));
}

if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if ($formdata = $mform->get_data()) {
    $now = time();
    $record = (object) [
        'name'         => $formdata->name,
        'description'  => $formdata->description ?? '',
        'subject'      => $lockedsubject ? $rulesubject : ($formdata->subject ?? 'user'),
        'enabled'      => $formdata->enabled,
        'usermodified' => $USER->id,
        'timemodified' => $now,
    ];
    if ($formdata->id) {
        $record->id = $formdata->id;
        // If the subject changed, the previously-saved trigger may no
        // longer make sense - e.g. a user_created event rule switched to
        // subject=course would still be picked up by the user-event
        // observer, which would then hand a user id to the course
        // engine. Reset to manual so the admin explicitly chooses a
        // valid trigger for the new subject.
        $oldsubject = $rule->subject ?? 'user';
        if ($record->subject !== $oldsubject) {
            $record->triggertype = 'manual';
            $record->eventname = null;
            $record->courseid = 0;
            $record->roleid = 0;
        }
        $DB->update_record('tool_automate_rule', $record);
        $ruleid = $record->id;
    } else {
        $record->timecreated = $now;
        $record->logic = 'all';
        $record->triggertype = 'manual';
        $ruleid = $DB->insert_record('tool_automate_rule', $record);
    }
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => $ruleid]));
}

echo $OUTPUT->header();

// Step 1-3: Rule metadata + subject picker.
echo $OUTPUT->heading(get_string('step_rule', 'tool_automate'), 3);
$mform->display();

if ($id) {
    $condtypes = manager::get_condition_types_for_subject($rulesubject);
    $acttypes = manager::get_action_types_for_subject($rulesubject);
    $showlogic = count($conditions) >= 2;

    // Step 3 continued: Conditions for the chosen subject.
    $heading = $rulesubject === 'course'
        ? get_string('conditionheading_course', 'tool_automate')
        : get_string('conditionheading_user', 'tool_automate');
    echo $OUTPUT->heading($heading, 3);

    if ($conditions) {
        $polaritylabels = [
            manager::POLARITY_MATCH    => get_string('polarity_match', 'tool_automate'),
            manager::POLARITY_NOTMATCH => get_string('polarity_notmatch', 'tool_automate'),
        ];
        $table = new html_table();
        $table->head = [
            get_string('label', 'tool_automate'),
            get_string('polarity', 'tool_automate'),
            get_string('conditiontype', 'tool_automate'),
            get_string('summary', 'tool_automate'),
            get_string('actions', 'tool_automate'),
        ];
        $allcondtypes = manager::get_condition_types();
        $i = 1;
        foreach ($conditions as $c) {
            $class = $allcondtypes[$c->type] ?? null;
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
            $polarity = $c->polarity ?? manager::POLARITY_MATCH;
            $table->data[] = [
                'c' . $i,
                $polaritylabels[$polarity] ?? $polarity,
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

    // Combine-conditions logic stays as an advanced disclosure once 2+ conditions exist.
    if ($showlogic) {
        echo html_writer::start_tag('details', ['class' => 'tool_automate_advanced mt-3']);
        echo html_writer::tag('summary', get_string('logicheading', 'tool_automate'));
        $logicform->display();
        echo html_writer::end_tag('details');
    }

    // Step 4: Actions.
    echo $OUTPUT->heading(get_string('actionheading', 'tool_automate'), 3);
    if ($actions) {
        $table = new html_table();
        $table->head = [
            get_string('actiontype', 'tool_automate'),
            get_string('summary', 'tool_automate'),
            get_string('actions', 'tool_automate'),
        ];
        $allacttypes = manager::get_action_types();
        foreach ($actions as $a) {
            $class = $allacttypes[$a->type] ?? null;
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

    // Step 5: Trigger - when should this run?
    echo $OUTPUT->heading(get_string('triggerheading', 'tool_automate'), 3);
    $triggerform->display();
}

echo $OUTPUT->footer();
