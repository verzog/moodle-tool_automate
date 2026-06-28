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
 * Condition: the user has not logged in for at least N days.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inactive_for_days extends condition_base {
    /**
     * Human-readable name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_inactive_for_days', 'tool_automate');
    }

    /**
     * Has the user been inactive for the configured number of days?
     *
     * Users who have never logged in (lastaccess = 0) count as inactive
     * once their account is at least N days old, so the rule still fires
     * on stale, never-used accounts.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $days = (int) ($this->config['days'] ?? 0);
        if ($days <= 0) {
            return false;
        }
        $cutoff = time() - ($days * DAYSECS);
        $reference = !empty($user->lastaccess) ? (int) $user->lastaccess : (int) ($user->timecreated ?? 0);
        return $reference > 0 && $reference < $cutoff;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'text',
            'config_days',
            get_string('inactivedays', 'tool_automate'),
            ['size' => 5]
        );
        $mform->setType('config_days', PARAM_INT);
        $mform->addRule('config_days', null, 'required', null, 'client');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['days' => max(1, (int) ($formdata->config_days ?? 0))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_days' => (int) ($config['days'] ?? 30)];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('cond_inactive_for_days_desc', 'tool_automate', (int) ($config['days'] ?? 0));
    }

    /**
     * SQL pre-filter: only consider users whose lastaccess (or timecreated
     * if never logged in) is older than the cutoff.
     *
     * @param array $config
     * @return array
     */
    public static function get_user_sql_filter(array $config): array {
        static $n = 0;
        $days = (int) ($config['days'] ?? 0);
        if ($days <= 0) {
            return ['', []];
        }
        $cutoff = time() - ($days * DAYSECS);
        // Unique placeholders per call so two inactivity conditions on one
        // rule don't collide on shared :ifd_cutoff names.
        $i = ++$n;
        $p1 = 'ifd_cutoff1_' . $i;
        $p2 = 'ifd_cutoff2_' . $i;
        $sql = "((u.lastaccess > 0 AND u.lastaccess < :$p1)
                 OR (u.lastaccess = 0 AND u.timecreated > 0 AND u.timecreated < :$p2))";
        return [$sql, [$p1 => $cutoff, $p2 => $cutoff]];
    }
}
