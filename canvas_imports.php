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
 * List the Canvas Uplifter import jobs this admin has queued (their history).
 *
 * The main use is the "Analyse & stage for later" flow: each staged package is a
 * Canvas Uplifter analyse job, and this page links each done analyse job to its
 * Canvas Uplifter status page, where the conversion report and a "Build this
 * course" action live. Build jobs link to the created course.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\canvas_repository;

$context = context_system::instance();
$baseurl = new moodle_url('/admin/tool/automate/canvas_imports.php');
$importurl = new moodle_url('/admin/tool/automate/canvas_import.php');
$indexurl = new moodle_url('/admin/tool/automate/index.php');

// Soft integration: the tree node only exists when Canvas Uplifter is installed,
// so a direct hit lands here. Handle it without fataling.
if (!canvas_repository::is_available()) {
    require_login();
    require_capability('tool/automate:manage', $context);
    $PAGE->set_context($context);
    $PAGE->set_url($baseurl);
    $PAGE->set_title(get_string('canvasimportstitle', 'tool_automate'));
    $PAGE->set_heading(get_string('canvasimportstitle', 'tool_automate'));
    echo $OUTPUT->header();
    echo html_writer::link($indexurl, get_string('back', 'tool_automate'), ['class' => 'tool_automate_back']);
    echo $OUTPUT->notification(get_string('canvasunavailable', 'tool_automate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

admin_externalpage_setup('tool_automate_canvas_imports');
require_capability('tool/automate:manage', $context);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('canvasimportstitle', 'tool_automate'));
$PAGE->set_heading(get_string('canvasimportstitle', 'tool_automate'));
$PAGE->add_body_class('tool_automate-page');

echo $OUTPUT->header();
echo html_writer::link($importurl, get_string('canvasimportsback', 'tool_automate'), ['class' => 'tool_automate_back']);

echo html_writer::div(get_string('canvasimportsintro', 'tool_automate'), 'text-muted mb-3');

// Only this admin's own jobs are shown: the Canvas Uplifter status/build page
// only lets the queueing user act on a job, so listing others' would just link
// to pages they cannot use.
$jobs = canvas_repository::list_jobs((int) $USER->id);

if (!canvas_repository::jobs_listable()) {
    // Canvas Uplifter is installed but predates job listing.
    echo $OUTPUT->notification(get_string('canvasimportsunsupported', 'tool_automate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if (!$jobs) {
    echo $OUTPUT->notification(get_string('canvasimportsnone', 'tool_automate'), 'info');
    echo $OUTPUT->single_button($importurl, get_string('canvastitle', 'tool_automate'), 'get');
    echo $OUTPUT->footer();
    exit;
}

// Short labels for a job's kind and status. These mirror Canvas Uplifter's
// job_manager KIND_*/STATUS_* constant values (a documented contract) so the
// table reads without depending on Canvas Uplifter's own language strings.
$kindlabels = [
    'analyse' => get_string('canvasmode_analyse', 'tool_automate'),
    'build' => get_string('canvasmode_build', 'tool_automate'),
];
$statuslabels = [
    'queued' => get_string('canvasjobstatus_queued', 'tool_automate'),
    'running' => get_string('canvasjobstatus_running', 'tool_automate'),
    'done' => get_string('canvasjobstatus_done', 'tool_automate'),
    'failed' => get_string('canvasjobstatus_failed', 'tool_automate'),
];

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('canvasimportscolsource', 'tool_automate'),
    get_string('canvasimportscoltype', 'tool_automate'),
    get_string('canvasimportscolstatus', 'tool_automate'),
    get_string('canvasimportscolcreated', 'tool_automate'),
    get_string('canvasimportscolaction', 'tool_automate'),
];

foreach ($jobs as $job) {
    // Source: the remote URL if this was a URL import, otherwise a stored upload.
    if (!empty($job->packageurl)) {
        $sourcecell = html_writer::tag('span', s(shorten_text((string) $job->packageurl, 70)), [
            'title' => s((string) $job->packageurl),
        ]);
    } else {
        $sourcecell = get_string('canvasimportsuploaded', 'tool_automate');
    }

    $kind = (string) $job->kind;
    $status = (string) $job->status;
    $kindlabel = $kindlabels[$kind] ?? s($kind);
    $statuslabel = $statuslabels[$status] ?? s($status);

    // Action: send the admin where they can act on this job.
    $statusurl = new moodle_url('/admin/tool/canvasuplifter/status.php', ['jobid' => $job->id]);
    if ($kind === 'analyse' && $status === 'done') {
        // The status page shows the report and the "Build this course" button.
        $action = html_writer::link($statusurl, get_string('canvasimportsreview', 'tool_automate'));
    } else if ($kind === 'build' && $status === 'done' && !empty($job->courseid)) {
        $courseurl = new moodle_url('/course/view.php', ['id' => $job->courseid]);
        $action = html_writer::link($courseurl, get_string('canvasimportsviewcourse', 'tool_automate'));
    } else {
        $action = html_writer::link($statusurl, get_string('canvasimportsviewstatus', 'tool_automate'));
    }

    $table->data[] = [
        $sourcecell,
        $kindlabel,
        $statuslabel,
        userdate((int) $job->timecreated),
        $action,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
