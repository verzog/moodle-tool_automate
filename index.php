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
 * Rule list and delete handling.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_automate');
$context = context_system::instance();
require_capability('tool/automate:manage', $context);

$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('rules', 'tool_automate'));
$PAGE->set_heading(get_string('rules', 'tool_automate'));

if ($delete && confirm_sesskey()) {
    $rule = $DB->get_record('tool_automate_rule', ['id' => $delete], '*', MUST_EXIST);
    if ($confirm) {
        $DB->delete_records('tool_automate_condition', ['ruleid' => $rule->id]);
        $DB->delete_records('tool_automate_action', ['ruleid' => $rule->id]);
        $DB->delete_records('tool_automate_log', ['ruleid' => $rule->id]);
        $DB->delete_records('tool_automate_rule', ['id' => $rule->id]);
        redirect($baseurl);
    }
    echo $OUTPUT->header();
    $yesurl = new moodle_url($baseurl, ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->confirm(get_string('confirmdelete', 'tool_automate', format_string($rule->name)), $yesurl, $baseurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();

// One-shot toast driven by URL params so it survives both the
// natural-redirect path and the inline-AJAX path on edit.php (where
// the fetch interceptor follows the off-page redirect, consuming
// any session notification before the browser actually navigates).
// The tiny JS below strips the params via history.replaceState
// after first paint so reloading the overview doesn't re-fire the
// toast.
$automatemsg = optional_param('automatemsg', '', PARAM_ALPHANUMEXT);
if ($automatemsg === 'triggersaved') {
    $rulename = optional_param('automatename', '', PARAM_TEXT);
    \core\notification::success(
        get_string('triggersaved', 'tool_automate', format_string($rulename))
    );
    $PAGE->requires->js_init_code(<<<'JS'
(function () {
    if (!window.history || !window.history.replaceState) { return; }
    var u = new URL(window.location.href);
    if (!u.searchParams.has('automatemsg')) { return; }
    u.searchParams.delete('automatemsg');
    u.searchParams.delete('automatename');
    window.history.replaceState({}, '', u.toString());
})();
JS
    );
}

echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/automate/edit.php'),
    get_string('newrule', 'tool_automate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/automate/log.php'),
    get_string('runhistory', 'tool_automate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/automate/report.php'),
    get_string('savedreports', 'tool_automate'),
    'get'
);
echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/automate/restore.php'),
    get_string('restoretitle', 'tool_automate'),
    'get'
);

$rules = $DB->get_records('tool_automate_rule', null, 'name');
if (!$rules) {
    echo $OUTPUT->notification(get_string('norules', 'tool_automate'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('name', 'tool_automate'),
        get_string('trigger', 'tool_automate'),
        get_string('enabled', 'tool_automate'),
        get_string('actions', 'tool_automate'),
    ];
    foreach ($rules as $rule) {
        $editurl = new moodle_url('/admin/tool/automate/edit.php', ['id' => $rule->id]);
        $previewurl = new moodle_url(
            '/admin/tool/automate/run.php',
            ['id' => $rule->id, 'dryrun' => 1, 'sesskey' => sesskey()]
        );
        $runurl = new moodle_url(
            '/admin/tool/automate/run.php',
            ['id' => $rule->id, 'dryrun' => 0, 'sesskey' => sesskey()]
        );
        $deleteurl = new moodle_url($baseurl, ['delete' => $rule->id, 'sesskey' => sesskey()]);

        // Render Preview / Run now as buttons so they stand out from the
        // edit/delete text links - admins kept missing them when they
        // were styled the same as the rest of the row.
        $btn = function ($url, $label, $variant) {
            return html_writer::link($url, $label, [
                'class' => 'btn btn-sm btn-' . $variant . ' me-1',
            ]);
        };
        $links = $btn($previewurl, get_string('preview', 'tool_automate'), 'secondary')
            . $btn($runurl, get_string('runnow', 'tool_automate'), 'primary')
            . html_writer::link($editurl, get_string('edit', 'tool_automate'))
            . ' | '
            . html_writer::link($deleteurl, get_string('delete', 'tool_automate'));

        $triggerlabel = ($rule->triggertype ?? '') === ''
            ? get_string('trigger_none', 'tool_automate')
            : get_string('trigger_' . $rule->triggertype, 'tool_automate');
        $table->data[] = [
            format_string($rule->name),
            $triggerlabel,
            $rule->enabled ? get_string('yes') : get_string('no'),
            $links,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
