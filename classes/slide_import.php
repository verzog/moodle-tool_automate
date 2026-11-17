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
 * Importing slide decks from watch folders, via booktool_importpptx and local_lessonimportpptx.
 *
 * A soft integration, like {@see calendar_import}: tool_automate does not declare a hard
 * dependency on either importer. When at least one of them is installed with its API class, a
 * "Find files that..." rule can import PowerPoint (and, where the server supports it, PDF) files
 * from a watch folder into a Book or Lesson activity in a course the rule chooses.
 *
 * This class does the part that follows the rule's decision. The rule's action moves a deck
 * from its watch folder into that folder's "processing" subfolder, writes a small target file
 * beside it saying where it goes, and queues one adhoc task for it. The task imports the deck
 * and moves it to "processed" or "failed", writing a results CSV beside it either way.
 *
 * Everything read back from a folder (file names and target files) is treated as untrusted
 * input: names must be bare file names, and every value is checked again before use.
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
    /** Extensions (lower-case, no dot) the importers can ever accept. */
    public const DECK_EXTENSIONS = ['pptx', 'pdf'];

    /** Most decks moved into processing and queued by one scheduled run, whichever the mapping. */
    public const BATCH = 50;

    /** Suffix of the target file written beside a deck in the processing subfolder. */
    public const TARGET_SUFFIX = '.target.json';

    /** Suffix of every results file. */
    public const RESULTS_SUFFIX = '.results.csv';

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
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            // A link could point anywhere on the server, so only real files count.
            if (self::is_safe_filename($entry, $extensions) && is_file($path) && !is_link($path)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Check and tidy a target read back from a target file.
     *
     * @param array $raw Keys courseid, name, type and optionally section, mode.
     * @return array Keys courseid, name, type, section, mode, plus error (a lang string key in
     *               tool_automate, empty when the target is usable).
     */
    public static function clean_target(array $raw): array {
        $text = function ($value): string {
            return is_scalar($value) ? trim(clean_param((string) $value, PARAM_TEXT)) : '';
        };
        $mode = strtolower($text($raw['mode'] ?? ''));
        $target = [
            'courseid' => max(0, (int) $text($raw['courseid'] ?? '')),
            'name' => \core_text::substr($text($raw['name'] ?? ''), 0, 255),
            'type' => strtolower($text($raw['type'] ?? '')),
            'section' => max(0, (int) $text($raw['section'] ?? '')),
            'mode' => $mode === 'images' ? 'images' : 'editable',
            'error' => '',
        ];
        if ($target['courseid'] === 0 || $target['name'] === '') {
            $target['error'] = 'slideimport_notarget';
        } else if (!isset(self::available_types()[$target['type']])) {
            $target['error'] = 'slideimport_badtype';
        }
        return $target;
    }

    /**
     * Move one deck into the processing subfolder, record its target and queue its import.
     *
     * @param string $dir The source folder.
     * @param string $name The deck's file name.
     * @param array $target Keys courseid, name, type and optionally section, mode.
     * @return bool False when the deck could not be moved; it is left where it was.
     */
    public static function queue_deck(string $dir, string $name, array $target): bool {
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
        $stored = array_intersect_key($target, array_flip(['courseid', 'name', 'type', 'section', 'mode']));
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
    public static function requeue_orphans(string $dir): int {
        $processing = $dir . DIRECTORY_SEPARATOR . self::DIR_PROCESSING;
        // Never follow a linked processing subfolder out of the folder.
        $files = is_link($processing) ? [] : self::list_files($processing);
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
     * May a queued deck at this path be imported now?
     *
     * A deck is only ever imported from the "processing" subfolder of a watch folder, whatever
     * the queued task data says, and only while watch folders are switched on.
     *
     * @param string $filepath Absolute path from the queued task.
     * @return string 'ok', 'off' (watch folders are switched off: leave the deck where it is)
     *                or 'outside' (not a path Automate imports from).
     */
    public static function check_queued_path(string $filepath): string {
        $processing = dirname($filepath);
        if (basename($processing) !== self::DIR_PROCESSING || !self::is_deck_filename(basename($filepath))) {
            return 'outside';
        }
        // The path must be what it looks like: a link here could reach anywhere on the server.
        if (is_link($processing) || is_link($filepath)) {
            return 'outside';
        }
        $owner = dirname($processing);
        $iswatchfolder = watch_folder::folder_path(watch_folder::BASE) === $owner
            || watch_folder::folder_path(basename($owner)) === $owner;
        if (!$iswatchfolder) {
            return 'outside';
        }
        return watch_folder::is_enabled() ? 'ok' : 'off';
    }

    /**
     * Import one deck that is waiting in the processing subfolder, then file it away.
     *
     * If the deck cannot be moved to processed or failed, it is renamed in place with a ".done"
     * suffix instead, so it is not queued and imported again.
     *
     * @param string $filepath Absolute path to the deck in the processing subfolder.
     * @return array Keys: error (string, empty on success), count (int, chapters or pages
     *               created), course (its short name), name, type and movedto (path; empty if
     *               renamed in place).
     */
    public static function import_queued_file(string $filepath): array {
        $targetfile = $filepath . self::TARGET_SUFFIX;
        $readable = is_file($targetfile) && !is_link($targetfile);
        $raw = $readable ? json_decode((string) file_get_contents($targetfile), true) : null;
        // With no readable target file there is nothing to say where the deck goes, so it fails.
        $target = self::clean_target(is_array($raw) ? $raw : []);
        $outcome = ['error' => '', 'count' => 0, 'course' => ''] + array_intersect_key($target, array_flip(['name', 'type']));
        if ($target['error'] !== '') {
            $outcome['error'] = get_string($target['error'], 'tool_automate');
        } else {
            try {
                $course = self::get_target_course($target['courseid']);
                $outcome['course'] = $course->shortname;
                $outcome['count'] = self::import_deck($filepath, $target, $course);
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
        self::write_csv($dest . self::RESULTS_SUFFIX, [
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
     * The course a target names.
     *
     * @param int $courseid
     * @return \stdClass The course record.
     */
    protected static function get_target_course(int $courseid): \stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('slideimport_nocourse', 'tool_automate', '', $courseid);
        }
        return $course;
    }

    /**
     * Import a deck into the activity its target names, creating the activity if needed.
     *
     * @param string $filepath Absolute path to the deck.
     * @param array $target A usable target from {@see self::clean_target()}.
     * @param \stdClass $course The course the target names.
     * @return int The number of chapters or pages created.
     */
    protected static function import_deck(string $filepath, array $target, \stdClass $course): int {
        $api = self::available_types()[$target['type']];
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!in_array($extension, $api::extensions(), true)) {
            throw new \moodle_exception('slideimport_badextension', 'tool_automate', '', $extension);
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
     * @return string|null The subfolder path, or null if it is a link, or cannot be created or
     *                     written to.
     */
    protected static function subfolder(string $dir, string $name): ?string {
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0770))) {
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
