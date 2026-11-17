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

/**
 * Condition: the file's name matches a wildcard pattern.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class file_name_matches extends condition_base {
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
        return get_string('cond_file_name_matches', 'tool_automate');
    }

    /**
     * Does the file's name match the configured pattern?
     *
     * @param \stdClass $subject A file record from watch_folder::list_files().
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $pattern = trim((string) ($this->config['pattern'] ?? ''));
        $name = (string) ($subject->name ?? '');
        return $pattern !== '' && $name !== '' && self::wildcard_match($pattern, $name);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'text',
            'config_pattern',
            get_string('filenamepattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => 'Week*.pptx']
        );
        $mform->setType('config_pattern', PARAM_TEXT);
        $mform->addHelpButton('config_pattern', 'filenamepattern', 'tool_automate');
        $mform->addRule('config_pattern', null, 'required', null, 'client');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['pattern' => trim((string) ($formdata->config_pattern ?? ''))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_pattern' => $config['pattern'] ?? ''];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('cond_file_name_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }
}
