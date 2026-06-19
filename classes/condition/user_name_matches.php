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
 * Condition: the user's name matches a wildcard pattern. The name
 * tested is firstname + ' ' + lastname; matching is case-insensitive.
 * Use * as the wildcard. Patterns without * are treated as a substring
 * (i.e. *pattern*).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_name_matches extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_user_name_matches', 'tool_automate');
    }

    /**
     * Does the user's name match the configured pattern?
     *
     * @param \stdClass $subject A user record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $pattern = trim((string) ($this->config['pattern'] ?? ''));
        if ($pattern === '') {
            return false;
        }
        $name = trim(((string) ($subject->firstname ?? '')) . ' ' . ((string) ($subject->lastname ?? '')));
        if ($name === '') {
            return false;
        }
        if (strpos($pattern, '*') === false) {
            $pattern = '*' . $pattern . '*';
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $name);
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
            get_string('namepattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => 'Jane *']
        );
        $mform->setType('config_pattern', PARAM_TEXT);
        $mform->addHelpButton('config_pattern', 'namepattern', 'tool_automate');
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
        return get_string('cond_user_name_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }

    /**
     * SQL pre-filter on the {user} table. Translates the user's *
     * wildcards into SQL % and matches against firstname || ' ' ||
     * lastname using the cross-database concatenation helper.
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
        // sql_like_escape neutralises any SQL wildcards in the admin's
        // input; the remaining * then become the SQL %.
        $sqlpattern = str_replace('*', '%', $DB->sql_like_escape($pattern));
        $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $like = $DB->sql_like($fullname, ':unm_pattern', false);
        return [$like, ['unm_pattern' => $sqlpattern]];
    }
}
