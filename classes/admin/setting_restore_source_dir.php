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

/**
 * Text setting for the bulk-restore source directory that shows whether the
 * configured path is a directory Moodle can read.
 *
 * Renders a green tick ("readable") or a red cross ("not found or not
 * readable") underneath the field, so an admin can confirm at a glance that
 * the web server user can see the directory before relying on the restore
 * page or CLI. The check reflects the value currently saved/displayed; it is
 * advisory and does not block saving.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_restore_source_dir extends \admin_setting_configtext {
    /**
     * Render the field, appending a readability status line to the description.
     *
     * @param mixed $data The current setting value.
     * @param string $query Search query that led here (for highlighting).
     * @return string The HTML for the setting row.
     */
    public function output_html($data, $query = ''): string {
        $status = $this->status_html((string) $data);
        if ($status === '') {
            return parent::output_html($data, $query);
        }
        // Temporarily append the status to the description so it renders in the
        // setting's normal description cell without re-implementing the markup.
        $original = $this->description;
        $this->description = $original . $status;
        $html = parent::output_html($data, $query);
        $this->description = $original;
        return $html;
    }

    /**
     * Build the readability badge for a configured path.
     *
     * @param string $path The configured directory path.
     * @return string Badge HTML, or '' when no path is set.
     */
    protected function status_html(string $path): string {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (is_dir($path) && is_readable($path)) {
            return $this->badge("\u{2713}", 'text-success', get_string('restoredirreadable', 'tool_automate'));
        }
        return $this->badge("\u{2717}", 'text-danger', get_string('restoredirunreadable', 'tool_automate'));
    }

    /**
     * Render a coloured glyph + label badge.
     *
     * @param string $glyph Leading status glyph (tick or cross).
     * @param string $class Bootstrap text colour class.
     * @param string $label Status label.
     * @return string Badge HTML.
     */
    protected function badge(string $glyph, string $class, string $label): string {
        $mark = \html_writer::tag('span', $glyph, ['aria-hidden' => 'true', 'class' => 'me-1']);
        return \html_writer::div($mark . s($label), 'tool_automate_dirstatus ' . $class);
    }
}
