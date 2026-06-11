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

echo $OUTPUT->header();

if ($dryrun) {
    echo $OUTPUT->notification(get_string('dryrunnotice', 'tool_automate'), 'info');
}
echo html_writer::tag('p', get_string('matchedusers', 'tool_automate', count($results)));

if ($results) {
    $table = new html_table();
    $table->head = [
        get_string('user', 'tool_automate'),
        get_string('outcome', 'tool_automate'),
        get_string('message', 'tool_automate'),
    ];
    foreach ($results as $row) {
        $table->data[] = [s($row->fullname), s($row->outcome), s($row->message)];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->single_button($baseurl, get_string('back', 'tool_automate'), 'get');
echo $OUTPUT->footer();
