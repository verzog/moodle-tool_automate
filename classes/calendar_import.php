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
 * Group calendar events, via local_grpcalendarimport.
 *
 * A soft integration, like {@see canvas_repository}: tool_automate does not declare a hard
 * dependency on local_grpcalendarimport. When that plugin (v1.2.0 or later, which ships the
 * importer API class) is installed, two features appear:
 *  - the "Create group calendar event" course action; and
 *  - a scheduled import of CSV/TSV files dropped into a server folder.
 *
 * The folder import works in place. Each run moves up to {@see self::BATCH} files from the
 * folder into its "processing" subfolder (so the next run cannot pick them up again) and queues
 * one adhoc task per file. The task imports the file and moves it to "processed", or to "failed"
 * when the file itself could not be read, writing a results CSV beside it either way.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class calendar_import {
    /** The importer API class provided by local_grpcalendarimport. */
    public const IMPORTER = '\\local_grpcalendarimport\\importer';

    /** Lowest importer API version this integration supports. */
    public const MIN_API_VERSION = 1;

    /** Capability the importer plugin uses for its own upload page. */
    public const CAPABILITY = 'local/grpcalendarimport:manage';

    /** File extensions (lower-case, including the dot) the folder import picks up. */
    public const EXTENSIONS = ['.csv', '.tsv'];

    /** Most files moved into processing and queued by one scheduled run. */
    public const BATCH = 20;

    /** Subfolder for files queued but not yet imported. */
    public const DIR_PROCESSING = 'processing';
    /** Subfolder for imported files and their results. */
    public const DIR_PROCESSED = 'processed';
    /** Subfolder for files that could not be imported at all. */
    public const DIR_FAILED = 'failed';

    /**
     * Is a compatible local_grpcalendarimport installed?
     *
     * @return bool
     */
    public static function is_available(): bool {
        $class = self::IMPORTER;
        return class_exists($class) && defined($class . '::API_VERSION')
            && (int) constant($class . '::API_VERSION') >= self::MIN_API_VERSION;
    }

    /**
     * Whether the scheduled folder import is switched on for the site.
     *
     * Off by default: it creates events in bulk, so a site admin opts in first.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_automate', 'allow_calendar_import');
    }

    /**
     * Whether the folder import skips events that already exist.
     *
     * @return bool
     */
    public static function skip_duplicates(): bool {
        $value = get_config('tool_automate', 'calendar_skip_duplicates');
        // Not saved yet means the default, which is on.
        return $value === false ? true : (bool) $value;
    }

    /**
     * The configured source folder, without a trailing separator.
     *
     * @return string Empty when not configured.
     */
    public static function get_source_dir(): string {
        $dir = trim((string) get_config('tool_automate', 'calendar_source_dir'));
        return $dir === '' ? '' : rtrim($dir, '/\\');
    }

    /**
     * Is this a bare import filename (no path components, .csv or .tsv extension)?
     *
     * @param string $name
     * @return bool
     */
    public static function is_import_filename(string $name): bool {
        if ($name === '' || $name !== basename($name) || $name[0] === '.') {
            return false;
        }
        $lower = strtolower($name);
        foreach (self::EXTENSIONS as $extension) {
            if (strlen($lower) > strlen($extension) && substr($lower, -strlen($extension)) === $extension) {
                return true;
            }
        }
        return false;
    }

    /**
     * List the import files waiting in a folder (top level only).
     *
     * @param string|null $dir Folder to scan; defaults to the configured one.
     * @return string[] Sorted basenames.
     */
    public static function list_files(?string $dir = null): array {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $files = [];
        foreach ((scandir($dir) ?: []) as $entry) {
            if (self::is_import_filename($entry) && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Move waiting files into the processing subfolder and queue an import task for each.
     *
     * Files already in processing that no queued task owns (for example after a crash between the
     * move and the queueing, or a run while the import was switched off) are queued again first.
     *
     * @param string|null $dir Folder to scan; defaults to the configured one.
     * @return array Keys: queued (int), error (string, empty when every waiting file was queued).
     */
    public static function queue_waiting_files(?string $dir = null): array {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !is_dir($dir)) {
            return ['queued' => 0, 'error' => get_string('calendarimport_nodir', 'tool_automate')];
        }
        if (!is_readable($dir)) {
            return ['queued' => 0, 'error' => get_string('calendarimport_notreadable', 'tool_automate', $dir)];
        }

        $queued = self::requeue_orphans($dir);
        $files = array_slice(self::list_files($dir), 0, max(0, self::BATCH - $queued));
        if (empty($files)) {
            return ['queued' => $queued, 'error' => ''];
        }
        // Moving a file out of the folder needs write access to the folder itself.
        $processing = is_writable($dir) ? self::subfolder($dir, self::DIR_PROCESSING) : null;
        if ($processing === null) {
            return ['queued' => $queued, 'error' => get_string('calendarimport_notwritable', 'tool_automate', $dir)];
        }

        $failed = 0;
        foreach ($files as $name) {
            // A timestamp prefix keeps a re-dropped file with the same name from overwriting one
            // still waiting in processing.
            $source = $dir . DIRECTORY_SEPARATOR . $name;
            $target = $processing . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . $name;
            if (file_exists($target) || !@rename($source, $target)) {
                $failed++;
                continue;
            }
            try {
                self::queue_file($target);
            } catch (\Throwable $e) {
                // Put the file back so the next run picks it up again, then let the task fail.
                @rename($target, $source);
                throw $e;
            }
            $queued++;
        }
        $error = $failed ? get_string('calendarimport_movefailed', 'tool_automate', $failed) : '';
        return ['queued' => $queued, 'error' => $error];
    }

    /**
     * Queue an import task for files in the processing subfolder that no queued task owns.
     *
     * A queued (or running, or failed and waiting to retry) import task keeps its record until it
     * finishes, and it moves its file out of processing before then, so an unowned file was never
     * queued or was left behind on purpose.
     *
     * @param string $dir The source folder.
     * @return int How many files were queued.
     */
    protected static function requeue_orphans(string $dir): int {
        $processing = $dir . DIRECTORY_SEPARATOR . self::DIR_PROCESSING;
        $files = self::list_files($processing);
        if (empty($files)) {
            return 0;
        }
        $owned = [];
        foreach (\core\task\manager::get_adhoc_tasks(task\import_calendar_file::class) as $task) {
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
     * Queue the import task for one file in the processing subfolder.
     *
     * @param string $filepath Absolute path to the file.
     */
    protected static function queue_file(string $filepath): void {
        $task = new task\import_calendar_file();
        $task->set_custom_data(['filepath' => $filepath]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Import one file that is waiting in the processing subfolder, then file it away.
     *
     * If the file cannot be moved to processed or failed, it is renamed in place with a ".done"
     * suffix instead, so it is not queued and imported again.
     *
     * @param string $filepath Absolute path to the file in the processing subfolder.
     * @return array The importer's import_file() outcome, plus 'movedto' (path; empty if renamed in place).
     */
    public static function import_queued_file(string $filepath): array {
        $class = self::IMPORTER;
        $outcome = $class::import_file($filepath, self::skip_duplicates());

        $base = dirname(dirname($filepath));
        $destdir = self::subfolder($base, $outcome['error'] === '' ? self::DIR_PROCESSED : self::DIR_FAILED);
        $outcome['movedto'] = '';
        if ($destdir !== null) {
            $dest = $destdir . DIRECTORY_SEPARATOR . basename($filepath);
            if (@rename($filepath, $dest)) {
                $outcome['movedto'] = $dest;
                self::write_results($dest . '.results.csv', $outcome);
                return $outcome;
            }
        }
        if (@rename($filepath, $filepath . '.done')) {
            self::write_results($filepath . '.done.results.csv', $outcome);
        }
        return $outcome;
    }

    /**
     * Find groups in a course whose name matches a wildcard pattern.
     *
     * @param int $courseid Course id.
     * @param string $pattern Wildcard pattern ("*" matches anything; no "*" means "contains").
     *                        Blank matches every group.
     * @return \stdClass[] Group records keyed by id, ordered by name.
     */
    public static function matching_groups(int $courseid, string $pattern): array {
        global $DB;
        $pattern = trim($pattern);
        $groups = $DB->get_records('groups', ['courseid' => $courseid], 'name, id');
        if ($pattern === '') {
            return $groups;
        }
        return array_filter($groups, function ($group) use ($pattern) {
            return condition\condition_base::wildcard_match($pattern, (string) $group->name);
        });
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
     * Write an import outcome to a CSV file beside the imported file.
     *
     * @param string $path Results file path.
     * @param array $outcome The importer's import_file() outcome.
     */
    protected static function write_results(string $path, array $outcome): void {
        $handle = @fopen($path, 'w');
        if (!$handle) {
            return;
        }
        fputcsv($handle, ['row', 'status', 'message'], ',', '"', '');
        if ($outcome['error'] !== '') {
            fputcsv($handle, [0, 'error', $outcome['error']], ',', '"', '');
        }
        foreach ($outcome['results'] as $result) {
            fputcsv($handle, [$result['row'], $result['status'], $result['message']], ',', '"', '');
        }
        fclose($handle);
    }
}
