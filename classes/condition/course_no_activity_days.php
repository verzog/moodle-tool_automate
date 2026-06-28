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
 * Condition: the course has had no logged activity for at least N days.
 * Uses {course}.timemodified as a coarse proxy, fall-back to timecreated.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_no_activity_days extends condition_base {
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
        return get_string('cond_course_no_activity_days', 'tool_automate');
    }

    /**
     * Has the course been quiet for the configured number of days?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $days = (int) ($this->config['days'] ?? 0);
        if ($days <= 0) {
            return false;
        }
        $cutoff = time() - ($days * DAYSECS);
        $reference = !empty($subject->timemodified)
            ? (int) $subject->timemodified
            : (int) ($subject->timecreated ?? 0);
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
        return get_string('cond_course_no_activity_days_desc', 'tool_automate', (int) ($config['days'] ?? 0));
    }

    /**
     * SQL pre-filter on the {course} table.
     *
     * @param array $config
     * @return array
     */
    public static function get_course_sql_filter(array $config): array {
        static $n = 0;
        $days = (int) ($config['days'] ?? 0);
        if ($days <= 0) {
            return ['', []];
        }
        $cutoff = time() - ($days * DAYSECS);
        // Unique placeholders per call so two no-activity conditions on one
        // rule don't collide on shared :cna_cutoff names.
        $i = ++$n;
        $p1 = 'cna_cutoff1_' . $i;
        $p2 = 'cna_cutoff2_' . $i;
        $sql = "((c.timemodified > 0 AND c.timemodified < :$p1)
                 OR (c.timemodified = 0 AND c.timecreated > 0 AND c.timecreated < :$p2))";
        return [$sql, [$p1 => $cutoff, $p2 => $cutoff]];
    }
}
