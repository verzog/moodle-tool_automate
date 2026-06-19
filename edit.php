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
$logicform = $id ? new logic_form() : null;
$triggerform = $id ? new trigger_form(null, ['subject' => $rulesubject]) : null;

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
    redirect($selfurl);
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
    $conditions,
    $condform,
    $condclass,
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
    $showlogic = count($conditions) >= 2;

    echo $rendercondsection();

    if ($showlogic) {
        echo html_writer::start_tag('details', ['class' => 'tool_automate_advanced mt-3']);
        echo html_writer::tag('summary', get_string('logicheading', 'tool_automate'));
        $logicform->display();
        echo html_writer::end_tag('details');
    }

    echo $renderactsection();

    // Step 5: Trigger - when should this run?
    echo html_writer::start_tag('div', ['data-inline-target' => 'trigger']);
    echo $OUTPUT->heading(get_string('triggerheading', 'tool_automate'), 3);
    $triggerform->display();
    echo html_writer::end_tag('div');

    // Inline editor: intercept picker submits and edit links, fetch the
    // section's HTML via inline=1, and swap it into the page without a
    // full reload. Uses event delegation on document so that the
    // bindings survive DOM replacement and don't depend on the
    // pickers/links existing yet when the init code first runs.
    $PAGE->requires->js_init_code(<<<'JS'
(function () {
    // Show or hide each Step 5 sub-field based on the trigger type and
    // schedule currently chosen. Done here rather than via the form's
    // hideIf so it survives the inline AJAX swaps below (after a swap the
    // moodleform's own dependency JS is no longer wired to the new DOM).
    var applyTrigger = function () {
        var c = document.querySelector('[data-inline-target="trigger"]');
        if (!c) { return; }
        var valueOf = function (id) {
            var el = c.querySelector('#' + id);
            return el ? el.value : '';
        };
        var tt = valueOf('id_triggertype');
        var sched = valueOf('id_schedule');
        var ev = valueOf('id_eventname');
        var showRow = function (name, on) {
            // Moodleform wraps each field's row in an element whose id
            // is "fitem_id_<name>" - target the row directly so this
            // works for compound widgets too (date_time_selector etc.)
            // where the field has no single matching .fitem child.
            var row = c.querySelector('#fitem_id_' + name);
            if (row) { row.style.display = on ? '' : 'none'; }
        };
        showRow('schedule', tt === 'cron');
        showRow('scheduledate', tt === 'cron' && sched === 'oncedate');
        showRow('eventname', tt === 'event');
        showRow('courseid', tt === 'event' && ev === '\\core\\event\\course_completed');
        showRow('roleid', tt === 'event' && ev === '\\core\\event\\role_assigned');
    };
    document.addEventListener('change', function (e) {
        if (e.target.closest && e.target.closest('[data-inline-target="trigger"]')) {
            applyTrigger();
        }
    });
    var fetchAndReplace = function (url, target) {
        url.searchParams.set('inline', '1');
        fetch(url.toString(), {credentials: 'same-origin'})
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = html;
                var rep = wrapper.querySelector(
                    '[data-inline-target="' + target.dataset.inlineTarget + '"]'
                );
                if (!rep) { return; }
                var scripts = Array.prototype.slice.call(
                    wrapper.querySelectorAll('script')
                );
                target.replaceWith(rep);
                scripts.forEach(function (n) {
                    var f = document.createElement('script');
                    if (n.src) { f.src = n.src; } else { f.textContent = n.textContent; }
                    document.head.appendChild(f);
                });
                applyTrigger();
            })
            .catch(function () {
                // Fall back to a full-page navigation, but to the
                // human-facing URL, not the inline=1 fragment endpoint.
                url.searchParams.delete('inline');
                window.location.href = url.toString();
            });
    };
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form.tool_automate-picker');
        if (!form) { return; }
        var sel = form.querySelector('select');
        if (!sel || !sel.value) { return; }
        var target = form.closest('[data-inline-target]');
        if (!target) { return; }
        e.preventDefault();
        var u = new URL(form.action, window.location.href);
        new FormData(form).forEach(function (v, k) { u.searchParams.set(k, v); });
        fetchAndReplace(u, target);
    }, true);
    document.addEventListener('click', function (e) {
        var a = e.target.closest('a.tool_automate-inline-edit');
        if (!a) { return; }
        var target = a.closest('[data-inline-target]');
        if (!target) { return; }
        e.preventDefault();
        fetchAndReplace(new URL(a.href, window.location.href), target);
    }, true);
    // Any other form submit inside a data-inline-target section (the
    // condition / action inline edit forms, the trigger form) gets
    // POSTed via fetch. The response is the full page after PHP's
    // redirect; we then swap every data-inline-target section from the
    // response into the live DOM so dependent areas (table refreshes,
    // condition count) stay consistent without a full page reload.
    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form');
        if (!form) { return; }
        if (form.classList.contains('tool_automate-picker')) { return; }
        var target = form.closest('[data-inline-target]');
        if (!target) { return; }
        e.preventDefault();
        var url = new URL(form.action, window.location.href);
        // Pass the submitter so the clicked button's name/value is
        // included; without it, PHP's $mform->is_cancelled() never sees
        // the cancel button and the save path runs instead.
        var fd = e.submitter
            ? new FormData(form, e.submitter)
            : new FormData(form);
        fetch(url.toString(), {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var scripts = [];
                doc.querySelectorAll('[data-inline-target]').forEach(function (rep) {
                    var name = rep.dataset.inlineTarget;
                    var live = document.querySelector('[data-inline-target="' + name + '"]');
                    if (!live) { return; }
                    Array.prototype.slice.call(rep.querySelectorAll('script'))
                        .forEach(function (s) { scripts.push(s); });
                    live.replaceWith(rep);
                });
                scripts.forEach(function (n) {
                    var f = document.createElement('script');
                    if (n.src) { f.src = n.src; } else { f.textContent = n.textContent; }
                    document.head.appendChild(f);
                });
                applyTrigger();
            })
            .catch(function () { form.submit(); });
    }, true);
    applyTrigger();
})();
JS
    );
}

echo $OUTPUT->footer();
