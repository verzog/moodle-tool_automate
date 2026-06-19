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
 * Condition: the course's idnumber matches a wildcard or substring pattern.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_idnumber_matches extends condition_base {
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
        return get_string('cond_course_idnumber_matches', 'tool_automate');
    }

    /**
     * Does the course's idnumber match the configured pattern?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $pattern = trim($this->config['pattern'] ?? '');
        if ($pattern === '') {
            return false;
        }
        $idnumber = (string) ($subject->idnumber ?? '');
        if ($idnumber === '') {
            return false;
        }
        if (strpos($pattern, '*') === false) {
            $pattern = '*' . $pattern . '*';
        }
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $idnumber);
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
            get_string('idnumberpattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => 'BIO-*']
        );
        $mform->setType('config_pattern', PARAM_TEXT);
        $mform->addHelpButton('config_pattern', 'idnumberpattern', 'tool_automate');
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
        return get_string('cond_course_idnumber_matches_desc', 'tool_automate', s($config['pattern'] ?? ''));
    }
}
