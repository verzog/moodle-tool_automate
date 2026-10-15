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
 * Adhoc task that imports one calendar CSV/TSV file through local_grpcalendarimport.
 *
 * Runs one at a time. The file waits in the "processing" subfolder until this task runs, then
 * moves to "processed" (or "failed" if it could not be read at all) with a results CSV beside it.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_calendar_file extends \core\task\adhoc_task {
    /**
     * One import at a time, so a folder of files cannot crowd out other adhoc tasks.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Import the file.
     */
    public function execute(): void {
        $data = (object) $this->get_custom_data();
        $filepath = (string) ($data->filepath ?? '');

        // The file may have been moved or removed after it was queued: skip it.
        if ($filepath === '' || !is_file($filepath)) {
            mtrace('tool_automate: calendar import file ' . $filepath . ' is gone, skipping');
            return;
        }
        // Re-check at run time: an admin who switches the import off, or uninstalls the importer,
        // expects queued work to stop. The file stays in "processing" for them to deal with.
        if (!calendar_import::is_available() || !calendar_import::is_enabled()) {
            mtrace('tool_automate: calendar import is switched off, leaving ' . $filepath . ' in place');
            return;
        }

        $outcome = calendar_import::import_queued_file($filepath);
        if ($outcome['error'] !== '') {
            mtrace('tool_automate: calendar import of ' . basename($filepath) . ' failed - ' . $outcome['error']);
        } else {
            $counts = $outcome['counts'];
            mtrace('tool_automate: imported ' . basename($filepath) . ' - created ' . $counts['ok']
                . ', skipped ' . $counts['skip'] . ', errors ' . $counts['error']);
        }
        if ($outcome['movedto'] === '') {
            mtrace('tool_automate: could not move ' . $filepath . ' out of the processing folder');
        }
    }
}
