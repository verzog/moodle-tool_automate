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
 * Bulk restore course backups from the repository directory.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_automate_restore');
$context = context_system::instance();
require_capability('tool/automate:manage', $context);

$baseurl = new moodle_url('/admin/tool/automate/restore.php');
$indexurl = new moodle_url('/admin/tool/automate/index.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'tool_automate_settings']);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('restoretitle', 'tool_automate'));
$PAGE->set_heading(get_string('restoretitle', 'tool_automate'));
$PAGE->add_body_class('tool_automate-page');

$enabled = \tool_automate\restore_repository::is_enabled();
$sourcedir = \tool_automate\restore_repository::get_source_dir();
$dirok = $enabled && $sourcedir !== '' && is_dir($sourcedir) && is_readable($sourcedir);

// Process the selection before any output so a successful queue can
// redirect-after-POST (a refresh must not re-queue every selected restore).
$files = [];
$categories = [];
$preview = null;
$formerror = null;

if ($dirok) {
    $files = \tool_automate\restore_repository::list_backups($sourcedir);
    $categories = $DB->get_records_menu('course_categories', null, 'name', 'id, name');

    // The selection is a hand-rolled checkbox table (so it can show size and
    // date columns), not a moodleform, so detect its submit buttons directly.
    $cancel = optional_param('cancel', '', PARAM_RAW);
    $dopreview = optional_param('preview', '', PARAM_RAW) !== '';
    $doqueue = optional_param('queue', '', PARAM_RAW) !== '';

    if ($cancel !== '' || $dopreview || $doqueue) {
        require_sesskey();
        if ($cancel !== '') {
            redirect($indexurl);
        }

        $dryrun = !$doqueue;
        // Each checkbox value is a cleaning-proof hash of a basename; map each
        // back before resolving it to a path on disk.
        $tokens = optional_param_array('files', [], PARAM_ALPHANUM);
        $categoryid = optional_param('categoryid', 0, PARAM_INT);

        if (empty($tokens)) {
            $formerror = get_string('restoreselectone', 'tool_automate');
        } else if (!isset($categories[$categoryid])) {
            $formerror = get_string('restoreselectcategory', 'tool_automate');
        } else {
            $categoryname = $categories[$categoryid];
            $queued = [];
            $skipped = [];
            foreach ($tokens as $token) {
                $basename = \tool_automate\restore_repository::basename_for_token($token, $sourcedir);
                $resolved = $basename === null
                    ? null
                    : \tool_automate\restore_repository::resolve($basename, $sourcedir);
                if ($resolved === null) {
                    $skipped[] = $basename ?? $token;
                    continue;
                }
                if (!$dryrun) {
                    \tool_automate\restore_repository::queue($resolved, $categoryid, $USER->id);
                }
                $queued[] = $basename;
            }

            if (!$dryrun) {
                // Redirect-after-POST: a browser refresh of the rendered result
                // would otherwise resubmit and queue every restore again.
                $a = (object) [
                    'count'    => count($queued),
                    'category' => format_string($categoryname),
                ];
                $message = get_string('restorequeued', 'tool_automate', $a);
                if ($skipped) {
                    $message .= ' ' . get_string('restoreskipped', 'tool_automate', implode(', ', $skipped));
                }
                redirect($baseurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
            }

            // Dry-run preview: stash the outcome to render after the header.
            $preview = (object) [
                'queued'   => $queued,
                'skipped'  => $skipped,
                'category' => $categoryname,
            ];
        }
    }
}

echo $OUTPUT->header();
echo html_writer::link($indexurl, get_string('back', 'tool_automate'), ['class' => 'tool_automate_back']);

// Site-level kill-switch and prerequisite checks. Each is a dead end with a
// pointer to the setting that needs attention.
if (!$enabled) {
    echo $OUTPUT->notification(get_string('restoredisabled', 'tool_automate'), 'warning');
    echo html_writer::div(html_writer::link($settingsurl, get_string('settings', 'tool_automate')));
    echo $OUTPUT->footer();
    exit;
}
if ($sourcedir === '') {
    echo $OUTPUT->notification(get_string('restorenosourcedir', 'tool_automate'), 'warning');
    echo html_writer::div(html_writer::link($settingsurl, get_string('settings', 'tool_automate')));
    echo $OUTPUT->footer();
    exit;
}
if (!is_dir($sourcedir) || !is_readable($sourcedir)) {
    echo $OUTPUT->notification(get_string('restorebaddir', 'tool_automate', s($sourcedir)), 'error');
    echo html_writer::div(html_writer::link($settingsurl, get_string('settings', 'tool_automate')));
    echo $OUTPUT->footer();
    exit;
}

if ($formerror !== null) {
    echo $OUTPUT->notification($formerror, 'warning');
}

if ($preview !== null) {
    if ($preview->queued) {
        $a = (object) ['count' => count($preview->queued), 'category' => format_string($preview->category)];
        echo $OUTPUT->notification(get_string('restorewouldqueue', 'tool_automate', $a), 'info');
        echo html_writer::div(
            html_writer::alist(array_map('s', $preview->queued)),
            'tool_automate_restore_list mb-3'
        );
    }
    if ($preview->skipped) {
        echo $OUTPUT->notification(
            get_string('restoreskipped', 'tool_automate', html_writer::alist(array_map('s', $preview->skipped))),
            'warning'
        );
    }
}

// Source directory shown as a framed panel matching the listing tables: a
// tinted label strip above the resolved path so it reads as a labelled value,
// not a sentence.
echo html_writer::div(
    html_writer::div(get_string('restoresourcedir', 'tool_automate'), 'tool_automate_sourcedir_label')
        . html_writer::tag('code', s($sourcedir), ['class' => 'tool_automate_sourcedir_path']),
    'tool_automate_sourcedir mb-3'
);

if (empty($files)) {
    echo $OUTPUT->notification(get_string('restorenofiles', 'tool_automate'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Server-side search and row cap. The directory can hold thousands of .mbz
// files; rendering them all would bloat the page and the client-side filter
// could only ever reach the rows already in the DOM. So filter the full list
// by the submitted query and render at most $maxrows of the matches, letting
// the admin narrow with the search instead of scrolling.
$maxrows = 200;
$query = trim(optional_param('q', '', PARAM_RAW));
if ($query !== '') {
    $needle = core_text::strtolower($query);
    $matches = array_values(array_filter($files, function($name) use ($needle) {
        return core_text::strpos(core_text::strtolower($name), $needle) !== false;
    }));
} else {
    $matches = $files;
}
$totalmatches = count($matches);
$shownfiles = array_slice($matches, 0, $maxrows);

// A selection has to survive a server-side search: the admin may tick files
// under one query, search again, and tick more before queueing. Selected
// tokens ride along in the search request as sel[]; here we pre-tick any that
// land among the rendered rows and carry the rest as hidden inputs so they are
// still submitted even though they are no longer on screen.
$selectedtokens = optional_param_array('sel', [], PARAM_ALPHANUM);
$selectedset = array_flip($selectedtokens);
$showntokens = [];

// Build the checkbox table: one row per backup, the checkbox column being the
// selection control, plus size and modified-date detail a plain picker can't
// show.
$btable = new html_table();
$btable->attributes['id'] = 'tool_automate_backups_table';
$btable->attributes['class'] = 'generaltable tool_automate_backups_table';
$btable->head = [
    get_string('restorecolselect', 'tool_automate'),
    get_string('restorecolname', 'tool_automate'),
    get_string('restorecolsize', 'tool_automate'),
    get_string('restorecolmodified', 'tool_automate'),
];
foreach ($shownfiles as $basename) {
    $resolved = \tool_automate\restore_repository::resolve($basename, $sourcedir);
    $readable = $resolved !== null && is_readable($resolved);
    $token = \tool_automate\restore_repository::token($basename);
    $showntokens[$token] = true;

    $checkcell = new html_table_cell(
        html_writer::checkbox('files[]', $token, isset($selectedset[$token]), '', ['class' => 'tool_automate_backupcb'])
    );
    $checkcell->attributes['class'] = 'tool_automate_backupcheck';

    $namecell = new html_table_cell(s($basename));
    $namecell->attributes['class'] = 'tool_automate_backupname';

    $sizecell = new html_table_cell($readable ? display_size(filesize($resolved)) : '-');
    $sizecell->attributes['class'] = 'tool_automate_backupsize';

    $row = new html_table_row([
        $checkcell,
        $namecell,
        $sizecell,
        $readable ? userdate(filemtime($resolved)) : '-',
    ]);
    $row->attributes['data-name'] = $basename;
    $btable->data[] = $row;
}

// Search behaviour. Typing filters the rendered rows instantly (great for the
// common case where every match is already on screen); pressing Enter or the
// Search button submits a server-side search across the whole directory, which
// is what reaches matches beyond the rendered cap. Before that submit we copy
// the current selection (ticked checkboxes plus already-carried hidden inputs)
// into the search form as sel[] so nothing is lost on the round-trip. Inline
// AMD only reads values and toggles visibility - no markup from user data.
$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var input = document.getElementById('tool_automate_backupsearch');
    var table = document.getElementById('tool_automate_backups_table');
    var searchform = document.getElementById('tool_automate_searchform');
    var selectform = document.getElementById('tool_automate_selectform');
    if (table) {
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-name]'));
        var nomatch = document.getElementById('tool_automate_backups_nomatch');
        if (input) {
            input.addEventListener('input', function() {
                var needle = input.value.toLowerCase();
                var shown = 0;
                rows.forEach(function(row) {
                    var name = (row.getAttribute('data-name') || '').toLowerCase();
                    var match = name.indexOf(needle) !== -1;
                    row.style.display = match ? '' : 'none';
                    if (match) {
                        shown++;
                    }
                });
                if (nomatch) {
                    nomatch.style.display = shown === 0 ? '' : 'none';
                }
            });
        }
    }
    if (searchform && selectform) {
        searchform.addEventListener('submit', function() {
            Array.prototype.slice.call(searchform.querySelectorAll('input[data-sel]'))
                .forEach(function(node) { node.parentNode.removeChild(node); });
            var seen = {};
            Array.prototype.slice.call(selectform.querySelectorAll('input[name="files[]"]'))
                .forEach(function(cb) {
                    if ((cb.type === 'hidden' || cb.checked) && !seen[cb.value]) {
                        seen[cb.value] = true;
                        var hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'sel[]';
                        hidden.value = cb.value;
                        hidden.setAttribute('data-sel', '1');
                        searchform.appendChild(hidden);
                    }
                });
        });
    }
});
JS);

// Search form (GET): submitting reloads the page with the query so the filter
// runs server-side over every file, not just the rendered rows.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $baseurl->out(false),
    'id'     => 'tool_automate_searchform',
    'class'  => 'tool_automate_searchform',
]);
echo html_writer::label(
    get_string('restorefiles', 'tool_automate'),
    'tool_automate_backupsearch',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::start_div('tool_automate_searchrow mb-2');
echo html_writer::empty_tag('input', [
    'type'         => 'text',
    'name'         => 'q',
    'value'        => $query,
    'id'           => 'tool_automate_backupsearch',
    'class'        => 'tool_automate_backupsearch',
    'placeholder'  => get_string('restorefilessearch', 'tool_automate'),
    'autocomplete' => 'off',
]);
echo html_writer::tag(
    'button',
    get_string('restorefilessearchbtn', 'tool_automate'),
    ['type' => 'submit', 'class' => 'btn btn-secondary']
);
if ($query !== '') {
    echo html_writer::link($baseurl, get_string('restorefilesclear', 'tool_automate'), ['class' => 'btn btn-link']);
}
echo html_writer::end_div();
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $baseurl->out(false),
    'id'     => 'tool_automate_selectform',
    'class'  => 'tool_automate_restore_form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Carry selected files that the current search has pushed off-screen so they
// still submit with the queue.
foreach ($selectedtokens as $token) {
    if (!isset($showntokens[$token])) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'files[]', 'value' => $token]);
    }
}

if ($totalmatches > $maxrows) {
    $a = (object) ['shown' => count($shownfiles), 'total' => $totalmatches];
    echo $OUTPUT->notification(get_string('restorefilescapped', 'tool_automate', $a), 'info');
}

if (empty($shownfiles)) {
    // Server-side search returned nothing; the directory itself is not empty
    // (that case exits earlier), so prompt a different query rather than a table.
    echo $OUTPUT->notification(get_string('restorenomatch', 'tool_automate'), 'info');
} else {
    // Scroll the backups in a fixed-height pane with a sticky header so a large
    // result set stays a compact, scannable list; the live search filters the
    // rendered rows in place.
    echo html_writer::start_div('tool_automate_backups_scroll');
    echo html_writer::table($btable);
    echo html_writer::end_div();
    echo html_writer::div(
        get_string('restorenomatch', 'tool_automate'),
        'tool_automate_restore_nomatch',
        ['id' => 'tool_automate_backups_nomatch', 'style' => 'display:none;']
    );
}

echo html_writer::start_div('tool_automate_field tool_automate_categoryfield');
echo html_writer::label(
    get_string('restoretargetcategory', 'tool_automate'),
    'menucategoryid',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::select($categories, 'categoryid', '', false, [
    'id'    => 'menucategoryid',
    'class' => 'tool_automate_categoryselect',
]);
echo html_writer::end_div();

echo html_writer::start_div('tool_automate_restore_actions');
echo html_writer::tag(
    'button',
    get_string('restorepreview', 'tool_automate'),
    ['type' => 'submit', 'name' => 'preview', 'value' => '1', 'class' => 'btn btn-secondary']
);
echo html_writer::tag(
    'button',
    get_string('restorequeue', 'tool_automate'),
    ['type' => 'submit', 'name' => 'queue', 'value' => '1', 'class' => 'btn btn-primary']
);
echo html_writer::link($indexurl, get_string('cancel'), ['class' => 'btn btn-link']);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
