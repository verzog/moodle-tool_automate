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
 * Condition: the course's fullname matches a wildcard pattern. Use *
 * as the wildcard; matching is case-insensitive. For plain substring
 * matching use course_name_contains.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_name_matches extends condition_base {
    /**
     * Subject discriminator.
     *
     * @return string
     */
    public static function get_subject(): string {
        return 'course';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_course_name_matches', 'tool_automate');
    }

    /**
     * Does the course's fullname match the configured pattern?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $pattern = trim((string) ($this->config['pattern'] ?? ''));
        if ($pattern === '') {
            return false;
        }
        $name = (string) ($subject->fullname ?? '');
        if ($name === '') {
            return false;
        }
        return self::wildcard_match($pattern, $name);
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
            ['size' => 40, 'placeholder' => 'BIO *']
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
        return get_string('cond_course_name_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }

    /**
     * SQL pre-filter on the {course} table. Translates the user's *
     * wildcards into SQL % so the candidate set is narrowed in DB
     * before PHP evaluation.
     *
     * @param array $config
     * @return array
     */
    public static function get_course_sql_filter(array $config): array {
        global $DB;
        static $n = 0;
        $pattern = trim((string) ($config['pattern'] ?? ''));
        if ($pattern === '') {
            return ['', []];
        }
        if (strpos($pattern, '*') === false) {
            $pattern = '*' . $pattern . '*';
        }
        // Neutralise SQL wildcards (% and _) so literal user input is
        // matched as-is. After escaping, the remaining * characters
        // represent the admin's intended wildcards and become SQL %.
        // The placeholder gets a per-call counter so two
        // course_name_matches conditions on one rule don't collide.
        $sqlpattern = str_replace('*', '%', $DB->sql_like_escape($pattern));
        $param = 'cnm_pattern_' . (++$n);
        $like = $DB->sql_like('c.fullname', ':' . $param, false);
        return [$like, [$param => $sqlpattern]];
    }
}
