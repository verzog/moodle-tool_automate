<?php
// GPL v3 or later. @package tool_automate.

require(__DIR__ . '/../../../config.php');

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
    echo $OUTPUT->confirm(get_string('confirmdelete', 'tool_automate', $rule->name), $yesurl, $baseurl);
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();

echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/automate/edit.php'),
    get_string('newrule', 'tool_automate'),
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
        $previewurl = new moodle_url('/admin/tool/automate/run.php',
            ['id' => $rule->id, 'dryrun' => 1, 'sesskey' => sesskey()]);
        $runurl = new moodle_url('/admin/tool/automate/run.php',
            ['id' => $rule->id, 'dryrun' => 0, 'sesskey' => sesskey()]);
        $deleteurl = new moodle_url($baseurl, ['delete' => $rule->id, 'sesskey' => sesskey()]);

        $links = html_writer::link($editurl, get_string('edit', 'tool_automate')) . ' | ' .
                 html_writer::link($previewurl, get_string('preview', 'tool_automate')) . ' | ' .
                 html_writer::link($runurl, get_string('runnow', 'tool_automate')) . ' | ' .
                 html_writer::link($deleteurl, get_string('delete', 'tool_automate'));

        $table->data[] = [
            format_string($rule->name),
            get_string('trigger_' . $rule->triggertype, 'tool_automate'),
            $rule->enabled ? get_string('yes') : get_string('no'),
            $links,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
