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
 * Condition: the user's account is older (or younger) than N days.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class account_age extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_account_age', 'tool_automate');
    }

    /**
     * Check.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $days = (int) ($this->config['days'] ?? 0);
        $op = (string) ($this->config['op'] ?? 'gte');
        if ($days <= 0 || empty($user->timecreated)) {
            return false;
        }
        $age = (int) floor((time() - (int) $user->timecreated) / DAYSECS);
        return $op === 'lte' ? $age <= $days : $age >= $days;
    }

    /**
     * Form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $ops = [
            'gte' => get_string('op_atleast', 'tool_automate'),
            'lte' => get_string('op_atmost', 'tool_automate'),
        ];
        $mform->addElement('select', 'config_op', get_string('comparison', 'tool_automate'), $ops);
        $mform->addElement('text', 'config_days', get_string('days', 'tool_automate'), ['size' => 5]);
        $mform->setType('config_days', PARAM_INT);
        $mform->addRule('config_days', null, 'required', null, 'client');
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'op'   => (string) ($formdata->config_op ?? 'gte'),
            'days' => max(1, (int) ($formdata->config_days ?? 0)),
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
            'config_op'   => $config['op'] ?? 'gte',
            'config_days' => (int) ($config['days'] ?? 30),
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
            'op'   => get_string('op_' . ($config['op'] === 'lte' ? 'atmost' : 'atleast'), 'tool_automate'),
            'days' => (int) ($config['days'] ?? 0),
        ];
        return get_string('cond_account_age_desc', 'tool_automate', $a);
    }

    /**
     * SQL pre-filter.
     *
     * @param array $config
     * @return array
     */
    public static function get_user_sql_filter(array $config): array {
        $days = (int) ($config['days'] ?? 0);
        if ($days <= 0) {
            return ['', []];
        }
        $cutoff = time() - ($days * DAYSECS);
        if (($config['op'] ?? 'gte') === 'lte') {
            return ['u.timecreated >= :aa_cutoff', ['aa_cutoff' => $cutoff]];
        }
        return ['u.timecreated > 0 AND u.timecreated <= :aa_cutoff', ['aa_cutoff' => $cutoff]];
    }
}
