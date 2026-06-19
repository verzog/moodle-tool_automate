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
 * Condition: the user's username contains the configured substring
 * (case-insensitive). For wildcard matching use user_username_matches.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_username_contains extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_user_username_contains', 'tool_automate');
    }

    /**
     * Does the user's username contain the configured substring?
     *
     * @param \stdClass $subject A user record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $needle = trim((string) ($this->config['needle'] ?? ''));
        if ($needle === '') {
            return false;
        }
        $username = (string) ($subject->username ?? '');
        return stripos($username, $needle) !== false;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'text',
            'config_needle',
            get_string('substring', 'tool_automate'),
            ['size' => 40]
        );
        $mform->setType('config_needle', PARAM_TEXT);
        $mform->addRule('config_needle', null, 'required', null, 'client');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['needle' => trim((string) ($formdata->config_needle ?? ''))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_needle' => $config['needle'] ?? ''];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('cond_user_username_contains_desc', 'tool_automate', s($config['needle'] ?? ''));
    }

    /**
     * SQL pre-filter on the {user} table.
     *
     * @param array $config
     * @return array
     */
    public static function get_user_sql_filter(array $config): array {
        global $DB;
        $needle = trim((string) ($config['needle'] ?? ''));
        if ($needle === '') {
            return ['', []];
        }
        $like = $DB->sql_like('u.username', ':uuc_needle', false);
        return [$like, ['uuc_needle' => '%' . $DB->sql_like_escape($needle) . '%']];
    }
}
