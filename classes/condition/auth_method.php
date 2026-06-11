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
 * Condition: the user's auth method equals a configured value.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_method extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_auth_method', 'tool_automate');
    }

    /**
     * Match.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $auth = (string) ($this->config['auth'] ?? '');
        return $auth !== '' && (string) ($user->auth ?? '') === $auth;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $enabled = get_enabled_auth_plugins();
        $options = [];
        foreach ($enabled as $a) {
            $options[$a] = $a;
        }
        $mform->addElement('select', 'config_auth', get_string('authmethod', 'tool_automate'), $options);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['auth' => (string) ($formdata->config_auth ?? '')];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_auth' => $config['auth'] ?? 'manual'];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('cond_auth_method_desc', 'tool_automate', s($config['auth'] ?? ''));
    }
}
