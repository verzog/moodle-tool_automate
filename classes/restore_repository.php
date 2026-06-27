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
 * Bulk restore from a repository directory.
 *
 * A "repository" here is a server-side directory the site admin nominates
 * (Site administration > Plugins > Admin tools > Settings) that holds Moodle
 * course backup files (.mbz). Both the web page (restore.php) and the CLI
 * (cli/restore_repository.php) call into this class so the listing, path
 * safety and queueing logic live in one tested place.
 *
 * Each selected backup is restored into a brand-new course in a chosen
 * category, in the background, via the restore_course adhoc task. Nothing is
 * restored inline - a directory of large backups would otherwise block the
 * request (or the cron worker) for a very long time.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_repository {
    /** File extension (lower-case, including the dot) of a Moodle course backup. */
    public const EXTENSION = '.mbz';

    /**
     * Whether the bulk-restore feature is switched on for the site.
     *
     * Off by default - this feature creates courses in bulk, so a site admin
     * has to opt in before the page or CLI will queue anything.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_automate', 'allow_bulk_restore');
    }

    /**
     * The configured source directory, with any trailing slash trimmed.
     *
     * @return string Absolute path, or '' when unset.
     */
    public static function get_source_dir(): string {
        $dir = trim((string) get_config('tool_automate', 'restore_source_dir'));
        return $dir === '' ? '' : rtrim($dir, '/\\');
    }

    /**
     * Is this a bare backup filename (no path components, .mbz extension)?
     *
     * @param string $name
     * @return bool
     */
    public static function is_backup_filename(string $name): bool {
        // Reject the empty string, anything with path components, and a bare
        // ".mbz" with no stem before the extension.
        if ($name === '' || $name !== basename($name) || strlen($name) <= strlen(self::EXTENSION)) {
            return false;
        }
        return strtolower(substr($name, -strlen(self::EXTENSION))) === self::EXTENSION;
    }

    /**
     * List the backup files available in a source directory.
     *
     * Returns bare filenames (basenames), sorted, so callers never have to
     * handle absolute paths - they pass a chosen basename back to resolve().
     *
     * @param string|null $dir Directory to scan; defaults to the configured one.
     * @return string[] Sorted list of .mbz basenames (empty if none / no dir).
     */
    public static function list_backups(?string $dir = null): array {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $files = [];
        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (self::is_backup_filename($entry) && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Resolve a chosen basename to an absolute path inside the source dir.
     *
     * Guards against path traversal: the name must be a bare .mbz basename and
     * the resolved real path must sit inside the real source directory. Returns
     * null for anything that fails those checks or does not exist.
     *
     * @param string $basename
     * @param string|null $dir Directory to resolve against; defaults to config.
     * @return string|null Absolute path, or null if invalid / missing.
     */
    public static function resolve(string $basename, ?string $dir = null): ?string {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !self::is_backup_filename($basename)) {
            return null;
        }
        $realdir = realpath($dir);
        $real = realpath($dir . DIRECTORY_SEPARATOR . $basename);
        if ($realdir === false || $real === false || !is_file($real)) {
            return null;
        }
        // Belt and braces on top of the basename check: the resolved file must
        // live directly under the configured directory.
        if (strpos($real, $realdir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * Queue one backup file for background restore into a new course.
     *
     * @param string $filepath Absolute path to a .mbz file (already resolved).
     * @param int $categoryid Target category for the new course.
     * @param int $userid User the restore runs as (the queueing admin).
     */
    public static function queue(string $filepath, int $categoryid, int $userid): void {
        $task = new task\restore_course();
        $task->set_custom_data([
            'filepath'   => $filepath,
            'categoryid' => $categoryid,
            'userid'     => $userid,
        ]);
        // Run the restore as the queueing admin so cron sets up $USER and the
        // restored course's "modified by" attribution is sensible.
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task);
    }
}
