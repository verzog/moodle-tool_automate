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
 * Bulk import of Canvas Common Cartridge (.imscc) packages, via tool_canvasuplifter.
 *
 * This is the Canvas counterpart to {@see restore_repository}: instead of
 * restoring Moodle .mbz backups, it drives the sibling Canvas Uplifter plugin
 * to convert many Canvas exports into Moodle courses at once - the workhorse
 * behind a large-scale Canvas-to-Moodle migration.
 *
 * Two package sources are supported, matching the two problems a migration hits:
 *  - a server directory of .imscc/.zip files (bulk upload from a staging area), and
 *  - a pasted list of Canvas backup download URLs (the packages are fetched,
 *    SSRF-safely, inside Canvas Uplifter's own background task).
 *
 * Two run modes decide what happens to each package:
 *  - "build" queues a Canvas Uplifter build job, so a course is created
 *    automatically; and
 *  - "analyse" queues an analyse-only job, so the package is fetched and a
 *    conversion report produced for later manual review and build.
 *
 * Nothing is done inline: every package becomes a background Canvas Uplifter
 * job, throttled by that plugin's own task concurrency, so a directory (or URL
 * list) of hundreds of courses cannot block the request or the cron worker pool.
 *
 * The whole feature is a soft integration - tool_automate does not declare a
 * hard dependency on tool_canvasuplifter, so it still installs on sites that do
 * not want Canvas import. {@see self::is_available()} gates the page, CLI and
 * settings on the sibling plugin actually being present.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class canvas_repository {
    /** Canvas Uplifter's launcher facade - the supported programmatic entry point. */
    public const LAUNCHER = '\\tool_canvasuplifter\\launcher';

    /** File extensions (lower-case, including the dot) a Canvas package may use. */
    public const EXTENSIONS = ['.imscc', '.zip'];

    /** Run mode: create the course now. Must match job_manager::KIND_BUILD. */
    public const MODE_BUILD = 'build';
    /** Run mode: fetch and analyse for later manual build. Matches KIND_ANALYSE. */
    public const MODE_ANALYSE = 'analyse';

    /** Package source: an absolute path to a file on the server's disk. */
    public const SOURCE_FILE = 'file';
    /** Package source: a remote http(s) URL Canvas Uplifter will fetch. */
    public const SOURCE_URL = 'url';

    /**
     * Is the Canvas Uplifter plugin installed, so we can drive it at all?
     *
     * Checked before the import page, CLI and settings expose anything, so the
     * feature simply does not appear on a site without tool_canvasuplifter,
     * rather than fatalling on a missing class.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return class_exists(self::LAUNCHER);
    }

    /**
     * Whether the bulk Canvas import feature is switched on for the site.
     *
     * Off by default - it creates courses in bulk, so a site admin has to opt in
     * before the page or CLI will queue anything. Mirrors the bulk-restore
     * kill-switch.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_automate', 'allow_bulk_canvas');
    }

    /**
     * Whether the installed Canvas Uplifter exposes the job-listing API.
     *
     * launcher::list_jobs() was added in Canvas Uplifter 0.42.0; guard on it so
     * the "Staged Canvas imports" page degrades gracefully (rather than
     * fatalling) on a site whose Canvas Uplifter predates it.
     *
     * @return bool
     */
    public static function jobs_listable(): bool {
        return self::is_available()
            && method_exists(self::LAUNCHER, 'list_jobs');
    }

    /**
     * List a user's Canvas Uplifter import jobs, newest first.
     *
     * Reads through Canvas Uplifter's public launcher facade, not its internal
     * job_manager.
     *
     * @param int $userid User whose jobs to list.
     * @param int $limit Maximum rows (0 = no limit).
     * @return array Job records keyed by id, or [] when Canvas Uplifter is
     *               absent or too old to list jobs.
     */
    public static function list_jobs(int $userid, int $limit = 0): array {
        if (!self::jobs_listable()) {
            return [];
        }
        $launcher = self::LAUNCHER;
        return $launcher::list_jobs($userid, null, null, $limit);
    }

    /**
     * Delete a Canvas import job and free its stored package, to reclaim space.
     *
     * Delegates to Canvas Uplifter's launcher, which frees the stored .imscc and
     * removes the job (leaving any built course in place) and only if the job
     * belongs to $userid. A no-op returning false when Canvas Uplifter is absent
     * or too old to support deletion.
     *
     * @param int $jobid Job to delete.
     * @param int $userid The job must belong to this user.
     * @return bool True if a job was deleted.
     */
    public static function delete_job(int $jobid, int $userid): bool {
        if (!self::deletion_supported()) {
            return false;
        }
        $launcher = self::LAUNCHER;
        return (bool) $launcher::delete_job($jobid, $userid);
    }

    /**
     * Whether the installed Canvas Uplifter can delete jobs.
     *
     * launcher::delete_job() arrived in Canvas Uplifter 0.43.0, after listing
     * (0.42.0); gate the delete UI on it so a site on 0.42.x does not show
     * delete controls that would silently no-op.
     *
     * @return bool
     */
    public static function deletion_supported(): bool {
        return self::is_available() && method_exists(self::LAUNCHER, 'delete_job');
    }

    /**
     * Total bytes of a user's stored Canvas packages, for a storage counter.
     *
     * @param int $userid User whose packages to total.
     * @return int|null Bytes, or null when Canvas Uplifter is absent or too old
     *                  to report storage usage.
     */
    public static function storage_used(int $userid): ?int {
        if (!self::is_available() || !method_exists(self::LAUNCHER, 'package_storage_used')) {
            return null;
        }
        $launcher = self::LAUNCHER;
        return (int) $launcher::package_storage_used($userid);
    }

    /**
     * The configured source directory, with any trailing slash trimmed.
     *
     * @return string Absolute path, or '' when unset.
     */
    public static function get_source_dir(): string {
        $dir = trim((string) get_config('tool_automate', 'canvas_source_dir'));
        return $dir === '' ? '' : rtrim($dir, '/\\');
    }

    /**
     * Is this a bare package filename (no path components, a Canvas extension)?
     *
     * @param string $name
     * @return bool
     */
    public static function is_package_filename(string $name): bool {
        // Reject the empty string and anything with path components.
        if ($name === '' || $name !== basename($name)) {
            return false;
        }
        $lower = \core_text::strtolower($name);
        foreach (self::EXTENSIONS as $ext) {
            // Require a non-empty stem before the extension.
            if (strlen($name) > strlen($ext) && substr($lower, -strlen($ext)) === $ext) {
                return true;
            }
        }
        return false;
    }

    /**
     * List the Canvas package files available in a source directory.
     *
     * Returns bare filenames (basenames), sorted, so callers never have to
     * handle absolute paths - they pass a chosen basename back to resolve().
     *
     * @param string|null $dir Directory to scan; defaults to the configured one.
     * @return string[] Sorted list of package basenames (empty if none / no dir).
     */
    public static function list_packages(?string $dir = null): array {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $files = [];
        foreach ((scandir($dir) ?: []) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (self::is_package_filename($entry) && is_file($dir . DIRECTORY_SEPARATOR . $entry)) {
                $files[] = $entry;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    /**
     * Resolve a chosen basename to an absolute path inside the source dir.
     *
     * Guards against path traversal: the name must be a bare package basename
     * and the resolved real path must sit inside the real source directory.
     * Returns null for anything that fails those checks, does not exist, or is
     * not readable by this process - queueing a package the importer cannot
     * open would only fail later, so it is rejected here.
     *
     * @param string $basename
     * @param string|null $dir Directory to resolve against; defaults to config.
     * @return string|null Absolute path, or null if invalid / missing / unreadable.
     */
    public static function resolve(string $basename, ?string $dir = null): ?string {
        $dir = $dir ?? self::get_source_dir();
        if ($dir === '' || !self::is_package_filename($basename)) {
            return null;
        }
        $realdir = realpath($dir);
        $real = realpath($dir . DIRECTORY_SEPARATOR . $basename);
        if ($realdir === false || $real === false || !is_file($real) || !is_readable($real)) {
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
     * Stable, cleaning-proof picker id for a package basename.
     *
     * The picker table submits ticked values through Moodle's parameter
     * cleaning, which can corrupt filenames containing commas, whitespace runs
     * or stripped characters. Using a hex hash as the option value keeps it
     * invariant under that cleaning; basename_for_token() maps it back.
     *
     * @param string $basename
     * @return string
     */
    public static function token(string $basename): string {
        return sha1($basename);
    }

    /**
     * Map a picker token back to the package basename it was generated from.
     *
     * @param string $token A value produced by token().
     * @param string|null $dir Directory to look in; defaults to config.
     * @return string|null The matching basename, or null if none in the dir.
     */
    public static function basename_for_token(string $token, ?string $dir = null): ?string {
        foreach (self::list_packages($dir) as $basename) {
            if (self::token($basename) === $token) {
                return $basename;
            }
        }
        return null;
    }

    /**
     * Parse a pasted block of Canvas backup URLs into a clean, de-duplicated list.
     *
     * Accepts one URL per line, ignores blank lines and #-comment lines, and
     * keeps only well-formed http(s) URLs. The real network safety (blocking
     * loopback/private ranges, port restrictions) is enforced downstream by
     * Canvas Uplifter's fetch, which routes through Moodle's cURL security
     * layer; this only filters out obvious junk before we queue a job per URL.
     *
     * @param string $text Raw textarea contents.
     * @return string[] Ordered, unique list of valid URLs.
     */
    public static function parse_urls(string $text): array {
        $urls = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (!preg_match('#^https?://#i', $line)) {
                continue;
            }
            // The PARAM_URL cleaner returns '' for anything not a valid URL.
            $clean = clean_param($line, PARAM_URL);
            if ($clean !== '' && !in_array($clean, $urls, true)) {
                $urls[] = $clean;
            }
        }
        return $urls;
    }

    /**
     * Normalise a submitted run mode to one of the two supported values.
     *
     * @param string $mode Candidate mode.
     * @return string self::MODE_BUILD or self::MODE_ANALYSE (the safe default).
     */
    public static function normalise_mode(string $mode): string {
        return $mode === self::MODE_BUILD ? self::MODE_BUILD : self::MODE_ANALYSE;
    }

    /**
     * Queue a background import of a package file on the server's disk.
     *
     * @param string $filepath Absolute path to a resolved .imscc/.zip file.
     * @param int $categoryid Target category for the new course.
     * @param int $userid User the job runs as (the queueing admin).
     * @param string $mode self::MODE_BUILD or self::MODE_ANALYSE.
     * @param bool $quizfrombank Also build a quiz from each standalone question bank.
     * @param string $pagegrouping '' | 'book' | 'lesson' page-combining option.
     * @return void
     */
    public static function queue_file(
        string $filepath,
        int $categoryid,
        int $userid,
        string $mode,
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ): void {
        self::queue(self::SOURCE_FILE, $filepath, $categoryid, $userid, $mode, $quizfrombank, $pagegrouping);
    }

    /**
     * Queue a background import of a package identified by a remote URL.
     *
     * @param string $url A validated http(s) package URL.
     * @param int $categoryid Target category for the new course.
     * @param int $userid User the job runs as (the queueing admin).
     * @param string $mode self::MODE_BUILD or self::MODE_ANALYSE.
     * @param bool $quizfrombank Also build a quiz from each standalone question bank.
     * @param string $pagegrouping '' | 'book' | 'lesson' page-combining option.
     * @return void
     */
    public static function queue_url(
        string $url,
        int $categoryid,
        int $userid,
        string $mode,
        bool $quizfrombank = false,
        string $pagegrouping = ''
    ): void {
        self::queue(self::SOURCE_URL, $url, $categoryid, $userid, $mode, $quizfrombank, $pagegrouping);
    }

    /**
     * Queue one import as a tool_automate adhoc task.
     *
     * The task - not this method - creates the Canvas Uplifter job, and it
     * re-checks the kill-switch (and that Canvas Uplifter is still installed) at
     * run time. So an admin who disables "Allow bulk Canvas import" after
     * queueing the wrong batch stops the Canvas Uplifter jobs from ever being
     * created, mirroring how the bulk-restore kill-switch drains its queue.
     * Deferring also keeps a large directory or URL list off the web request.
     *
     * @param string $sourcetype self::SOURCE_FILE or self::SOURCE_URL.
     * @param string $source Absolute file path or package URL.
     * @param int $categoryid Target category for the new course.
     * @param int $userid User the job runs as.
     * @param string $mode self::MODE_BUILD or self::MODE_ANALYSE.
     * @param bool $quizfrombank Also build a quiz from each standalone question bank.
     * @param string $pagegrouping '' | 'book' | 'lesson' page-combining option.
     * @return void
     */
    protected static function queue(
        string $sourcetype,
        string $source,
        int $categoryid,
        int $userid,
        string $mode,
        bool $quizfrombank,
        string $pagegrouping
    ): void {
        $task = new task\import_canvas();
        $task->set_custom_data([
            'sourcetype' => $sourcetype,
            'source' => $source,
            'categoryid' => $categoryid,
            'userid' => $userid,
            'mode' => self::normalise_mode($mode),
            'quizfrombank' => $quizfrombank ? 1 : 0,
            'pagegrouping' => $pagegrouping,
        ]);
        // Run as the queueing admin so cron sets up $USER and the created
        // course's attribution is sensible.
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task);
    }
}
