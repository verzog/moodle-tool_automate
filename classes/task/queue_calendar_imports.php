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

use tool_automate\calendar_import;

/**
 * Scheduled task that picks up calendar CSV/TSV files dropped into the configured folder.
 *
 * Does nothing unless the folder import is switched on and local_grpcalendarimport is installed.
 * Each run moves up to calendar_import::BATCH files into the "processing" subfolder and queues
 * one {@see import_calendar_file} adhoc task per file, so large folders are worked through in
 * small, throttled batches.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_calendar_imports extends \core\task\scheduled_task {
    /**
     * Human-readable task name shown in the task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskqueuecalendarimports', 'tool_automate');
    }

    /**
     * Queue an import task for each waiting file.
     */
    public function execute(): void {
        if (!calendar_import::is_available() || !calendar_import::is_enabled()) {
            return;
        }
        $outcome = calendar_import::queue_waiting_files();
        if ($outcome['queued'] > 0) {
            mtrace('tool_automate: queued ' . $outcome['queued'] . ' calendar import file(s)');
        }
        if ($outcome['error'] !== '') {
            mtrace('tool_automate: calendar import - ' . $outcome['error']);
        }
    }
}
