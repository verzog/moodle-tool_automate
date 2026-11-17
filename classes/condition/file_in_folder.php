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

namespace tool_automate\condition;

use tool_automate\watch_folder;

/**
 * Condition: the file is in a named watch folder.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_in_folder extends condition_base {
    /**
     * Subject discriminator.
     *
     * @return string
     */
    public static function get_subject(): string {
        return 'file';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_file_in_folder', 'tool_automate');
    }

    /**
     * Is the file in the configured watch folder?
     *
     * @param \stdClass $subject A file record from watch_folder::list_files().
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $folder = (string) ($this->config['folder'] ?? '');
        $usable = $folder === watch_folder::BASE || watch_folder::is_folder_name($folder);
        return $usable && (string) ($subject->folder ?? '') === $folder;
    }

    /**
     * Form fields: a choice of the watch folders that exist now.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $folders = watch_folder::list_folders();
        if (empty($folders)) {
            $mform->addElement('static', 'config_nofolders', '', get_string('watchfolder_nofolders', 'tool_automate'));
            return;
        }
        $mform->addElement(
            'select',
            'config_folder',
            get_string('watchfolder', 'tool_automate'),
            array_combine($folders, array_map([watch_folder::class, 'folder_label'], $folders))
        );
        $mform->addHelpButton('config_folder', 'watchfolder', 'tool_automate');
    }

    /**
     * Extract config. Only a name that is an existing watch folder is kept.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        $folder = trim((string) ($formdata->config_folder ?? ''));
        return ['folder' => watch_folder::folder_path($folder) === null ? '' : $folder];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_folder' => (string) ($config['folder'] ?? '')];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $label = watch_folder::folder_label((string) ($config['folder'] ?? ''));
        return get_string('cond_file_in_folder_desc', 'tool_automate', s($label));
    }
}
