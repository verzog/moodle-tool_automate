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

namespace tool_automate\task;

/**
 * Adhoc task: restore one course backup (.mbz) into a new course.
 *
 * Queued by the bulk restore page / CLI so a directory of many backups can be
 * kicked off at once without blocking the request (or the cron worker) on a
 * long chain of synchronous restores. Each backup becomes a brand-new course
 * in the chosen category (restore target TARGET_NEW_COURSE) - existing courses
 * are never touched.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_course extends \core\task\adhoc_task {
    /**
     * Cap how many restore_course tasks Moodle's cron will run in parallel.
     *
     * Reads tool_automate / restore_concurrency (defaults to 2). A bulk
     * restore of a large repository can queue hundreds of these and each
     * restore is heavy (file extraction, precheck, full restore plan); without
     * a cap the cron worker pool gets dominated by them and other queued work
     * stalls. Admins who want them to run faster can raise the value from
     * Site administration > Plugins > Admin tools > Settings.
     *
     * Overrides get_default_concurrency_limit() rather than
     * get_concurrency_limit(): the latter is final in core, so overriding it
     * fatals on load. A per-class $CFG override still wins over this.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        $configured = (int) get_config('tool_automate', 'restore_concurrency');
        return $configured > 0 ? $configured : 2;
    }

    /**
     * Run the restore.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $data = (object) $this->get_custom_data();
        $filepath = (string) ($data->filepath ?? '');
        $categoryid = (int) ($data->categoryid ?? 0);
        $userid = (int) ($data->userid ?? 0);

        // The file may have been moved or removed between queue and execute -
        // that is not an error worth aborting the queue over, just skip it.
        if ($filepath === '' || !is_file($filepath)) {
            mtrace('tool_automate: backup file ' . $filepath . ' is gone, skipping restore');
            return;
        }
        if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
            mtrace('tool_automate: target category ' . $categoryid . ' is gone, skipping restore of ' . $filepath);
            return;
        }

        // A course restore (lots of files, activities, grades) can easily
        // outrun the default PHP time limit; raise it before we start.
        \core_php_time_limit::raise(0);

        // Extract the .mbz into a uniquely-named backup temp directory. The
        // restore_controller is given that directory's name (relative to the
        // backup temp area), mirroring core's admin/cli/restore_backup.php.
        $backupdir = 'tool_automate_restore_' . uniqid();
        $path = make_backup_temp_directory($backupdir);
        $packer = get_file_packer('application/vnd.moodle.backup');
        $packer->extract_to_pathname($filepath, $path);

        mtrace("tool_automate: restoring '" . basename($filepath) . "' into a new course in category " . $categoryid);

        // Empty shell course; the restore overwrites name/settings from the
        // backup because the target is TARGET_NEW_COURSE.
        $courseid = \restore_dbops::create_new_course('', '', $categoryid);

        $rc = new \restore_controller(
            $backupdir,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $userid,
            \backup::TARGET_NEW_COURSE
        );

        try {
            if (!$rc->execute_precheck()) {
                $results = $rc->get_precheck_results();
                if (!empty($results['errors'])) {
                    // Roll back the shell course so a failed precheck does not
                    // leave an empty husk behind, then surface the failure.
                    $rc->destroy();
                    delete_course($courseid, false);
                    fix_course_sortorder();
                    fulldelete($path);
                    throw new \moodle_exception(
                        'restorefailed',
                        'tool_automate',
                        '',
                        basename($filepath),
                        implode('; ', array_map('strval', $results['errors']))
                    );
                }
                // Warnings only - log them and carry on, as the restore UI does.
                if (!empty($results['warnings'])) {
                    foreach ($results['warnings'] as $warning) {
                        mtrace('  restore warning: ' . $warning);
                    }
                }
            }
            $rc->execute_plan();
            $rc->destroy();
        } finally {
            // The controller copies what it needs out of the temp dir; clean it
            // up whether the restore succeeded or threw.
            fulldelete($path);
        }

        fix_course_sortorder();
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname');
        $shortname = $course ? $course->shortname : ('#' . $courseid);
        mtrace("tool_automate: restored '" . basename($filepath) . "' as course '" . $shortname . "' (id " . $courseid . ')');
    }
}
