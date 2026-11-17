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

namespace tool_automate\admin;

use tool_automate\watch_folder;

/**
 * The watch folders base folder setting, which also lists the watch folders it found.
 *
 * Seeing the list on the settings page shows an administrator straight away which folders a
 * rule will be able to pick, instead of finding out in the rule editor.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_watch_base_dir extends setting_restore_source_dir {
    /**
     * Build the readability badge, followed by the watch folders found for the saved path.
     *
     * @param string $path The configured directory path.
     * @return string Badge HTML, or '' when no path is set.
     */
    protected function status_html(string $path): string {
        $status = parent::status_html($path);
        $path = rtrim(trim($path), '/\\');
        // The list reflects the saved setting, so only show it for the saved path.
        if ($status === '' || $path !== watch_folder::get_base_dir()) {
            return $status;
        }
        $labels = array_map([watch_folder::class, 'folder_label'], watch_folder::list_folders());
        if (empty($labels)) {
            return $status . \html_writer::div(s(get_string('watchfolder_nonefound', 'tool_automate')), 'text-danger');
        }
        $found = get_string('watchfolder_found', 'tool_automate', implode(', ', $labels));
        return $status . \html_writer::div(s($found), 'text-muted');
    }
}
