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
 * Condition: the course's start date falls between two configured
 * timestamps (inclusive). Either bound can be left at zero to make
 * the range open-ended on that side.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_startdate_between extends condition_base {
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
        return get_string('cond_course_startdate_between', 'tool_automate');
    }

    /**
     * Is the course's start date inside the configured window?
     *
     * @param \stdClass $subject A course record.
     * @return bool
     */
    public function matches(\stdClass $subject): bool {
        $from = (int) ($this->config['from'] ?? 0);
        $to = (int) ($this->config['to'] ?? 0);
        $start = (int) ($subject->startdate ?? 0);
        if ($start <= 0) {
            return false;
        }
        if ($from > 0 && $start < $from) {
            return false;
        }
        // date_selector submits midnight at the *start* of the selected
        // day. The UI labels this as an inclusive range, so extend the
        // upper bound to the end of that day (== start of the next).
        if ($to > 0 && $start >= $to + DAYSECS) {
            return false;
        }
        return $from > 0 || $to > 0;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        // Optional=true on each date_selector lets the admin clear the
        // bound to leave that side of the range open.
        $mform->addElement(
            'date_selector',
            'config_from',
            get_string('cond_course_startdate_from', 'tool_automate'),
            ['optional' => true]
        );
        $mform->addElement(
            'date_selector',
            'config_to',
            get_string('cond_course_startdate_to', 'tool_automate'),
            ['optional' => true]
        );
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'from' => (int) ($formdata->config_from ?? 0),
            'to'   => (int) ($formdata->config_to ?? 0),
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
            'config_from' => (int) ($config['from'] ?? 0),
            'config_to'   => (int) ($config['to'] ?? 0),
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $from = (int) ($config['from'] ?? 0);
        $to = (int) ($config['to'] ?? 0);
        $fmt = get_string('strftimedate', 'langconfig');
        $fromtxt = $from > 0 ? userdate($from, $fmt) : get_string('cond_course_startdate_any', 'tool_automate');
        $totxt = $to > 0 ? userdate($to, $fmt) : get_string('cond_course_startdate_any', 'tool_automate');
        return get_string(
            'cond_course_startdate_between_desc',
            'tool_automate',
            (object) ['from' => $fromtxt, 'to' => $totxt]
        );
    }

    /**
     * SQL pre-filter on the {course} table - lets the matched-course
     * query skip rows outside the window without instantiating each
     * record in PHP.
     *
     * @param array $config
     * @return array
     */
    public static function get_course_sql_filter(array $config): array {
        $from = (int) ($config['from'] ?? 0);
        $to = (int) ($config['to'] ?? 0);
        $clauses = ['c.startdate > 0'];
        $params = [];
        if ($from > 0) {
            $clauses[] = 'c.startdate >= :csdb_from';
            $params['csdb_from'] = $from;
        }
        if ($to > 0) {
            // Strictly less than the start of the day *after* the To
            // date, so the whole selected end day is included
            // (date_selector submits its midnight).
            $clauses[] = 'c.startdate < :csdb_to';
            $params['csdb_to'] = $to + DAYSECS;
        }
        // Both bounds unset = the condition never matches; emit a
        // FALSE clause rather than something that selects every row.
        if ($from === 0 && $to === 0) {
            return ['1=0', []];
        }
        return ['(' . implode(' AND ', $clauses) . ')', $params];
    }
}
