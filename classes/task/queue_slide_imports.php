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
use tool_automate\watch_folder;

/**
 * Scheduled task that picks up slide decks dropped into the configured folder.
 *
 * Does nothing unless the folder import is switched on and an importer plugin is installed, and
 * says so in the task log when it does nothing.
 * Each run moves waiting decks into the "processing" subfolder and queues one
 * {@see import_slide_file} adhoc task per deck, so large folders are worked through one deck
 * at a time.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_slide_imports extends \core\task\scheduled_task {
    /**
     * Human-readable task name shown in the task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskqueueslideimports', 'tool_automate');
    }

    /**
     * Queue an import task for each waiting deck.
     */
    public function execute(): void {
        // Decks a watch-folder rule moved into processing but never managed to queue.
        if (watch_folder::is_enabled()) {
            $requeued = watch_folder::requeue_orphans();
            if ($requeued > 0) {
                mtrace('tool_automate: queued ' . $requeued . ' slide deck(s) left waiting in watch folders');
            }
        }
        // Always say why nothing was done, so an empty run can be told from a broken one.
        if (!slide_import::is_available()) {
            mtrace('tool_automate: slide folder import - nothing to do: no PowerPoint import plugin with the importer API is'
                . ' installed (Book 1.18.0 or later, or Lesson 1.13.0 or later)');
            return;
        }
        if (!slide_import::is_enabled()) {
            mtrace('tool_automate: slide folder import - nothing to do: "Allow slide folder import" is switched off');
            return;
        }
        $dir = slide_import::get_source_dir();
        $decks = count(slide_import::list_files($dir));
        $manifests = count(slide_import::list_manifests($dir));
        $outcome = slide_import::queue_waiting_files();
        if ($outcome['queued'] > 0) {
            mtrace('tool_automate: queued ' . $outcome['queued'] . ' slide deck(s) for import');
        }
        if ($outcome['error'] !== '') {
            mtrace('tool_automate: slide import - ' . $outcome['error']);
        }
        if ($outcome['queued'] === 0 && $outcome['error'] === '') {
            mtrace('tool_automate: slide folder import - ' . slide_import::describe_nothing_queued($decks, $manifests));
        }
    }
}
