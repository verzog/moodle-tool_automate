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

use tool_automate\slide_import;

/**
 * Adhoc task that imports one slide deck into a Book or Lesson activity.
 *
 * Runs one at a time. The deck waits in the "processing" subfolder until this task runs, then
 * moves to "processed" (or "failed" if it could not be imported) with a results CSV beside it.
 * A failed deck is not retried: fix the cause and drop the file into the folder again.
 *
 * The task's data holds only the deck's path, so there is no personal data to export or delete.
 * Security-lint: ignore S17 (the task data is a server file path, not personal data).
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_slide_file extends \core\task\adhoc_task {
    /**
     * One import at a time: rendering slides is heavy, and a folder of decks must not crowd
     * out other adhoc tasks.
     *
     * @return int
     */
    protected function get_default_concurrency_limit(): int {
        return 1;
    }

    /**
     * Import the deck.
     */
    public function execute(): void {
        $data = (object) $this->get_custom_data();
        $filepath = (string) ($data->filepath ?? '');

        // The deck may have been moved or removed after it was queued: skip it.
        if ($filepath === '' || !is_file($filepath)) {
            mtrace('tool_automate: slide deck ' . $filepath . ' is gone, skipping');
            return;
        }
        // Only ever import from the configured folder's own processing subfolder, whatever the
        // queued data says.
        $expected = slide_import::get_source_dir() . DIRECTORY_SEPARATOR . slide_import::DIR_PROCESSING;
        if (dirname($filepath) !== $expected || !slide_import::is_deck_filename(basename($filepath))) {
            mtrace('tool_automate: ' . $filepath . ' is not in the slide import folder, skipping');
            return;
        }
        // Re-check at run time: an admin who switches the import off, or uninstalls the
        // importers, expects queued work to stop. The deck stays in "processing", and the
        // scheduled task queues it again once the import is switched back on.
        if (!slide_import::is_available() || !slide_import::is_enabled()) {
            mtrace('tool_automate: slide import is switched off, leaving ' . $filepath . ' in place');
            return;
        }

        $outcome = slide_import::import_queued_file($filepath);
        if ($outcome['error'] !== '') {
            mtrace('tool_automate: slide import of ' . basename($filepath) . ' failed - ' . $outcome['error']);
        } else {
            mtrace('tool_automate: imported ' . basename($filepath) . ' - created ' . $outcome['count']
                . ' ' . $outcome['type'] . ' page(s) in "' . $outcome['name'] . '"');
        }
        if ($outcome['movedto'] === '') {
            mtrace('tool_automate: could not move ' . $filepath . ' out of the processing folder, renamed it to .done');
        }
    }
}
