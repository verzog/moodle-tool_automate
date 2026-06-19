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
 * Run a rule now (with optional dry run) and show the results.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_sesskey();
admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$id = required_param('id', PARAM_INT);
$dryrun = optional_param('dryrun', 1, PARAM_BOOL);

$rule = $DB->get_record('tool_automate_rule', ['id' => $id], '*', MUST_EXIST);
$baseurl = new moodle_url('/admin/tool/automate/index.php');
$PAGE->set_url(new moodle_url('/admin/tool/automate/run.php', ['id' => $id]));
$PAGE->set_title(get_string('results', 'tool_automate'));
$PAGE->set_heading(get_string('resultsfor', 'tool_automate', format_string($rule->name)));

$results = \tool_automate\manager::run_rule($id, (bool) $dryrun);
$iscourse = ($rule->subject ?? 'user') === 'course';

echo $OUTPUT->header();

echo html_writer::link($baseurl, get_string('back', 'tool_automate'), [
    'class' => 'tool_automate_back',
]);

if ($dryrun) {
    echo $OUTPUT->notification(get_string('dryrunnotice', 'tool_automate'), 'info');
}

// Group action results by subject so each subject shows up once with the
// list of changes underneath. Finalise rows (e.g. the report URL emitted
// after a generate_report action) use a 0 id; keep those separate so
// they don't inflate the matched-subjects count or render under a
// blank table row.
$bysubject = [];
$finaliserows = [];
foreach ($results as $row) {
    if ((int) $row->userid === 0) {
        $finaliserows[] = $row;
        continue;
    }
    $bysubject[$row->userid]['fullname'] = $row->fullname;
    $bysubject[$row->userid]['changes'][] = $row;
}
$countstring = $iscourse ? 'matchedcourses' : 'matchedusers';
echo html_writer::tag('p', get_string($countstring, 'tool_automate', count($bysubject)));

if ($bysubject) {
    $table = new html_table();
    $table->head = [
        get_string($iscourse ? 'course' : 'user', 'tool_automate'),
        $dryrun
            ? get_string('plannedchanges', 'tool_automate')
            : get_string('changes', 'tool_automate'),
    ];
    $table->attributes['class'] = 'generaltable tool_automate_results';
    foreach ($bysubject as $entry) {
        $items = [];
        foreach ($entry['changes'] as $r) {
            $cls = $r->outcome === 'error' ? 'text-danger' : '';
            $items[] = html_writer::tag('li', s($r->message), ['class' => $cls]);
        }
        $list = html_writer::tag('ul', implode('', $items), ['class' => 'mb-0']);
        $table->data[] = [s($entry['fullname']), $list];
    }
    echo html_writer::table($table);
}

foreach ($finaliserows as $row) {
    $type = $row->outcome === 'error' ? 'error' : 'info';
    echo $OUTPUT->notification(s($row->message), $type);
    if (!empty($row->url)) {
        echo html_writer::div(
            html_writer::link($row->url, get_string('viewreport', 'tool_automate')),
            'tool_automate_viewreport mb-3'
        );
    }
}

echo $OUTPUT->single_button($baseurl, get_string('back', 'tool_automate'), 'get');
echo $OUTPUT->footer();
