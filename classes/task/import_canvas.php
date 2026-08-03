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

use tool_automate\canvas_repository;

/**
 * Adhoc task: create one Canvas Uplifter job for a bulk-import selection.
 *
 * Queued by the bulk Canvas import page / CLI (via canvas_repository::queue_*)
 * so a directory or URL list of many packages can be kicked off at once without
 * blocking the request, and - crucially - so the kill-switch is re-checked here
 * at run time. An admin who disables "Allow bulk Canvas import" after queueing
 * the wrong batch therefore stops the Canvas Uplifter jobs from ever being
 * created, mirroring how the bulk-restore task drains its queue when its
 * kill-switch is turned off.
 *
 * The actual conversion (fetch, unzip, parse, build) happens in Canvas
 * Uplifter's own throttled adhoc task, which this task hands the package to.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_canvas extends \core\task\adhoc_task {
    /**
     * Human-readable name shown in Server > Tasks > Task logs.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskimportcanvas', 'tool_automate');
    }

    /**
     * Create the Canvas Uplifter job for this selection.
     *
     * @return void
     */
    public function execute() {
        global $DB;

        $data = (object) $this->get_custom_data();
        $sourcetype = (string) ($data->sourcetype ?? '');
        $source = (string) ($data->source ?? '');
        $categoryid = (int) ($data->categoryid ?? 0);
        $userid = (int) ($data->userid ?? 0);
        $mode = canvas_repository::normalise_mode((string) ($data->mode ?? ''));
        $quizfrombank = !empty($data->quizfrombank);
        $pagegrouping = (string) ($data->pagegrouping ?? '');

        // Canvas Uplifter does the conversion; without it there is nothing to do.
        if (!canvas_repository::is_available()) {
            mtrace('tool_automate: tool_canvasuplifter is not installed, skipping Canvas import of ' . $source);
            return;
        }
        // Re-check the site kill-switch at run time so turning "Allow bulk Canvas
        // import" back off stops queued work, rather than draining it out anyway.
        if (!canvas_repository::is_enabled()) {
            mtrace('tool_automate: bulk Canvas import is switched off, skipping import of ' . $source);
            return;
        }
        if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
            mtrace('tool_automate: target category ' . $categoryid . ' is gone, skipping import of ' . $source);
            return;
        }

        $launcher = canvas_repository::LAUNCHER;
        if ($sourcetype === canvas_repository::SOURCE_FILE) {
            // The file may have moved or become unreadable between queue and run;
            // that is not worth failing the queue over, so skip it.
            if ($source === '' || !is_file($source) || !is_readable($source)) {
                mtrace('tool_automate: package file ' . $source . ' is gone or unreadable, skipping Canvas import');
                return;
            }
            $jobid = (int) $launcher::queue_from_path(
                $userid,
                $categoryid,
                $mode,
                $source,
                '',
                $quizfrombank,
                $pagegrouping
            );
        } else {
            $jobid = (int) $launcher::queue_from_url(
                $userid,
                $categoryid,
                $mode,
                $source,
                $quizfrombank,
                $pagegrouping
            );
        }

        mtrace("tool_automate: queued Canvas Uplifter {$mode} job {$jobid} for " . $source);
    }
}
