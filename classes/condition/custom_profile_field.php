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
 * Condition: a custom profile field (user_info_data) equals or contains a value.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_profile_field extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_custom_profile_field', 'tool_automate');
    }

    /**
     * Match.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        global $DB;
        $fieldid = (int) ($this->config['fieldid'] ?? 0);
        $op = (string) ($this->config['op'] ?? 'equals');
        $value = (string) ($this->config['value'] ?? '');
        if (!$fieldid) {
            return false;
        }
        $data = $DB->get_field('user_info_data', 'data', ['userid' => $user->id, 'fieldid' => $fieldid]);
        $actual = (string) ($data ?? '');
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
        global $DB;
        $fields = $DB->get_records_menu('user_info_field', null, 'name', 'id, name');
        $ops = [
            'equals'   => get_string('op_equals', 'tool_automate'),
            'contains' => get_string('op_contains', 'tool_automate'),
        ];
        if (empty($fields)) {
            $mform->addElement('static', 'config_nofields', '', get_string('nocustomfields', 'tool_automate'));
            return;
        }
        $mform->addElement('select', 'config_fieldid', get_string('field', 'tool_automate'), $fields);
        $mform->addElement('select', 'config_op', get_string('operator', 'tool_automate'), $ops);
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
            'fieldid' => (int) ($formdata->config_fieldid ?? 0),
            'op'      => (string) ($formdata->config_op ?? 'equals'),
            'value'   => (string) ($formdata->config_value ?? ''),
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
            'config_fieldid' => (int) ($config['fieldid'] ?? 0),
            'config_op'      => $config['op'] ?? 'equals',
            'config_value'   => $config['value'] ?? '',
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        global $DB;
        $name = $DB->get_field('user_info_field', 'name', ['id' => (int) ($config['fieldid'] ?? 0)]);
        $a = (object) [
            'field' => s($name ?: '?'),
            'op'    => s($config['op'] ?? ''),
            'value' => s($config['value'] ?? ''),
        ];
        return get_string('cond_custom_profile_field_desc', 'tool_automate', $a);
    }
}
