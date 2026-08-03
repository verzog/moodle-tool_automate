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
 * CLI: bulk import Canvas packages via tool_canvasuplifter.
 *
 * Queues a Canvas Uplifter analyse or build job for each .imscc package found
 * in a directory and/or listed (one per line) in a URL file. Defaults to a dry
 * run; pass --execute to actually queue the jobs.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use tool_automate\canvas_repository;

[$options, $unrecognised] = cli_get_params(
    [
        'help'         => false,
        'list'         => false,
        'source'       => '',
        'urls'         => '',
        'category'     => 0,
        'mode'         => canvas_repository::MODE_ANALYSE,
        'quizfrombank' => false,
        'pagegrouping' => '',
        'execute'      => false,
    ],
    [
        'h' => 'help',
        'l' => 'list',
        's' => 'source',
        'u' => 'urls',
        'c' => 'category',
        'm' => 'mode',
        'e' => 'execute',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    echo "Bulk import Canvas Common Cartridge (.imscc) packages via tool_canvasuplifter.

Each package (from a directory and/or a URL list) is queued as a background
Canvas Uplifter job. In 'build' mode a new course is created automatically; in
'analyse' mode the package is fetched and a conversion report produced for a
later manual build. Existing courses are never overwritten.

Options:
  -h, --help              Print this help.
  -l, --list              List the packages found (directory + URL file) and exit.
  -s, --source=PATH       Directory to read .imscc packages from. Defaults to the
                          configured 'Bulk Canvas import source directory' setting.
  -u, --urls=FILE         Path to a text file of Canvas backup URLs, one per line
                          (# lines and blanks ignored).
  -c, --category=ID       Target course category id for the new courses.
  -m, --mode=MODE         'build' (create courses now) or 'analyse' (fetch and
                          report for later manual build). Default: analyse.
      --quizfrombank      Also build a runnable quiz from each standalone question
                          bank (passed through to Canvas Uplifter).
      --pagegrouping=X    Combine consecutive pages: 'book', 'lesson', or '' (off).
  -e, --execute           Queue the jobs. Without this flag the script only
                          reports what it would do (dry run).

Examples:
  # Dry run: what would be imported from the configured directory, into category 2:
  \$ php admin/tool/automate/cli/import_canvas.php --category=2

  # Build courses now from a directory of exports into category 5:
  \$ php admin/tool/automate/cli/import_canvas.php --source=/data/canvas --category=5 --mode=build --execute

  # Analyse a list of Canvas backup URLs for later manual build:
  \$ php admin/tool/automate/cli/import_canvas.php --urls=/data/urls.txt --category=5 --execute
";
    exit(0);
}

// The sibling tool_canvasuplifter plugin does the actual conversion; without it
// there is nothing to drive.
if (!canvas_repository::is_available()) {
    cli_error("tool_canvasuplifter is not installed. Install the Canvas Uplifter plugin to use bulk Canvas import.");
}

// Honour the same site-level kill-switch as the web UI.
if (!canvas_repository::is_enabled()) {
    cli_error("Bulk Canvas import is switched off. Enable 'Allow bulk Canvas import' in "
        . "Site administration > Plugins > Admin tools > Automate > Settings first.");
}

$mode = canvas_repository::normalise_mode((string) $options['mode']);
$quizfrombank = (bool) $options['quizfrombank'];
$pagegrouping = in_array($options['pagegrouping'], ['book', 'lesson'], true) ? (string) $options['pagegrouping'] : '';

// Resolve the directory source (optional).
$sourcedir = $options['source'] !== ''
    ? rtrim((string) $options['source'], '/\\')
    : canvas_repository::get_source_dir();
if ($sourcedir !== '' && (!is_dir($sourcedir) || !is_readable($sourcedir))) {
    cli_error("Source directory not found or not readable: " . $sourcedir);
}
$files = $sourcedir !== '' ? canvas_repository::list_packages($sourcedir) : [];

// Resolve the URL list source (optional).
$urls = [];
if ($options['urls'] !== '') {
    $urlfile = (string) $options['urls'];
    if (!is_file($urlfile) || !is_readable($urlfile)) {
        cli_error("URL list file not found or not readable: " . $urlfile);
    }
    $urls = canvas_repository::parse_urls((string) file_get_contents($urlfile));
}

if ($options['list']) {
    if ($files) {
        cli_writeln(count($files) . " package file(s) in " . $sourcedir . ":");
        foreach ($files as $basename) {
            cli_writeln('  ' . $basename);
        }
    } else {
        cli_writeln("No .imscc package files found" . ($sourcedir !== '' ? " in " . $sourcedir : "") . ".");
    }
    if ($urls) {
        cli_writeln(count($urls) . " URL(s):");
        foreach ($urls as $url) {
            cli_writeln('  ' . $url);
        }
    }
    exit(0);
}

$categoryid = (int) $options['category'];
if (!$categoryid || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
    cli_error("Pass a valid --category=ID (target course category). Use --list to inspect the sources first.");
}

if (!$files && !$urls) {
    cli_writeln("No packages to import (empty directory and no --urls file) - nothing to do.");
    exit(0);
}

$adminid = (int) get_admin()->id;
$queued = 0;
$skipped = 0;

foreach ($files as $basename) {
    $resolved = canvas_repository::resolve($basename, $sourcedir);
    if ($resolved === null) {
        cli_problem('  skipped (could not resolve): ' . $basename);
        $skipped++;
        continue;
    }
    if ($options['execute']) {
        canvas_repository::queue_file($resolved, $categoryid, $adminid, $mode, $quizfrombank, $pagegrouping);
        cli_writeln('  queued (' . $mode . '): ' . $basename);
    } else {
        cli_writeln('  would queue (' . $mode . '): ' . $basename);
    }
    $queued++;
}
foreach ($urls as $url) {
    if ($options['execute']) {
        canvas_repository::queue_url($url, $categoryid, $adminid, $mode, $quizfrombank, $pagegrouping);
        cli_writeln('  queued (' . $mode . '): ' . $url);
    } else {
        cli_writeln('  would queue (' . $mode . '): ' . $url);
    }
    $queued++;
}

if ($options['execute']) {
    cli_writeln($queued . " Canvas import job(s) queued into category " . $categoryid
        . " in '" . $mode . "' mode. Run cron to process them (" . $skipped . " skipped).");
} else {
    cli_writeln("Dry run: " . $queued . " package(s) would be queued into category " . $categoryid
        . " in '" . $mode . "' mode (" . $skipped . " skipped). Re-run with --execute to queue them.");
}

exit(0);
