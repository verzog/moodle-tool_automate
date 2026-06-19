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
 * Condition: the user's username matches a wildcard pattern. Use * as
 * the wildcard; matching is case-insensitive. Patterns without * are
 * treated as a substring.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_username_matches extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_user_username_matches', 'tool_automate');
    }

    /**
     * Does the user's username match the configured pattern?
     *
     * @param \stdClass $subject A user record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $pattern = trim((string) ($this->config['pattern'] ?? ''));
        if ($pattern === '') {
            return false;
        }
        $username = (string) ($subject->username ?? '');
        if ($username === '') {
            return false;
        }
        if (strpos($pattern, '*') === false) {
            $pattern = '*' . $pattern . '*';
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $username);
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
            get_string('usernamepattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => 's12*']
        );
        $mform->setType('config_pattern', PARAM_TEXT);
        $mform->addHelpButton('config_pattern', 'usernamepattern', 'tool_automate');
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
        return get_string('cond_user_username_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }

    /**
     * SQL pre-filter on the {user} table.
     *
     * @param array $config
     * @return array
     */
    public static function get_user_sql_filter(array $config): array {
        global $DB;
        $pattern = trim((string) ($config['pattern'] ?? ''));
        if ($pattern === '') {
            return ['', []];
        }
        if (strpos($pattern, '*') === false) {
            $pattern = '*' . $pattern . '*';
        }
        $sqlpattern = str_replace('*', '%', $DB->sql_like_escape($pattern));
        $like = $DB->sql_like('u.username', ':uun_pattern', false);
        return [$like, ['uun_pattern' => $sqlpattern]];
    }
}
