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
 * CLI: bulk restore course backups from a repository directory.
 *
 * Lists or queues the .mbz files in the configured (or a supplied) directory
 * for background restore into new courses. Defaults to a dry run; pass
 * --execute to actually queue the restore_course adhoc tasks.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'     => false,
        'list'     => false,
        'source'   => '',
        'category' => 0,
        'execute'  => false,
    ],
    [
        'h' => 'help',
        'l' => 'list',
        's' => 'source',
        'c' => 'category',
        'e' => 'execute',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    echo "Bulk restore course backups (.mbz) from a repository directory into new courses.

Each backup is queued as a background restore_course adhoc task and becomes a
brand-new course in the target category. Existing courses are never touched.

Options:
  -h, --help            Print this help.
  -l, --list            List the backup files found and exit.
  -s, --source=PATH     Directory to read .mbz files from. Defaults to the
                        configured 'Bulk restore source directory' setting.
  -c, --category=ID     Target course category id for the new courses.
  -e, --execute         Queue the restores. Without this flag the script only
                        reports what it would do (dry run).

Examples:
  # Dry run against the configured directory, into category 2:
  \$ php admin/tool/automate/cli/restore_repository.php --category=2

  # Restore every backup in a directory into category 5:
  \$ php admin/tool/automate/cli/restore_repository.php --source=/data/restores --category=5 --execute
";
    exit(0);
}

// Honour the same site-level kill-switch as the web UI: one setting governs
// the whole feature.
if (!\tool_automate\restore_repository::is_enabled()) {
    cli_error("The bulk restore feature is switched off. Enable 'Allow bulk restore from repository' "
        . "in Site administration > Plugins > Admin tools > Settings first.");
}

$sourcedir = $options['source'] !== ''
    ? rtrim((string) $options['source'], '/\\')
    : \tool_automate\restore_repository::get_source_dir();

if ($sourcedir === '') {
    cli_error("No source directory. Set 'Bulk restore source directory' in the plugin settings, or pass --source=PATH.");
}
if (!is_dir($sourcedir) || !is_readable($sourcedir)) {
    cli_error("Source directory not found or not readable: " . $sourcedir);
}

$files = \tool_automate\restore_repository::list_backups($sourcedir);

if ($options['list']) {
    if (!$files) {
        cli_writeln("No .mbz backup files found in " . $sourcedir);
    } else {
        cli_writeln(count($files) . " backup file(s) in " . $sourcedir . ":");
        foreach ($files as $basename) {
            cli_writeln('  ' . $basename);
        }
    }
    exit(0);
}

$categoryid = (int) $options['category'];
if (!$categoryid || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
    cli_error("Pass a valid --category=ID (target course category). Use --list to inspect the directory first.");
}

if (!$files) {
    cli_writeln("No .mbz backup files found in " . $sourcedir . " - nothing to do.");
    exit(0);
}

$adminid = (int) get_admin()->id;
$queued = 0;
$skipped = 0;
foreach ($files as $basename) {
    $resolved = \tool_automate\restore_repository::resolve($basename, $sourcedir);
    if ($resolved === null) {
        cli_problem('  skipped (could not resolve): ' . $basename);
        $skipped++;
        continue;
    }
    if ($options['execute']) {
        \tool_automate\restore_repository::queue($resolved, $categoryid, $adminid);
        cli_writeln('  queued: ' . $basename);
    } else {
        cli_writeln('  would queue: ' . $basename);
    }
    $queued++;
}

if ($options['execute']) {
    cli_writeln($queued . " restore(s) queued into category " . $categoryid
        . ". Run cron to process them (" . $skipped . " skipped).");
} else {
    cli_writeln("Dry run: " . $queued . " backup(s) would be queued into category " . $categoryid
        . " (" . $skipped . " skipped). Re-run with --execute to queue them.");
}

exit(0);
