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

admin_externalpage_setup('tool_automate');
$context = context_system::instance();
require_capability('tool/automate:manage', $context);

$baseurl = new moodle_url('/admin/tool/automate/restore.php');
$indexurl = new moodle_url('/admin/tool/automate/index.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'tool_automate_settings']);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('restoretitle', 'tool_automate'));
$PAGE->set_heading(get_string('restoretitle', 'tool_automate'));

$enabled = \tool_automate\restore_repository::is_enabled();
$sourcedir = \tool_automate\restore_repository::get_source_dir();

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

$files = \tool_automate\restore_repository::list_backups($sourcedir);
$categories = $DB->get_records_menu('course_categories', null, 'name', 'id, name');

$form = new \tool_automate\form\restore_form($baseurl, [
    'files'      => $files,
    'categories' => $categories,
    'sourcedir'  => $sourcedir,
]);

if ($form->is_cancelled()) {
    redirect($indexurl);
}

if (($data = $form->get_data()) && !empty($data->files)) {
    $dryrun = empty($data->restore);
    $categoryid = (int) $data->categoryid;
    $categoryname = $categories[$categoryid] ?? ('#' . $categoryid);

    $queued = [];
    $skipped = [];
    foreach ((array) $data->files as $basename) {
        $resolved = \tool_automate\restore_repository::resolve($basename, $sourcedir);
        if ($resolved === null) {
            $skipped[] = $basename;
            continue;
        }
        if (!$dryrun) {
            \tool_automate\restore_repository::queue($resolved, $categoryid, $USER->id);
        }
        $queued[] = $basename;
    }

    if ($queued) {
        $a = (object) ['count' => count($queued), 'category' => format_string($categoryname)];
        $message = $dryrun
            ? get_string('restorewouldqueue', 'tool_automate', $a)
            : get_string('restorequeued', 'tool_automate', $a);
        $list = html_writer::alist(array_map('s', $queued));
        echo $OUTPUT->notification($message, $dryrun ? 'info' : 'success');
        echo html_writer::div($list, 'tool_automate_restore_list mb-3');
    }
    if ($skipped) {
        echo $OUTPUT->notification(
            get_string('restoreskipped', 'tool_automate', html_writer::alist(array_map('s', $skipped))),
            'warning'
        );
    }
}

$form->display();
echo $OUTPUT->footer();
