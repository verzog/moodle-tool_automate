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
 * Run history viewer.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/tablelib.php');

admin_externalpage_setup('tool_automate');
require_capability('tool/automate:manage', context_system::instance());

$ruleid = optional_param('ruleid', 0, PARAM_INT);

$baseurl = new moodle_url('/admin/tool/automate/log.php', ['ruleid' => $ruleid]);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('runhistory', 'tool_automate'));
$PAGE->set_heading(get_string('runhistory', 'tool_automate'));

echo $OUTPUT->header();

echo html_writer::link(
    new moodle_url('/admin/tool/automate/index.php'),
    get_string('back', 'tool_automate'),
    ['class' => 'tool_automate_back']
);

$rules = $DB->get_records_menu('tool_automate_rule', null, 'name', 'id, name');
$options = [0 => get_string('allrules', 'tool_automate')] + $rules;
echo html_writer::start_tag('form', [
    'method' => 'get', 'action' => $baseurl->out_omit_querystring(),
]);
echo html_writer::label(get_string('rule', 'tool_automate'), 'ruleid');
echo ' ';
echo html_writer::select($options, 'ruleid', $ruleid, false);
echo ' ';
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => get_string('filter', 'tool_automate'),
]);
echo html_writer::end_tag('form');

$table = new flexible_table('tool_automate_log');
$table->define_columns(['timecreated', 'rulename', 'fullname', 'dryrun', 'outcome', 'message']);
$table->define_headers([
    get_string('when', 'tool_automate'),
    get_string('rule', 'tool_automate'),
    get_string('user'),
    get_string('mode', 'tool_automate'),
    get_string('outcome', 'tool_automate'),
    get_string('message', 'tool_automate'),
]);
$table->define_baseurl($baseurl);
$table->sortable(true, 'timecreated', SORT_DESC);
$table->no_sorting('message');
$table->pageable(true);
$table->setup();

$where = '1=1';
$params = [];
if ($ruleid) {
    $where .= ' AND l.ruleid = :ruleid';
    $params['ruleid'] = $ruleid;
}

$count = $DB->count_records_sql("SELECT COUNT(*) FROM {tool_automate_log} l WHERE $where", $params);
$table->pagesize(50, $count);

$sort = $table->get_sql_sort() ?: 'l.timecreated DESC';
$sql = "SELECT l.*, r.name AS rulename,
               u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,
               u.middlename, u.alternatename
          FROM {tool_automate_log} l
          JOIN {tool_automate_rule} r ON r.id = l.ruleid
     LEFT JOIN {user} u ON u.id = l.userid
         WHERE $where
      ORDER BY $sort";
$rows = $DB->get_records_sql($sql, $params, $table->get_page_start(), $table->get_page_size());

foreach ($rows as $row) {
    $fullname = $row->userid ? fullname($row) : '-';
    $table->add_data([
        userdate($row->timecreated),
        s($row->rulename),
        $fullname,
        $row->dryrun
            ? get_string('preview', 'tool_automate')
            : get_string('live', 'tool_automate'),
        s($row->outcome),
        s($row->message ?? ''),
    ]);
}
$table->finish_output();

echo $OUTPUT->footer();
