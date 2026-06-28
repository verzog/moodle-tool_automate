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
 * Condition: the user's email matches a wildcard or substring pattern.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_matches extends condition_base {
    /**
     * Human-readable name shown in the rule form.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_email_matches', 'tool_automate');
    }

    /**
     * Does the user's email address match the configured pattern?
     *
     * @param \stdClass $user A full user record.
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $pattern = trim($this->config['pattern'] ?? '');
        if ($pattern === '' || empty($user->email)) {
            return false;
        }
        // A pattern with no wildcard is treated as a substring match (so
        // "@example.com" matches "alice@example.com") by wildcard_match().
        return self::wildcard_match($pattern, (string) $user->email);
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
            get_string('emailpattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => '@example.com']
        );
        $mform->setType('config_pattern', PARAM_TEXT);
        $mform->addHelpButton('config_pattern', 'emailpattern', 'tool_automate');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['pattern' => trim($formdata->config_pattern ?? '')];
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
        return get_string('cond_email_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }
}
