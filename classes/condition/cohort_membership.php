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
 * Condition: the user is (or is not) a member of a cohort.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cohort_membership extends condition_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_cohort_membership', 'tool_automate');
    }

    /**
     * Match.
     *
     * @param \stdClass $user
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $cohortid = (int) ($this->config['cohortid'] ?? 0);
        $mode = (string) ($this->config['mode'] ?? 'in');
        if (!$cohortid || !$DB->record_exists('cohort', ['id' => $cohortid])) {
            return false;
        }
        $ismember = cohort_is_member($cohortid, $user->id);
        return $mode === 'in' ? $ismember : !$ismember;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $cohorts = $DB->get_records_menu('cohort', null, 'name', 'id, name');
        $modes = [
            'in'    => get_string('cohortmode_in', 'tool_automate'),
            'notin' => get_string('cohortmode_notin', 'tool_automate'),
        ];
        $mform->addElement('select', 'config_mode', get_string('membership', 'tool_automate'), $modes);
        $mform->addElement('select', 'config_cohortid', get_string('cohort', 'tool_automate'), $cohorts);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'mode'     => (string) ($formdata->config_mode ?? 'in'),
            'cohortid' => (int) ($formdata->config_cohortid ?? 0),
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
            'config_mode'     => $config['mode'] ?? 'in',
            'config_cohortid' => (int) ($config['cohortid'] ?? 0),
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
        $name = $DB->get_field('cohort', 'name', ['id' => (int) ($config['cohortid'] ?? 0)]);
        $key = ($config['mode'] ?? 'in') === 'notin'
            ? 'cond_cohort_membership_desc_notin'
            : 'cond_cohort_membership_desc_in';
        return get_string($key, 'tool_automate', s($name ?: '?'));
    }
}
