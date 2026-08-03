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
 * Bulk import Canvas Common Cartridge (.imscc) packages via tool_canvasuplifter.
 *
 * Two sources: a pasted list of Canvas backup URLs, and/or a server directory of
 * .imscc packages. Two run modes: build a course now, or analyse (fetch + report)
 * for later manual build. Every package becomes a background Canvas Uplifter job.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_automate\canvas_repository;

$context = context_system::instance();
$baseurl = new moodle_url('/admin/tool/automate/canvas_import.php');
$indexurl = new moodle_url('/admin/tool/automate/index.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'tool_automate_settings']);

// The feature is a soft integration: without tool_canvasuplifter installed the
// admin-tree node is never added, so a direct hit lands here. Handle it with a
// minimal page rather than fataling in admin_externalpage_setup on an unknown id.
if (!canvas_repository::is_available()) {
    require_login();
    require_capability('tool/automate:manage', $context);
    $PAGE->set_context($context);
    $PAGE->set_url($baseurl);
    $PAGE->set_title(get_string('canvastitle', 'tool_automate'));
    $PAGE->set_heading(get_string('canvastitle', 'tool_automate'));
    echo $OUTPUT->header();
    echo html_writer::link($indexurl, get_string('back', 'tool_automate'), ['class' => 'tool_automate_back']);
    echo $OUTPUT->notification(get_string('canvasunavailable', 'tool_automate'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

admin_externalpage_setup('tool_automate_canvas');
require_capability('tool/automate:manage', $context);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('canvastitle', 'tool_automate'));
$PAGE->set_heading(get_string('canvastitle', 'tool_automate'));
$PAGE->add_body_class('tool_automate-page');

$enabled = canvas_repository::is_enabled();
$sourcedir = canvas_repository::get_source_dir();
$dirok = $sourcedir !== '' && is_dir($sourcedir) && is_readable($sourcedir);

// At most this many directory rows are rendered; the client-side box filters
// them live. The URL list has no cap, so a very large migration can lean on it
// (or stage the directory in batches).
$maxrows = 1000;

$categories = $DB->get_records_menu('course_categories', null, 'name', 'id, name');
$files = $dirok ? canvas_repository::list_packages($sourcedir) : [];

// Build a token => basename map once from the directory listing (a single scan),
// so resolving many ticked packages never rescans, stats and sorts the whole
// directory per token - which would be quadratic work under a select-all.
$tokenmap = [];
foreach ($files as $basename) {
    $tokenmap[canvas_repository::token($basename)] = $basename;
}

// Remembered field values, so a preview re-render keeps everything the admin
// entered - including the ticked directory packages.
$urlsvalue = optional_param('urls', '', PARAM_RAW);
$selectedmode = canvas_repository::normalise_mode(optional_param('mode', canvas_repository::MODE_ANALYSE, PARAM_ALPHA));
$selectedcategory = optional_param('categoryid', 0, PARAM_INT);
$selectedquiz = optional_param('quizfrombank', 0, PARAM_INT) ? true : false;
$grouping = optional_param('pagegrouping', '', PARAM_ALPHA);
$selectedgrouping = in_array($grouping, ['book', 'lesson'], true) ? $grouping : '';
$submittedtokens = optional_param_array('files', [], PARAM_ALPHANUM);

$preview = null;
$formerror = null;

// Process the selection before any output so a successful queue can
// redirect-after-POST (a refresh must not re-queue every selected import).
if ($enabled) {
    $cancel = optional_param('cancel', '', PARAM_RAW);
    $dopreview = optional_param('preview', '', PARAM_RAW) !== '';
    $doqueue = optional_param('queue', '', PARAM_RAW) !== '';

    if ($cancel !== '' || $dopreview || $doqueue) {
        require_sesskey();
        if ($cancel !== '') {
            redirect($indexurl);
        }

        $dryrun = !$doqueue;
        $urls = canvas_repository::parse_urls($urlsvalue);

        // Resolve ticked tokens to basenames through the single scan above.
        $selectedbasenames = [];
        foreach ($submittedtokens as $token) {
            if (isset($tokenmap[$token])) {
                $selectedbasenames[$token] = $tokenmap[$token];
            }
        }

        $buildwanted = $selectedmode === canvas_repository::MODE_BUILD;
        $cancreate = isset($categories[$selectedcategory])
            && has_capability('moodle/course:create', context_coursecat::instance($selectedcategory));

        if (empty($selectedbasenames) && empty($urls)) {
            $formerror = get_string('canvasselectone', 'tool_automate');
        } else if (!isset($categories[$selectedcategory])) {
            $formerror = get_string('canvasselectcategory', 'tool_automate');
        } else if ($buildwanted && !$cancreate) {
            // Building creates courses in the target category, so require the same
            // capability Canvas Uplifter enforces for a build. Analyse creates
            // nothing, so it is allowed without it.
            $formerror = get_string('canvasnobuildcap', 'tool_automate');
        } else {
            $categoryname = $categories[$selectedcategory];
            $queued = [];
            $skipped = [];

            foreach ($selectedbasenames as $basename) {
                $resolved = canvas_repository::resolve($basename, $sourcedir);
                if ($resolved === null) {
                    $skipped[] = $basename;
                    continue;
                }
                if (!$dryrun) {
                    canvas_repository::queue_file(
                        $resolved,
                        $selectedcategory,
                        $USER->id,
                        $selectedmode,
                        $selectedquiz,
                        $selectedgrouping
                    );
                }
                $queued[] = $basename;
            }
            foreach ($urls as $url) {
                if (!$dryrun) {
                    canvas_repository::queue_url(
                        $url,
                        $selectedcategory,
                        $USER->id,
                        $selectedmode,
                        $selectedquiz,
                        $selectedgrouping
                    );
                }
                $queued[] = $url;
            }

            if (!$dryrun) {
                // Redirect-after-POST so a refresh cannot re-queue everything.
                $a = (object) [
                    'count' => count($queued),
                    'category' => format_string($categoryname),
                    'mode' => get_string('canvasmode_' . $selectedmode, 'tool_automate'),
                ];
                $message = get_string('canvasqueued', 'tool_automate', $a);
                if ($skipped) {
                    $message .= ' ' . get_string('canvasskipped', 'tool_automate', s(implode(', ', $skipped)));
                }
                redirect($baseurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
            }

            // Dry-run preview: stash the outcome to render after the header.
            $preview = (object) [
                'queued' => $queued,
                'skipped' => $skipped,
                'category' => $categoryname,
                'mode' => $selectedmode,
            ];
        }
    }
}

echo $OUTPUT->header();
echo html_writer::link($indexurl, get_string('back', 'tool_automate'), ['class' => 'tool_automate_back']);

// Only import packages you trust: Canvas Uplifter stores/renders imported HTML
// as authored, so a package from an untrusted source could carry active content.
echo $OUTPUT->notification(get_string('canvastrustwarning', 'tool_automate'), 'warning');

// A shortcut to the import history, where "Analyse & stage for later" packages
// are reviewed and built.
echo html_writer::div(
    html_writer::link(
        new moodle_url('/admin/tool/automate/canvas_imports.php'),
        get_string('canvasimportslink', 'tool_automate')
    ),
    'mb-3'
);

// Site-level kill-switch. A dead end with a pointer to the setting.
if (!$enabled) {
    echo $OUTPUT->notification(get_string('canvasdisabled', 'tool_automate'), 'warning');
    echo html_writer::div(html_writer::link($settingsurl, get_string('settings', 'tool_automate')));
    echo $OUTPUT->footer();
    exit;
}

if ($formerror !== null) {
    echo $OUTPUT->notification($formerror, 'warning');
}

if ($preview !== null) {
    if ($preview->queued) {
        $a = (object) [
            'count' => count($preview->queued),
            'category' => format_string($preview->category),
            'mode' => get_string('canvasmode_' . $preview->mode, 'tool_automate'),
        ];
        echo $OUTPUT->notification(get_string('canvaswouldqueue', 'tool_automate', $a), 'info');
        echo html_writer::div(
            html_writer::alist(array_map('s', $preview->queued)),
            'tool_automate_restore_list mb-3'
        );
    }
    if ($preview->skipped) {
        $skippedlist = html_writer::alist(array_map('s', $preview->skipped));
        echo $OUTPUT->notification(get_string('canvasskipped', 'tool_automate', $skippedlist), 'warning');
    }
}

// Live client-side filter for the directory table and a tick-all control. Inline
// AMD only reads values and toggles visibility - it never writes markup from
// user data.
$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var input = document.getElementById('tool_automate_canvassearch');
    var table = document.getElementById('tool_automate_canvas_table');
    if (!table) {
        return;
    }
    var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr[data-name]'));
    var nomatch = document.getElementById('tool_automate_canvas_nomatch');
    var selectall = document.getElementById('tool_automate_canvas_selectall');
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
            if (selectall) {
                selectall.checked = false;
            }
        });
    }
    if (selectall) {
        selectall.addEventListener('change', function() {
            rows.forEach(function(row) {
                if (row.style.display === 'none') {
                    return;
                }
                var box = row.querySelector('input[type="checkbox"]');
                if (box) {
                    box.checked = selectall.checked;
                }
            });
        });
    }
});
JS);

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => $baseurl->out(false),
    'id' => 'tool_automate_canvas_form',
    'class' => 'tool_automate_restore_form',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Source 1: a pasted list of Canvas backup URLs.
echo html_writer::start_div('tool_automate_field');
echo html_writer::label(
    get_string('canvasurls', 'tool_automate'),
    'tool_automate_canvasurls',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::div(get_string('canvasurls_help', 'tool_automate'), 'text-muted mb-1');
echo html_writer::tag('textarea', s($urlsvalue), [
    'name' => 'urls',
    'id' => 'tool_automate_canvasurls',
    'rows' => 6,
    'class' => 'tool_automate_canvasurls',
    'style' => 'width:100%;font-family:monospace;',
    'placeholder' => "https://example.edu/exports/course-1.imscc\nhttps://example.edu/exports/course-2.imscc",
    'spellcheck' => 'false',
]);
echo html_writer::end_div();

// Source 2: a server directory of packages.
echo html_writer::tag('h3', get_string('canvasdirheading', 'tool_automate'), ['class' => 'mt-3']);
if (!$dirok) {
    // Not a dead end: the URL list above still works without a directory.
    echo html_writer::div(get_string('canvasnodir', 'tool_automate'), 'text-muted mb-2');
    echo html_writer::div(html_writer::link($settingsurl, get_string('settings', 'tool_automate')), 'mb-3');
} else {
    echo html_writer::div(
        html_writer::div(get_string('restoresourcedir', 'tool_automate'), 'tool_automate_sourcedir_label')
            . html_writer::tag('code', s($sourcedir), ['class' => 'tool_automate_sourcedir_path']),
        'tool_automate_sourcedir mb-2'
    );

    if (empty($files)) {
        echo $OUTPUT->notification(get_string('canvasnofiles', 'tool_automate'), 'info');
    } else {
        $shownfiles = array_slice($files, 0, $maxrows);

        echo html_writer::start_div('tool_automate_searchrow mb-2');
        echo html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'tool_automate_canvassearch',
            'class' => 'tool_automate_backupsearch',
            'placeholder' => get_string('canvasfilessearch', 'tool_automate'),
            'autocomplete' => 'off',
        ]);
        echo html_writer::end_div();

        if (count($files) > $maxrows) {
            $a = (object) ['shown' => count($shownfiles), 'total' => count($files)];
            echo $OUTPUT->notification(get_string('canvasfilescapped', 'tool_automate', $a), 'info');
        }

        $selectall = html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'id' => 'tool_automate_canvas_selectall',
            'aria-label' => get_string('canvasselectall', 'tool_automate'),
        ]);
        $table = new html_table();
        $table->attributes['id'] = 'tool_automate_canvas_table';
        $table->attributes['class'] = 'generaltable tool_automate_backups_table';
        $table->head = [
            $selectall,
            get_string('restorecolname', 'tool_automate'),
            get_string('restorecolsize', 'tool_automate'),
            get_string('restorecolmodified', 'tool_automate'),
        ];
        foreach ($shownfiles as $basename) {
            $resolved = canvas_repository::resolve($basename, $sourcedir);
            $readable = $resolved !== null;
            $token = canvas_repository::token($basename);
            // Keep the admin's ticks across a preview re-render.
            $checked = in_array($token, $submittedtokens, true);

            $checkbox = html_writer::checkbox('files[]', $token, $checked, '', [
                'class' => 'tool_automate_backupcb',
                'aria-label' => get_string('canvasselectfile', 'tool_automate', $basename),
            ]);
            $checkcell = new html_table_cell($checkbox);
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
            $table->data[] = $row;
        }

        echo html_writer::start_div('tool_automate_backups_scroll');
        echo html_writer::table($table);
        echo html_writer::end_div();
        echo html_writer::div(
            get_string('canvasnomatch', 'tool_automate'),
            'tool_automate_restore_nomatch',
            ['id' => 'tool_automate_canvas_nomatch', 'style' => 'display:none;']
        );
    }
}

// Target category.
echo html_writer::start_div('tool_automate_field tool_automate_categoryfield mt-3');
echo html_writer::label(
    get_string('canvastargetcategory', 'tool_automate'),
    'menucategoryid',
    true,
    ['class' => 'd-block fw-bold']
);
echo html_writer::select($categories, 'categoryid', $selectedcategory, ['0' => get_string('choosedots')], [
    'id' => 'menucategoryid',
    'class' => 'tool_automate_categoryselect',
]);
echo html_writer::end_div();

// Run mode.
echo html_writer::start_div('tool_automate_field mt-3');
echo html_writer::div(get_string('canvasmode', 'tool_automate'), 'fw-bold');
foreach ([canvas_repository::MODE_BUILD, canvas_repository::MODE_ANALYSE] as $modeoption) {
    $radioattrs = [
        'type' => 'radio',
        'name' => 'mode',
        'id' => 'tool_automate_mode_' . $modeoption,
        'value' => $modeoption,
        'class' => 'me-1',
    ];
    if ($modeoption === $selectedmode) {
        $radioattrs['checked'] = 'checked';
    }
    $modelabel = get_string('canvasmode_' . $modeoption, 'tool_automate')
        . ' - ' . get_string('canvasmode_' . $modeoption . '_desc', 'tool_automate');
    echo html_writer::div(
        html_writer::empty_tag('input', $radioattrs)
            . html_writer::label($modelabel, 'tool_automate_mode_' . $modeoption, false, ['class' => 'ms-1']),
        'tool_automate_moderow'
    );
}
echo html_writer::end_div();

// Conversion options passed through to Canvas Uplifter.
echo html_writer::start_div('tool_automate_field mt-3');
echo html_writer::div(get_string('canvasoptions', 'tool_automate'), 'fw-bold');
$quizlabel = ' ' . get_string('canvasquizfrombank', 'tool_automate');
echo html_writer::div(
    html_writer::checkbox('quizfrombank', 1, $selectedquiz, $quizlabel, ['id' => 'tool_automate_quizfrombank'])
);
echo html_writer::start_div('mt-2');
echo html_writer::label(
    get_string('canvaspagegrouping', 'tool_automate'),
    'menupagegrouping',
    true,
    ['class' => 'me-2']
);
$groupingoptions = [
    '' => get_string('canvaspagegrouping_none', 'tool_automate'),
    'book' => get_string('canvaspagegrouping_book', 'tool_automate'),
    'lesson' => get_string('canvaspagegrouping_lesson', 'tool_automate'),
];
echo html_writer::select($groupingoptions, 'pagegrouping', $selectedgrouping, false, ['id' => 'menupagegrouping']);
echo html_writer::end_div();
echo html_writer::end_div();

// Actions.
echo html_writer::start_div('tool_automate_restore_actions mt-3');
echo html_writer::tag(
    'button',
    get_string('canvaspreview', 'tool_automate'),
    ['type' => 'submit', 'name' => 'preview', 'value' => '1', 'class' => 'btn btn-secondary']
);
echo html_writer::tag(
    'button',
    get_string('canvasqueue', 'tool_automate'),
    ['type' => 'submit', 'name' => 'queue', 'value' => '1', 'class' => 'btn btn-primary']
);
echo html_writer::link($indexurl, get_string('cancel'), ['class' => 'btn btn-link']);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo $OUTPUT->footer();
