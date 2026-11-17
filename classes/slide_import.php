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

namespace tool_automate;

/**
 * Slide folder import, via booktool_importpptx and local_lessonimportpptx.
 *
 * A soft integration, like {@see calendar_import}: tool_automate does not declare a hard
 * dependency on either importer. When at least one of them is installed with its API class,
 * a scheduled task imports PowerPoint (and, where the server supports it, PDF) files dropped
 * into a server folder, each into a Book or Lesson activity in the course the file names.
 *
 * How a file names its course and activity is a site setting:
 *  - "filename": the file is called <course>__<activity name>.pptx; or
 *  - "manifest": a CSV file in the same folder lists file, course and name for each deck.
 * In both, <course> is a course ID number or, failing that, a course short name.
 *
 * The import works in place. Each run moves waiting decks from the folder into its
 * "processing" subfolder, writes a small target file beside each one saying where it goes,
 * and queues one adhoc task per deck. The task imports the deck and moves it to "processed"
 * or "failed", writing a results CSV beside it either way.
 *
 * Everything read back from the folder (file names, manifests and target files) is treated as
 * untrusted input: names must be bare file names, and every value is checked again before use.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class slide_import {
    /** API classes provided by the importer plugins, keyed by the activity type they create. */
    public const IMPORTERS = [
        'book' => '\\booktool_importpptx\\api',
        'lesson' => '\\local_lessonimportpptx\\api',
    ];

    /** Lowest importer API version this integration supports. */
    public const MIN_API_VERSION = 1;

    /** Mapping mode: the course and activity name are in the file name. */
    public const MAPPING_FILENAME = 'filename';
    /** Mapping mode: a CSV manifest lists the course and activity name for each file. */
    public const MAPPING_MANIFEST = 'manifest';

    /** Separator between the course and the activity name in a file name. */
    public const SEPARATOR = '__';

    /** Extensions (lower-case, no dot) the importers can ever accept. */
    public const DECK_EXTENSIONS = ['pptx', 'pdf'];

    /** Most decks moved into processing and queued by one scheduled run. */
    public const BATCH = 50;

    /** Most rows read from one manifest. */
    public const MAX_MANIFEST_ROWS = 1000;

    /** Suffix of the target file written beside a deck in the processing subfolder. */
    public const TARGET_SUFFIX = '.target.json';

    /** Subfolder for decks queued but not yet imported. */
    public const DIR_PROCESSING = 'processing';
    /** Subfolder for imported decks and their results. */
    public const DIR_PROCESSED = 'processed';
    /** Subfolder for decks that could not be imported. */
    public const DIR_FAILED = 'failed';

    /**
     * The activity types that can be created on this site.
     *
     * @return string[] Activity type ('book', 'lesson') => importer API class name.
     */
    public static function available_types(): array {
        $types = [];
        foreach (self::IMPORTERS as $type => $class) {
            if (
                class_exists($class) && defined($class . '::API_VERSION')
                && (int) constant($class . '::API_VERSION') >= self::MIN_API_VERSION
            ) {
                $types[$type] = $class;
            }
        }
        return $types;
    }

    /**
     * Is at least one compatible importer plugin installed?
     *
     * @return bool
     */
    public static function is_available(): bool {
        return !empty(self::available_types());
    }

    /**
     * Whether the scheduled folder import is switched on for the site.
     *
     * Off by default: it creates activities in bulk, so a site admin opts in first.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_automate', 'allow_slide_import');
    }

    /**
     * The configured source folder, without a trailing separator.
     *
     * @return string Empty when not configured.
     */
    public static function get_source_dir(): string {
        $dir = trim((string) get_config('tool_automate', 'slide_source_dir'));
        return $dir === '' ? '' : rtrim($dir, '/\\');
    }

    /**
     * How files say where they go.
     *
     * @return string One of the MAPPING_* constants.
     */
    public static function get_mapping(): string {
        $mapping = (string) get_config('tool_automate', 'slide_mapping');
        return $mapping === self::MAPPING_MANIFEST ? self::MAPPING_MANIFEST : self::MAPPING_FILENAME;
    }

    /**
     * The activity type used when a file does not say (always, for the file name mapping).
     *
     * @return string 'book' or 'lesson'; empty when no importer is installed.
     */
    public static function get_default_type(): string {
        $types = self::available_types();
        $type = (string) get_config('tool_automate', 'slide_default_type');
        return isset($types[$type]) ? $type : (string) (array_key_first($types) ?? '');
    }

    /**
     * The import mode used when a file does not say.
     *
     * @return string 'editable' or 'images'.
     */
    public static function get_default_mode(): string {
        return get_config('tool_automate', 'slide_import_mode') === 'images' ? 'images' : 'editable';
    }

    /**
     * Is this a bare file name (no path components, not hidden) with one of the given extensions?
     *
     * @param string $name
     * @param string[] $extensions Lower-case extensions without the dot.
     * @return bool
     */
    public static function is_safe_filename(string $name, array $extensions): bool {
        if ($name === '' || $name[0] === '.' || $name !== basename($name) || strpbrk($name, "/\\\0") !== false) {
            return false;
        }
        $dot = strrpos($name, '.');
        return $dot !== false && $dot > 0 && in_array(strtolower(substr($name, $dot + 1)), $extensions, true);
    }

    /**
     * Is this a bare deck file name?
     *
     * @param string $name
     * @return bool
     */
    public static function is_deck_filename(string $name): bool {
        return self::is_safe_filename($name, self::DECK_EXTENSIONS);
    }

    /**
     * List the files with the given extensions in a folder (top level only).
     *
     * @param string $dir Folder to scan.
     * @param string[] $extensions Lower-case extensions without the dot.
     * @return string[] Sorted basenames.
     */
    public static function list_files(string $dir, array $extensions = self::DECK_EXTENSIONS): array {
        if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $files = [];
        foreach ((scandir($dir) ?: []) as $entry) {
            if (self::is_safe_filename($entry, $extensions) && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Read the course and activity name out of a deck's file name.
     *
     * @param string $filename For example "BIO101__Week 1 slides.pptx".
     * @return array|null Keys course and name, or null when the name does not follow the pattern.
     */
    public static function parse_filename(string $filename): ?array {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $pos = strpos($stem, self::SEPARATOR);
        if ($pos === false) {
            return null;
        }
        $course = trim(substr($stem, 0, $pos));
        $name = trim(substr($stem, $pos + strlen(self::SEPARATOR)));
        return ($course === '' || $name === '') ? null : ['course' => $course, 'name' => $name];
    }

    /**
     * Check and tidy a target, whatever its source (file name, manifest row or target file).
     *
     * @param array $raw Keys course, name and optionally type, section, mode.
     * @return array Keys course, name, type, section, mode, plus error (a lang string key in
     *               tool_automate, empty when the target is usable).
     */
    public static function clean_target(array $raw): array {
        $text = function ($value): string {
            return is_scalar($value) ? trim(clean_param((string) $value, PARAM_TEXT)) : '';
        };
        $type = strtolower($text($raw['type'] ?? ''));
        $mode = strtolower($text($raw['mode'] ?? ''));
        $target = [
            'course' => $text($raw['course'] ?? ''),
            'name' => \core_text::substr($text($raw['name'] ?? ''), 0, 255),
            'type' => $type === '' ? self::get_default_type() : $type,
            'section' => max(0, (int) $text($raw['section'] ?? '')),
            'mode' => in_array($mode, ['editable', 'images'], true) ? $mode : self::get_default_mode(),
            'error' => '',
        ];
        if ($target['course'] === '' || $target['name'] === '') {
            $target['error'] = 'slideimport_notarget';
        } else if (!isset(self::available_types()[$target['type']])) {
            $target['error'] = 'slideimport_badtype';
        }
        return $target;
    }

    /**
     * Find a course by ID number or, failing that, short name. The site course never matches.
     *
     * @param string $reference
     * @return \stdClass|null The course record.
     */
    public static function find_course(string $reference): ?\stdClass {
        if ($reference === '') {
            return null;
        }
        $course = self::single_course('idnumber', $reference) ?? self::single_course('shortname', $reference);
        return ($course === null || (int) $course->id === (int) SITEID) ? null : $course;
    }

    /**
     * The one course with this value in a field.
     *
     * @param string $field 'idnumber' or 'shortname'.
     * @param string $value
     * @return \stdClass|null Null when no course matches, or when more than one does (Moodle does
     *                        not allow that through its own forms, but the database does).
     */
    protected static function single_course(string $field, string $value): ?\stdClass {
        global $DB;
        $courses = $DB->get_records('course', [$field => $value], 'id', '*', 0, 2);
        return count($courses) === 1 ? reset($courses) : null;
    }

    /**
     * Move waiting decks into the processing subfolder and queue an import task for each.
     *
     * Decks already in processing that no queued task owns (for example after a crash between
     * the move and the queueing) are queued again first.
     *
     * @param string|null $dir Folder to scan; defaults to the configured one.
     * @return array Keys: queued (int), error (string, empty when nothing went wrong).
     */
    public static function queue_waiting_files(?string $dir = null): array {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !is_dir($dir)) {
            return ['queued' => 0, 'error' => get_string('slideimport_nodir', 'tool_automate')];
        }
        if (!is_readable($dir)) {
            return ['queued' => 0, 'error' => get_string('slideimport_notreadable', 'tool_automate', $dir)];
        }

        $queued = self::requeue_orphans($dir);
        $manifest = self::get_mapping() === self::MAPPING_MANIFEST;
        $waiting = $manifest ? self::list_files($dir, ['csv']) : self::list_files($dir);
        if (empty($waiting)) {
            return ['queued' => $queued, 'error' => ''];
        }
        // Moving a file out of the folder needs write access to the folder itself.
        if (!is_writable($dir) || self::subfolder($dir, self::DIR_PROCESSING) === null) {
            return ['queued' => $queued, 'error' => get_string('slideimport_notwritable', 'tool_automate', $dir)];
        }

        $failed = 0;
        if ($manifest) {
            foreach ($waiting as $name) {
                $outcome = self::queue_manifest($dir, $name);
                $queued += $outcome['queued'];
                $failed += $outcome['failed'];
            }
        } else {
            foreach (array_slice($waiting, 0, max(0, self::BATCH - $queued)) as $name) {
                // A name that does not follow the pattern is still queued: the task files it
                // under "failed" with a results file that says why.
                $target = self::parse_filename($name) ?? ['course' => '', 'name' => ''];
                if (self::queue_deck($dir, $name, $target)) {
                    $queued++;
                } else {
                    $failed++;
                }
            }
        }
        $error = $failed ? get_string('slideimport_movefailed', 'tool_automate', $failed) : '';
        return ['queued' => $queued, 'error' => $error];
    }

    /**
     * Queue every deck a manifest lists, then file the manifest away with a results CSV.
     *
     * @param string $dir The source folder.
     * @param string $manifest The manifest's file name.
     * @return array Keys: queued (int), failed (int, decks or manifests that could not be moved).
     */
    protected static function queue_manifest(string $dir, string $manifest): array {
        $path = $dir . DIRECTORY_SEPARATOR . $manifest;
        $parsed = self::read_manifest($path);
        $results = [];
        $queued = 0;
        $failed = 0;
        $seen = [];
        foreach ($parsed['rows'] as $row) {
            $file = $row['file'];
            if (!self::is_deck_filename($file) || !is_file($dir . DIRECTORY_SEPARATOR . $file)) {
                $results[] = [$row['line'], $file, 'error', get_string('slideimport_filemissing', 'tool_automate')];
                continue;
            }
            if (isset($seen[$file])) {
                $results[] = [$row['line'], $file, 'error', get_string('slideimport_duplicate', 'tool_automate')];
                continue;
            }
            $seen[$file] = true;
            if (self::queue_deck($dir, $file, $row)) {
                $queued++;
                $results[] = [$row['line'], $file, 'queued', ''];
            } else {
                $failed++;
                $results[] = [$row['line'], $file, 'error', get_string('slideimport_rowmovefailed', 'tool_automate')];
            }
        }

        $destdir = self::subfolder($dir, $parsed['error'] === '' ? self::DIR_PROCESSED : self::DIR_FAILED);
        $dest = ($destdir ?? $dir) . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . $manifest;
        if ($destdir === null || file_exists($dest) || !@rename($path, $dest)) {
            // Rename it in place so the same manifest is not read again every run.
            $dest = $path . '.done';
            if (!@rename($path, $dest)) {
                return ['queued' => $queued, 'failed' => $failed + 1];
            }
        }
        $rows = [['row', 'file', 'status', 'message']];
        if ($parsed['error'] !== '') {
            $rows[] = [0, '', 'error', $parsed['error']];
        }
        self::write_csv($dest . '.results.csv', array_merge($rows, $results));
        return ['queued' => $queued, 'failed' => $failed];
    }

    /**
     * Read a manifest: a CSV with a header row naming at least the file, course and name columns.
     *
     * @param string $path Absolute path to the manifest.
     * @return array Keys: rows (list of arrays with line, file, course, name, type, section,
     *               mode) and error (string, empty when the manifest could be read).
     */
    public static function read_manifest(string $path): array {
        $handle = @fopen($path, 'r');
        if (!$handle) {
            return ['rows' => [], 'error' => get_string('slideimport_manifestunreadable', 'tool_automate')];
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        $columns = [];
        foreach (($header ?: []) as $index => $label) {
            // Spreadsheets often save a byte order mark in front of the first heading.
            $label = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $label)));
            if (in_array($label, ['file', 'course', 'name', 'type', 'section', 'mode'], true)) {
                $columns[$label] = $index;
            }
        }
        if (!isset($columns['file'], $columns['course'], $columns['name'])) {
            fclose($handle);
            return ['rows' => [], 'error' => get_string('slideimport_manifestcolumns', 'tool_automate')];
        }

        $rows = [];
        $line = 1;
        $error = '';
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || trim(implode('', $cells)) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_MANIFEST_ROWS) {
                $error = get_string('slideimport_manifesttoolong', 'tool_automate', self::MAX_MANIFEST_ROWS);
                break;
            }
            $row = ['line' => $line];
            foreach (['file', 'course', 'name', 'type', 'section', 'mode'] as $label) {
                $row[$label] = isset($columns[$label]) ? trim((string) ($cells[$columns[$label]] ?? '')) : '';
            }
            $rows[] = $row;
        }
        fclose($handle);
        return ['rows' => $rows, 'error' => $error];
    }

    /**
     * Move one deck into the processing subfolder, record its target and queue its import.
     *
     * @param string $dir The source folder.
     * @param string $name The deck's file name.
     * @param array $target Keys course, name and optionally type, section, mode.
     * @return bool False when the deck could not be moved; it is left where it was.
     */
    protected static function queue_deck(string $dir, string $name, array $target): bool {
        $processing = self::subfolder($dir, self::DIR_PROCESSING);
        if ($processing === null) {
            return false;
        }
        // A timestamp prefix keeps a re-dropped file with the same name from overwriting one
        // still waiting in processing.
        $source = $dir . DIRECTORY_SEPARATOR . $name;
        $dest = $processing . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . $name;
        if (file_exists($dest) || !@rename($source, $dest)) {
            return false;
        }
        $stored = array_intersect_key($target, array_flip(['course', 'name', 'type', 'section', 'mode']));
        if (@file_put_contents($dest . self::TARGET_SUFFIX, json_encode($stored + ['original' => $name])) === false) {
            // For example a name too long to take the suffix. Put the deck back and carry on,
            // so one awkward file cannot stop the rest of the folder being queued.
            @unlink($dest . self::TARGET_SUFFIX);
            @rename($dest, $source);
            return false;
        }
        try {
            self::queue_file($dest);
        } catch (\Throwable $e) {
            // Put the deck back so the next run picks it up again, then let the task fail.
            @unlink($dest . self::TARGET_SUFFIX);
            @rename($dest, $source);
            throw $e;
        }
        return true;
    }

    /**
     * Queue an import task for decks in the processing subfolder that no queued task owns.
     *
     * @param string $dir The source folder.
     * @return int How many decks were queued.
     */
    protected static function requeue_orphans(string $dir): int {
        $processing = $dir . DIRECTORY_SEPARATOR . self::DIR_PROCESSING;
        $files = self::list_files($processing);
        if (empty($files)) {
            return 0;
        }
        $owned = [];
        foreach (\core\task\manager::get_adhoc_tasks(task\import_slide_file::class) as $task) {
            $owned[(string) ($task->get_custom_data()->filepath ?? '')] = true;
        }
        $queued = 0;
        foreach (array_slice($files, 0, self::BATCH) as $name) {
            $path = $processing . DIRECTORY_SEPARATOR . $name;
            if (!isset($owned[$path])) {
                self::queue_file($path);
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Queue the import task for one deck in the processing subfolder.
     *
     * @param string $filepath Absolute path to the deck.
     */
    protected static function queue_file(string $filepath): void {
        $task = new task\import_slide_file();
        $task->set_custom_data(['filepath' => $filepath]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Import one deck that is waiting in the processing subfolder, then file it away.
     *
     * If the deck cannot be moved to processed or failed, it is renamed in place with a ".done"
     * suffix instead, so it is not queued and imported again.
     *
     * @param string $filepath Absolute path to the deck in the processing subfolder.
     * @return array Keys: error (string, empty on success), count (int, chapters or pages
     *               created), course, name, type (the cleaned target) and movedto (path; empty
     *               if renamed in place).
     */
    public static function import_queued_file(string $filepath): array {
        $targetfile = $filepath . self::TARGET_SUFFIX;
        $raw = is_file($targetfile) ? json_decode((string) file_get_contents($targetfile), true) : null;
        if (!is_array($raw) && self::get_mapping() === self::MAPPING_FILENAME) {
            // No target file (for example a deck put straight into processing): fall back to the
            // deck's own name, without the timestamp added when it was queued. Never do this for
            // the manifest mapping, where the name says nothing reliable about the course.
            $raw = self::parse_filename(preg_replace('/^\d{8}-\d{6}-/', '', basename($filepath)));
        }
        $target = self::clean_target(is_array($raw) ? $raw : []);
        $outcome = ['error' => '', 'count' => 0] + array_intersect_key($target, array_flip(['course', 'name', 'type']));
        if ($target['error'] !== '') {
            $outcome['error'] = get_string($target['error'], 'tool_automate', self::SEPARATOR);
        } else {
            try {
                $outcome['count'] = self::import_deck($filepath, $target);
            } catch (\Throwable $e) {
                $outcome['error'] = $e->getMessage();
            }
        }

        $base = dirname(dirname($filepath));
        $destdir = self::subfolder($base, $outcome['error'] === '' ? self::DIR_PROCESSED : self::DIR_FAILED);
        $dest = $destdir === null ? '' : $destdir . DIRECTORY_SEPARATOR . basename($filepath);
        $outcome['movedto'] = '';
        if ($dest !== '' && !file_exists($dest) && @rename($filepath, $dest)) {
            $outcome['movedto'] = $dest;
        } else if (@rename($filepath, $filepath . '.done')) {
            $dest = $filepath . '.done';
        } else {
            $dest = $filepath;
        }
        @unlink($targetfile);
        self::write_csv($dest . '.results.csv', [
            ['file', 'course', 'activity', 'type', 'status', 'created', 'message'],
            [
                basename($filepath),
                $outcome['course'],
                $outcome['name'],
                $outcome['type'],
                $outcome['error'] === '' ? 'ok' : 'error',
                $outcome['count'],
                $outcome['error'],
            ],
        ]);
        return $outcome;
    }

    /**
     * Import a deck into the activity its target names, creating the activity if needed.
     *
     * @param string $filepath Absolute path to the deck.
     * @param array $target A usable target from {@see self::clean_target()}.
     * @return int The number of chapters or pages created.
     */
    protected static function import_deck(string $filepath, array $target): int {
        $api = self::available_types()[$target['type']];
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($extension, $api::extensions(), true)) {
            throw new \moodle_exception('slideimport_badextension', 'tool_automate', '', $extension);
        }
        $course = self::find_course($target['course']);
        if ($course === null) {
            throw new \moodle_exception('slideimport_nocourse', 'tool_automate', '', $target['course']);
        }
        $cmid = $api::find_activity((int) $course->id, $target['name']);
        if (!$cmid) {
            $cmid = $api::create_activity((int) $course->id, $target['name'], $target['section']);
        }
        return $api::import_path($filepath, $cmid, ['importmode' => $target['mode']]);
    }

    /**
     * Return a subfolder of $dir, creating it if needed.
     *
     * @param string $dir Parent folder.
     * @param string $name Subfolder name.
     * @return string|null The subfolder path, or null if it cannot be created or written to.
     */
    protected static function subfolder(string $dir, string $name): ?string {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path) && !@mkdir($path, 0770)) {
            return null;
        }
        return is_writable($path) ? $path : null;
    }

    /**
     * Write rows to a CSV file, neutralising spreadsheet formulas in every cell.
     *
     * @param string $path Results file path.
     * @param array[] $rows
     */
    protected static function write_csv(string $path, array $rows): void {
        $handle = @fopen($path, 'w');
        if (!$handle) {
            return;
        }
        foreach ($rows as $row) {
            fputcsv($handle, action\action_base::csv_safe_row($row), ',', '"', '');
        }
        fclose($handle);
    }
}
