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
 * Condition: a standard user profile field equals or contains a value.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profile_field extends condition_base {
    /** @var string[] User table columns we expose. */
    private const FIELDS = [
        'firstname', 'lastname', 'city', 'country',
        'institution', 'department', 'lang', 'username',
    ];

    /** @var string[] Comparison operators. */
    private const OPS = ['equals', 'contains'];

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_profile_field', 'tool_automate');
    }

    /**
     * Check the field.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $field = (string) ($this->config['field'] ?? '');
        $op = (string) ($this->config['op'] ?? 'equals');
        $value = (string) ($this->config['value'] ?? '');
        if (!in_array($field, self::FIELDS, true) || !isset($user->$field)) {
            return false;
        }
        $actual = (string) $user->$field;
        if ($op === 'contains') {
            return $value !== '' && stripos($actual, $value) !== false;
        }
        return strcasecmp($actual, $value) === 0;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $fields = [];
        foreach (self::FIELDS as $f) {
            $fields[$f] = get_string('userfield_' . $f, 'tool_automate');
        }
        $ops = [];
        foreach (self::OPS as $op) {
            $ops[$op] = get_string('op_' . $op, 'tool_automate');
        }
        $mform->addElement('select', 'config_field', get_string('field', 'tool_automate'), $fields);
        $mform->addElement('select', 'config_op', get_string('operator', 'tool_automate'), $ops);
        $mform->addElement('text', 'config_value', get_string('value', 'tool_automate'), ['size' => 40]);
        $mform->setType('config_value', PARAM_TEXT);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'field' => (string) ($formdata->config_field ?? ''),
            'op'    => (string) ($formdata->config_op ?? 'equals'),
            'value' => (string) ($formdata->config_value ?? ''),
        ];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_field' => $config['field'] ?? 'firstname',
            'config_op'    => $config['op'] ?? 'equals',
            'config_value' => $config['value'] ?? '',
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $a = (object) [
            'field' => s($config['field'] ?? ''),
            'op'    => s($config['op'] ?? ''),
            'value' => s($config['value'] ?? ''),
        ];
        return get_string('cond_profile_field_desc', 'tool_automate', $a);
    }
}
