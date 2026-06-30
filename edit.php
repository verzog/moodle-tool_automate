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
 * Single-page editor: name, description, subject, conditions, actions,
 * trigger. Each step has its own save button and posts back to this
 * page - there is no navigation away from the rule while building it.
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
use tool_automate\form\condition_form;
use tool_automate\form\action_form;
use tool_automate\manager;

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$addcondition = optional_param('addcondition', '', PARAM_ALPHANUMEXT);
$editcondition = optional_param('editcondition', 0, PARAM_INT);
$delcondition = optional_param('delcondition', 0, PARAM_INT);
$addaction = optional_param('addaction', '', PARAM_ALPHANUMEXT);
$editaction = optional_param('editaction', 0, PARAM_INT);
$delaction = optional_param('delaction', 0, PARAM_INT);
$inline = optional_param('inline', 0, PARAM_INT);

$baseurl = new moodle_url('/admin/tool/automate/index.php');
$selfurl = new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]);
$PAGE->set_url($selfurl);
$PAGE->add_body_class('tool_automate-page');

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

// Delete actions: confirm sesskey then redirect back to clean edit URL.
if ($id && $delcondition && confirm_sesskey()) {
    $DB->delete_records('tool_automate_condition', ['id' => $delcondition, 'ruleid' => $id]);
    redirect($selfurl);
}
if ($id && $delaction && confirm_sesskey()) {
    $DB->delete_records('tool_automate_action', ['id' => $delaction, 'ruleid' => $id]);
    redirect($selfurl);
}

$lockedsubject = $id && (count($conditions) > 0 || count($actions) > 0);
$mform = new rule_form(null, ['lockedsubject' => $lockedsubject]);
// Anchor the logic and trigger forms to $selfurl so their POST carries the
// rule id. With a null action, moodleform defaults to
// strip_querystring(qualified_me()), dropping the "?id=" - the submit would
// then land on edit.php with $id = 0, $triggerform / $logicform would be
// null, and the save + redirect-to-overview block would silently never run,
// re-rendering the editor instead. (The rule form survives a null action via
// its hidden id field; the condition / action forms set their own action URL.)
$logicform = $id ? new logic_form($selfurl) : null;
$triggerform = $id ? new trigger_form($selfurl, ['subject' => $rulesubject]) : null;

// Inline condition form: existing edit, or fresh add for the chosen type.
$condclass = null;
$condtype = '';
$existingcondition = null;
if ($id && $editcondition) {
    $existingcondition = $DB->get_record(
        'tool_automate_condition',
        ['id' => $editcondition, 'ruleid' => $id],
        '*',
        MUST_EXIST
    );
    $condtype = $existingcondition->type;
} else if ($id && $addcondition) {
    $condtype = $addcondition;
}
$condform = null;
if ($condtype) {
    $condtypes = manager::get_condition_types_for_subject($rulesubject);
    if (isset($condtypes[$condtype])) {
        $condclass = $condtypes[$condtype];
        // Anchor the form's POST to a URL that carries the discriminator
        // (addcondition / editcondition) AND the rule id. Without the
        // discriminator on POST, edit.php would see neither and skip
        // the save path; without the rule id it loses the parent rule.
        $condformurl = new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]);
        if ($editcondition) {
            $condformurl->param('editcondition', $editcondition);
        } else {
            $condformurl->param('addcondition', $condtype);
        }
        $condform = new condition_form($condformurl, ['type' => $condtype]);
        if ($existingcondition) {
            $cfg = (array) json_decode($existingcondition->configdata ?? '{}', true);
            $defaults = $condclass::config_to_form_defaults($cfg);
            $defaults['condid'] = $existingcondition->id;
            $defaults['ruleid'] = $id;
            $defaults['type'] = $condtype;
            $defaults['polarity'] = $existingcondition->polarity ?? manager::POLARITY_MATCH;
            $defaults['updatecondition'] = 1;
            $condform->set_data($defaults);
        } else {
            $condform->set_data([
                'ruleid' => $id,
                'type' => $condtype,
                'polarity' => manager::POLARITY_MATCH,
                'updatecondition' => 1,
            ]);
        }
    }
}

// Inline action form: same shape as conditions.
$actclass = null;
$acttype = '';
$existingaction = null;
if ($id && $editaction) {
    $existingaction = $DB->get_record(
        'tool_automate_action',
        ['id' => $editaction, 'ruleid' => $id],
        '*',
        MUST_EXIST
    );
    $acttype = $existingaction->type;
} else if ($id && $addaction) {
    $acttype = $addaction;
}
$actform = null;
if ($acttype) {
    $acttypes = manager::get_action_types_for_subject($rulesubject);
    if (isset($acttypes[$acttype])) {
        $actclass = $acttypes[$acttype];
        // Same anchoring as condition_form above: keep both the rule id
        // and the addaction/editaction discriminator on the POST URL so
        // edit.php can route the submit to the save path.
        $actformurl = new moodle_url('/admin/tool/automate/edit.php', ['id' => $id]);
        if ($editaction) {
            $actformurl->param('editaction', $editaction);
        } else {
            $actformurl->param('addaction', $acttype);
        }
        $actform = new action_form($actformurl, ['type' => $acttype]);
        if ($existingaction) {
            $cfg = (array) json_decode($existingaction->configdata ?? '{}', true);
            $defaults = $actclass::config_to_form_defaults($cfg);
            $defaults['actid'] = $existingaction->id;
            $defaults['ruleid'] = $id;
            $defaults['type'] = $acttype;
            $defaults['updateaction'] = 1;
            $actform->set_data($defaults);
        } else {
            $actform->set_data([
                'ruleid' => $id,
                'type' => $acttype,
                'updateaction' => 1,
            ]);
        }
    }
}

if ($rule) {
    $mform->set_data((array) $rule);
    $logicform->set_data([
        'ruleid'     => $rule->id,
        'logic'      => $rule->logic ?? 'all',
        'expression' => $rule->expression ?? '',
    ]);
    $triggerform->set_data([
        'ruleid'       => $rule->id,
        'triggertype'  => $rule->triggertype,
        'eventname'    => $rule->eventname,
        'courseid'     => $rule->courseid,
        'roleid'       => $rule->roleid,
        'schedule'     => $rule->schedule ?? 'hourly',
        'scheduledate' => (int) ($rule->scheduledate ?? 0) ?: time(),
    ]);
}

// Cancel on an inline condition / action form returns to the clean
// rule URL so the conditions / actions section re-renders with its
// picker instead of the half-filled form.
if ($condform && $condform->is_cancelled()) {
    redirect($selfurl);
}
if ($actform && $actform->is_cancelled()) {
    redirect($selfurl);
}

// Inline condition submit.
if ($condform && ($conddata = $condform->get_data()) && !empty($conddata->updatecondition)) {
    $config = $condclass::extract_config($conddata);
    $polarity = ($conddata->polarity ?? manager::POLARITY_MATCH) === manager::POLARITY_NOTMATCH
        ? manager::POLARITY_NOTMATCH
        : manager::POLARITY_MATCH;
    if ($existingcondition) {
        $existingcondition->configdata = json_encode($config);
        $existingcondition->polarity = $polarity;
        $DB->update_record('tool_automate_condition', $existingcondition);
    } else {
        $maxsort = (int) $DB->get_field(
            'tool_automate_condition',
            'COALESCE(MAX(sortorder), -1)',
            ['ruleid' => $id]
        );
        $DB->insert_record('tool_automate_condition', (object) [
            'ruleid'     => $id,
            'type'       => $condtype,
            'polarity'   => $polarity,
            'configdata' => json_encode($config),
            'sortorder'  => $maxsort + 1,
        ]);
    }
    redirect($selfurl);
}

// Inline action submit.
if ($actform && ($actdata = $actform->get_data()) && !empty($actdata->updateaction)) {
    $config = $actclass::extract_config($actdata);
    if ($existingaction) {
        $existingaction->configdata = json_encode($config);
        $DB->update_record('tool_automate_action', $existingaction);
    } else {
        $maxsort = (int) $DB->get_field(
            'tool_automate_action',
            'COALESCE(MAX(sortorder), -1)',
            ['ruleid' => $id]
        );
        $DB->insert_record('tool_automate_action', (object) [
            'ruleid'     => $id,
            'type'       => $acttype,
            'configdata' => json_encode($config),
            'sortorder'  => $maxsort + 1,
        ]);
    }
    redirect($selfurl);
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
    redirect($selfurl);
}

// Trigger-only submit: update when-should-this-run fields.
if ($triggerform && ($triggerdata = $triggerform->get_data()) && !empty($triggerdata->updatetrigger)) {
    $isevent = $triggerdata->triggertype === 'event';
    $iscron = $triggerdata->triggertype === 'cron';
    $eventname = $isevent ? ($triggerdata->eventname ?? null) : null;
    $schedule = $iscron ? ($triggerdata->schedule ?? 'hourly') : 'hourly';
    $scheduledate = ($iscron && $schedule === 'oncedate')
        ? (int) ($triggerdata->scheduledate ?? 0)
        : 0;
    $update = (object) [
        'id'           => (int) $triggerdata->ruleid,
        'triggertype'  => $triggerdata->triggertype,
        'eventname'    => $eventname,
        'courseid'     => ($isevent && $eventname === '\\core\\event\\course_completed')
            ? (int) ($triggerdata->courseid ?? 0) : 0,
        'roleid'       => ($isevent && $eventname === '\\core\\event\\role_assigned')
            ? (int) ($triggerdata->roleid ?? 0) : 0,
        'schedule'     => $schedule,
        'scheduledate' => $scheduledate,
        'usermodified' => $USER->id,
        'timemodified' => time(),
    ];
    // Reset the one-off run marker so a one-off date in the past fires
    // once after the admin commits to a new scheduledate. Only when the
    // date actually changes - re-saving the same oncedate trigger must
    // not refire a rule that has already run.
    if ($iscron && $schedule === 'oncedate') {
        $oldscheduledate = (int) ($rule->scheduledate ?? 0);
        if ($oldscheduledate !== $scheduledate) {
            $update->lastrunat = 0;
        }
    }
    $DB->update_record('tool_automate_rule', $update);
    // Saving the trigger is the last editing step - return the admin to
    // the rules overview, with a success toast confirming the save
    // landed. The trigger form submits as a normal full-page POST (the
    // inline fetch interceptor in this page's JS deliberately skips it,
    // since it can't reliably follow an off-page redirect), so the
    // browser follows this redirect natively. Encode the toast in URL
    // params rather than a one-shot session notification so it renders
    // reliably from the params on arrival at the overview.
    $tourl = new moodle_url($baseurl, [
        'automatemsg'  => 'triggersaved',
        'automatename' => (string) ($rule->name ?? ''),
    ]);
    redirect($tourl);
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
        $oldsubject = $rule->subject ?? 'user';
        if ($record->subject !== $oldsubject) {
            // Subject changed - the old trigger may not make sense, so
            // clear it back to the unset "Choose..." state and make the
            // admin re-pick a valid trigger for the new subject.
            $record->triggertype = '';
            $record->eventname = null;
            $record->courseid = 0;
            $record->roleid = 0;
        }
        $DB->update_record('tool_automate_rule', $record);
        $ruleid = $record->id;
    } else {
        // New rules start with no trigger selected so the editor opens
        // on "Choose..." and the admin must make a deliberate choice.
        $record->timecreated = $now;
        $record->logic = 'all';
        $record->triggertype = '';
        $record->schedule = 'hourly';
        $ruleid = $DB->insert_record('tool_automate_rule', $record);
    }
    redirect(new moodle_url('/admin/tool/automate/edit.php', ['id' => $ruleid]));
}

// Render the Step 3 conditions section (table, inline form if any, and
// picker) as a string so it can be sent either as part of the full edit
// page or as the AJAX payload that the inline JS swaps in on type pick
// or edit click.
$rendercondsection = function () use (
    $id,
    $selfurl,
    $rule,
    $conditions,
    $condform,
    $condclass,
    $logicform,
    $rulesubject,
    $OUTPUT
) {
    $condtypes = manager::get_condition_types_for_subject($rulesubject);
    ob_start();
    echo html_writer::start_tag('div', ['data-inline-target' => 'conditions']);

    $heading = $rulesubject === 'course'
        ? get_string('conditionheading_course', 'tool_automate')
        : get_string('conditionheading_user', 'tool_automate');
    echo $OUTPUT->heading($heading, 3);

    // Combinator picker: visible whenever there are 2+ conditions so
    // admins can flip between AND / OR / custom-expression in place,
    // without having to expand an "Advanced" disclosure to find it.
    $logic = $rule->logic ?? 'all';
    if ($logicform && count($conditions) >= 2) {
        echo html_writer::start_div('tool_automate_logic mb-2');
        $logicform->display();
        echo html_writer::end_div();
    }

    if ($conditions) {
        $polaritylabels = [
            manager::POLARITY_MATCH    => get_string('polarity_match', 'tool_automate'),
            manager::POLARITY_NOTMATCH => get_string('polarity_notmatch', 'tool_automate'),
        ];
        $connector = $logic === 'any'
            ? get_string('connector_or', 'tool_automate')
            : ($logic === 'expression'
                ? get_string('connector_expression', 'tool_automate')
                : get_string('connector_and', 'tool_automate'));
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
        $total = count($conditions);
        foreach ($conditions as $c) {
            $class = $allcondtypes[$c->type] ?? null;
            $config = (array) json_decode($c->configdata ?? '{}', true);
            $editurl = new moodle_url($selfurl, ['editcondition' => $c->id]);
            $deleteurl = new moodle_url($selfurl, [
                'delcondition' => $c->id, 'sesskey' => sesskey(),
            ]);
            $links = html_writer::link($editurl, get_string('edit', 'tool_automate'), [
                    'class' => 'tool_automate-inline-edit',
                ])
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
            // Add an AND / OR row between every pair of conditions so
            // the chosen combinator is visible inline next to the data
            // it applies to.
            if ($i < $total) {
                $cell = new html_table_cell(html_writer::tag('strong', $connector));
                $cell->colspan = count($table->head);
                $cell->attributes['class'] = 'tool_automate_connector text-center';
                $row = new html_table_row([$cell]);
                $table->data[] = $row;
            }
            $i++;
        }
        echo html_writer::table($table);
    } else {
        echo $OUTPUT->notification(get_string('noconditions', 'tool_automate'), 'info');
    }

    if ($condform) {
        echo $OUTPUT->heading($condclass::get_name(), 4);
        $condform->display();
    } else {
        echo html_writer::start_tag('form', [
            'action' => $selfurl->out_omit_querystring(),
            'method' => 'get',
            'class'  => 'tool_automate-picker',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        $opts = ['' => get_string('addcondition', 'tool_automate')];
        foreach ($condtypes as $type => $class) {
            $opts[$type] = $class::get_name();
        }
        echo html_writer::select($opts, 'addcondition', '', false);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
        ]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_tag('div');
    return ob_get_clean();
};

// Render the Step 4 actions section. Same shape as the conditions
// renderer above.
$renderactsection = function () use (
    $id,
    $selfurl,
    $actions,
    $actform,
    $actclass,
    $rulesubject,
    $OUTPUT
) {
    $acttypes = manager::get_action_types_for_subject($rulesubject);
    ob_start();
    echo html_writer::start_tag('div', ['data-inline-target' => 'actions']);
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
            $editurl = new moodle_url($selfurl, ['editaction' => $a->id]);
            $deleteurl = new moodle_url($selfurl, [
                'delaction' => $a->id, 'sesskey' => sesskey(),
            ]);
            $links = html_writer::link($editurl, get_string('edit', 'tool_automate'), [
                    'class' => 'tool_automate-inline-edit',
                ])
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

    if ($actform) {
        echo $OUTPUT->heading($actclass::get_name(), 4);
        $actform->display();
    } else {
        // Tell admins who lack the high-risk capability why destructive /
        // escalating actions (course delete, assign role) aren't in the
        // picker, rather than letting them silently vanish.
        if (!has_capability('tool/automate:managehighrisk', context_system::instance())) {
            echo $OUTPUT->notification(
                get_string('highriskactionshidden', 'tool_automate'),
                'info'
            );
        }
        echo html_writer::start_tag('form', [
            'action' => $selfurl->out_omit_querystring(),
            'method' => 'get',
            'class'  => 'tool_automate-picker',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
        $opts = ['' => get_string('addaction', 'tool_automate')];
        foreach ($acttypes as $type => $class) {
            $opts[$type] = $class::get_name();
        }
        echo html_writer::select($opts, 'addaction', '', false);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => get_string('add', 'tool_automate'),
        ]);
        echo html_writer::end_tag('form');
    }

    echo html_writer::end_tag('div');
    return ob_get_clean();
};

// Inline AJAX response: emit only the requested section, plus the JS
// requirements the moodleform registered (editor inits, hideIf wiring,
// etc.) so widgets in the inline-loaded form initialise correctly.
if ($id && $inline) {
    $section = ($addcondition !== '' || $editcondition) ? 'conditions'
        : (($addaction !== '' || $editaction) ? 'actions' : '');
    if ($section === 'conditions') {
        echo $rendercondsection();
        echo $PAGE->requires->get_end_code();
        exit;
    }
    if ($section === 'actions') {
        echo $renderactsection();
        echo $PAGE->requires->get_end_code();
        exit;
    }
}

echo $OUTPUT->header();

echo html_writer::link($baseurl, get_string('back', 'tool_automate'), [
    'class' => 'tool_automate_back',
]);

// Step 1-3: Rule metadata + subject picker.
echo $OUTPUT->heading(get_string('step_rule', 'tool_automate'), 3);
$mform->display();

if ($id) {
    echo $rendercondsection();

    echo $renderactsection();

    // Step 5: Trigger - when should this run?
    echo html_writer::start_tag('div', ['data-inline-target' => 'trigger']);
    echo $OUTPUT->heading(get_string('triggerheading', 'tool_automate'), 3);
    $triggerform->display();
    echo html_writer::end_tag('div');

    // Inline editor: conditional Step-5 fields, the Match/expression
    // toggle, and AJAX fragment swaps for the inline picker / edit
    // forms. Implemented as an AMD module, delegated on document so the
    // bindings survive the section swaps.
    $PAGE->requires->js_call_amd('tool_automate/editor', 'init');
}

echo $OUTPUT->footer();
