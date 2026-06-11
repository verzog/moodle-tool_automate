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

namespace tool_automate\action;

/**
 * Action: set a standard user profile field to a value.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_profile_field extends action_base {
    /** @var string[] Editable user table columns. */
    private const FIELDS = [
        'firstname', 'lastname', 'city', 'country',
        'institution', 'department', 'lang',
    ];

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_set_profile_field', 'tool_automate');
    }

    /**
     * Set.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $DB;
        $field = (string) ($this->config['field'] ?? '');
        $value = (string) ($this->config['value'] ?? '');
        if (!in_array($field, self::FIELDS, true)) {
            return get_string('invalidfield', 'tool_automate');
        }
        $current = (string) ($user->$field ?? '');
        $a = (object) ['field' => $field, 'value' => $value, 'from' => $current, 'to' => $value];
        if ($current === $value) {
            return get_string('fieldalready', 'tool_automate', $a);
        }
        if ($dryrun) {
            return get_string('fieldwouldset', 'tool_automate', $a);
        }
        $DB->set_field('user', $field, $value, ['id' => $user->id]);
        return get_string('fieldset', 'tool_automate', $a);
    }

    /**
     * Form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $fields = [];
        foreach (self::FIELDS as $f) {
            $fields[$f] = get_string('userfield_' . $f, 'tool_automate');
        }
        $mform->addElement('select', 'config_field', get_string('field', 'tool_automate'), $fields);
        $mform->addElement('text', 'config_value', get_string('value', 'tool_automate'), ['size' => 40]);
        $mform->setType('config_value', PARAM_TEXT);
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'field' => (string) ($formdata->config_field ?? ''),
            'value' => (string) ($formdata->config_value ?? ''),
        ];
    }

    /**
     * Defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_field' => $config['field'] ?? 'firstname',
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
            'value' => s($config['value'] ?? ''),
        ];
        return get_string('act_set_profile_field_desc', 'tool_automate', $a);
    }
}
