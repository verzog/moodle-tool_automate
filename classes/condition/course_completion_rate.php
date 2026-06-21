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
 * Condition: the share of enrolled users who have completed the course
 * is below (or above) a configured percentage. Backs questions like
 * "send me a report of courses where less than 30% of enrolled users
 * have finished".
 *
 * No SQL pre-filter - completion is per-user and not in a single
 * column, so the condition only narrows the matched-course set after
 * the rest of the rule has done its course-level work.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_completion_rate extends condition_base {
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
        return get_string('cond_course_completion_rate', 'tool_automate');
    }

    /**
     * Does the course's completion rate satisfy the configured test?
     * Courses without completion tracking enabled, and courses with no
     * enrolled users, are reported as non-matching - "less than 30%"
     * over an undefined denominator is not a useful match.
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $threshold = (int) ($this->config['percent'] ?? 0);
        $op = (string) ($this->config['op'] ?? 'lt');

        $info = new \completion_info($subject);
        if (!$info->is_enabled()) {
            return false;
        }
        $context = \context_course::instance((int) $subject->id, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        $users = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
        if (!$users) {
            return false;
        }
        $done = 0;
        foreach ($users as $u) {
            if ($info->is_course_complete((int) $u->id)) {
                $done++;
            }
        }
        $rate = ($done / count($users)) * 100;
        return $op === 'gt' ? $rate > $threshold : $rate < $threshold;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $ops = [
            'lt' => get_string('op_lessthan', 'tool_automate'),
            'gt' => get_string('op_greaterthan', 'tool_automate'),
        ];
        $mform->addElement('select', 'config_op', get_string('comparison', 'tool_automate'), $ops);
        $mform->addElement(
            'text',
            'config_percent',
            get_string('cond_course_completion_rate_percent', 'tool_automate'),
            ['size' => 5]
        );
        $mform->setType('config_percent', PARAM_INT);
        $mform->addRule('config_percent', null, 'required', null, 'client');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'op'      => (string) ($formdata->config_op ?? 'lt'),
            'percent' => max(0, min(100, (int) ($formdata->config_percent ?? 0))),
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
            'config_op'      => $config['op'] ?? 'lt',
            'config_percent' => (int) ($config['percent'] ?? 30),
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
            'op'      => ($config['op'] ?? 'lt') === 'gt'
                ? get_string('op_greaterthan', 'tool_automate')
                : get_string('op_lessthan', 'tool_automate'),
            'percent' => (int) ($config['percent'] ?? 0),
        ];
        return get_string('cond_course_completion_rate_desc', 'tool_automate', $a);
    }
}
