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
 * course" action live. Build jobs link to the created course. Finished imports
 * can be selectively deleted to free the stored package storage.
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

// Process a delete before any output, so a refresh cannot re-run it. Each ticked
// job is deleted (its stored package freed) only if it belongs to this user; a
// built course is left in place.
if (optional_param('delete', 0, PARAM_INT) && confirm_sesskey()) {
    $ids = optional_param_array('jobids', [], PARAM_INT);
    $deleted = 0;
    foreach ($ids as $id) {
        if (canvas_repository::delete_job((int) $id, (int) $USER->id)) {
            $deleted++;
        }
    }
    redirect(
        $baseurl,
        get_string('canvasimportsdeleted', 'tool_automate', $deleted),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo html_writer::link($importurl, get_string('canvasimportsback', 'tool_automate'), ['class' => 'tool_automate_back']);

echo html_writer::div(get_string('canvasimportsintro', 'tool_automate'), 'text-muted mb-3');

if (!canvas_repository::jobs_listable()) {
    // Canvas Uplifter is installed but predates job listing.
    echo $OUTPUT->notification(get_string('canvasimportsunsupported', 'tool_automate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

// Storage counter: how much space this user's stored packages occupy.
$storagebytes = canvas_repository::storage_used((int) $USER->id);
if ($storagebytes !== null) {
    echo html_writer::div(
        get_string('canvasimportsstorage', 'tool_automate', display_size($storagebytes)),
        'tool_automate_sourcedir mb-3'
    );
}

// Only this admin's own jobs are shown: the Canvas Uplifter status/build page
// only lets the queueing user act on a job, so listing others' would just link
// to pages they cannot use. Cap the rows so a site that has run the bulk flow
// many times cannot turn this into an unbounded query and page render; fetch one
// extra to detect that there are more.
$maxrows = 200;
$jobs = canvas_repository::list_jobs((int) $USER->id, $maxrows + 1);
$capped = count($jobs) > $maxrows;
if ($capped) {
    $jobs = array_slice($jobs, 0, $maxrows, true);
}

// Directory-sourced jobs have no packageurl, so resolve each stored package's
// filename (in one query) to show which source course a row is - otherwise they
// would all read "Uploaded package". URL jobs show the URL instead.
$fileids = [];
foreach ($jobs as $job) {
    if (empty($job->packageurl) && !empty($job->fileid)) {
        $fileids[(int) $job->fileid] = true;
    }
}
$filenames = [];
if ($fileids) {
    $records = $DB->get_records_list('files', 'id', array_keys($fileids), '', 'id, filename');
    foreach ($records as $rec) {
        $filenames[(int) $rec->id] = $rec->filename;
    }
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

// A tick-all control and a confirm on delete. Inline AMD only reads values and
// toggles checkboxes - it never writes markup from user data.
$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var selectall = document.getElementById('tool_automate_imports_selectall');
    var form = document.getElementById('tool_automate_imports_form');
    if (selectall && form) {
        selectall.addEventListener('change', function() {
            form.querySelectorAll('input.tool_automate_importcb').forEach(function(box) {
                box.checked = selectall.checked;
            });
        });
    }
    var del = document.getElementById('tool_automate_imports_delete');
    if (del && form) {
        del.addEventListener('click', function(e) {
            var any = form.querySelector('input.tool_automate_importcb:checked');
            if (!any || !window.confirm(del.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    }
});
JS);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $baseurl->out(false),
    'id' => 'tool_automate_imports_form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$selectall = html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'id' => 'tool_automate_imports_selectall',
    'aria-label' => get_string('canvasimportsselectall', 'tool_automate'),
]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    $selectall,
    get_string('canvasimportscolsource', 'tool_automate'),
    get_string('canvasimportscoltype', 'tool_automate'),
    get_string('canvasimportscolstatus', 'tool_automate'),
    get_string('canvasimportscolcreated', 'tool_automate'),
    get_string('canvasimportscolaction', 'tool_automate'),
];

foreach ($jobs as $job) {
    // Source: the remote URL if this was a URL import, otherwise the stored
    // package's filename, falling back to a generic label when it is unknown.
    if (!empty($job->packageurl)) {
        $sourcecell = html_writer::tag('span', s(shorten_text((string) $job->packageurl, 70)), [
            'title' => s((string) $job->packageurl),
        ]);
    } else {
        $filename = $filenames[(int) ($job->fileid ?? 0)] ?? '';
        $sourcecell = ($filename !== '' && $filename !== '.')
            ? s($filename)
            : get_string('canvasimportsuploaded', 'tool_automate');
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

    // Only finished jobs are deletable, so a delete never races an in-flight
    // conversion that is still reading the package.
    $deletable = $status === 'done' || $status === 'failed';
    $checkbox = $deletable
        ? html_writer::checkbox('jobids[]', (int) $job->id, false, '', [
            'class' => 'tool_automate_importcb',
            'aria-label' => get_string('canvasimportsselectrow', 'tool_automate'),
        ])
        : '';

    $table->data[] = [
        $checkbox,
        $sourcecell,
        $kindlabel,
        $statuslabel,
        userdate((int) $job->timecreated),
        $action,
    ];
}

if ($capped) {
    echo $OUTPUT->notification(get_string('canvasimportscapped', 'tool_automate', $maxrows), 'info');
}
echo html_writer::table($table);

echo html_writer::div(get_string('canvasimportsdeletehelp', 'tool_automate'), 'text-muted mb-2');
echo html_writer::tag(
    'button',
    get_string('canvasimportsdeleteselected', 'tool_automate'),
    [
        'type' => 'submit',
        'name' => 'delete',
        'value' => '1',
        'id' => 'tool_automate_imports_delete',
        'class' => 'btn btn-danger',
        'data-confirm' => get_string('canvasimportsdeleteconfirm', 'tool_automate'),
    ]
);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
