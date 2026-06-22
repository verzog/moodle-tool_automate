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

namespace tool_automate\task;

/**
 * Scheduled task that runs enabled cron-triggered rules whose own
 * schedule says they're due (hourly, daily, monthly, or a one-off date).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_rules extends \core\task\scheduled_task {
    /**
     * Human-readable task name shown in the task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrunrules', 'tool_automate');
    }

    /**
     * Iterate enabled cron rules and run each one whose per-rule
     * schedule has come due.
     */
    public function execute(): void {
        global $DB;
        $now = time();
        $rules = $DB->get_records('tool_automate_rule', [
            'enabled'     => 1,
            'triggertype' => 'cron',
        ]);
        $due = 0;
        foreach ($rules as $rule) {
            if (!self::is_due($rule, $now)) {
                continue;
            }
            $due++;
            // Narrate progress so this scheduled task's row in the task
            // log shows which rules ran rather than an opaque blank. The
            // rule name is logged before run_rule() so that if a rule
            // throws, the task log still names the rule it died on.
            mtrace("tool_automate: running cron rule '" . $rule->name . "' (id " . $rule->id . ')');
            $results = \tool_automate\manager::run_rule((int) $rule->id, false);
            $DB->set_field('tool_automate_rule', 'lastrunat', $now, ['id' => (int) $rule->id]);
            mtrace('  ' . count($results) . ' log entry/entries written');
        }
        mtrace('tool_automate: ' . $due . ' of ' . count($rules) . ' enabled cron rule(s) were due this run');
    }

    /**
     * Should a rule with the given schedule run now?
     *
     * - hourly  : always (the task itself fires hourly).
     * - daily   : >= 24h since lastrunat (or never run).
     * - monthly : today is the 1st of the month, AND lastrunat is in a
     *             previous calendar month (or the rule has never run).
     *             Restricting to the 1st matches the user-facing label
     *             "Monthly (1st of each month)" - admins enabling a
     *             rule mid-month wait until next month's 1st.
     * - oncedate: scheduledate is on/before now AND the rule hasn't yet
     *             run since that date.
     *
     * @param \stdClass $rule
     * @param int $now
     * @return bool
     */
    public static function is_due(\stdClass $rule, int $now): bool {
        $schedule = (string) ($rule->schedule ?? 'hourly');
        $last = (int) ($rule->lastrunat ?? 0);

        switch ($schedule) {
            case 'daily':
                return $last === 0 || ($now - $last) >= DAYSECS;

            case 'monthly':
                if ((int) date('j', $now) !== 1) {
                    return false;
                }
                if ($last === 0) {
                    return true;
                }
                return date('Y-m', $last) !== date('Y-m', $now);

            case 'oncedate':
                $when = (int) ($rule->scheduledate ?? 0);
                if ($when === 0 || $when > $now) {
                    return false;
                }
                return $last === 0 || $last < $when;

            case 'hourly':
            default:
                return true;
        }
    }
}
