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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the cron rule scheduler's "is this rule due?" logic.
 *
 * is_due() is pure (no DB), so these build in-memory rule records and
 * fixed timestamps - no reset needed.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(run_rules::class)]
final class run_rules_test extends \advanced_testcase {
    /**
     * Build a rule record for the scheduler.
     *
     * @param string $schedule hourly|daily|monthly|oncedate
     * @param int $last lastrunat epoch (0 = never run)
     * @param int $scheduledate oncedate target epoch
     * @return \stdClass
     */
    protected function rule(string $schedule, int $last = 0, int $scheduledate = 0): \stdClass {
        return (object) [
            'schedule'     => $schedule,
            'lastrunat'    => $last,
            'scheduledate' => $scheduledate,
        ];
    }

    /**
     * Hourly rules are always due - the task itself only fires hourly.
     */
    public function test_hourly_is_always_due(): void {
        $now = mktime(12, 0, 0, 6, 15, 2026);
        $this->assertTrue(run_rules::is_due($this->rule('hourly', 0), $now));
        $this->assertTrue(run_rules::is_due($this->rule('hourly', $now), $now));
        // Unknown schedules fall through to the hourly default.
        $this->assertTrue(run_rules::is_due($this->rule('weekly', $now), $now));
    }

    /**
     * Daily rules are due if never run or at least 24h since the last run.
     */
    public function test_daily_respects_24h_gap(): void {
        $now = mktime(12, 0, 0, 6, 15, 2026);
        $this->assertTrue(run_rules::is_due($this->rule('daily', 0), $now));
        $this->assertTrue(run_rules::is_due($this->rule('daily', $now - DAYSECS - 1), $now));
        $this->assertTrue(run_rules::is_due($this->rule('daily', $now - DAYSECS), $now));
        $this->assertFalse(run_rules::is_due($this->rule('daily', $now - HOURSECS), $now));
    }

    /**
     * Monthly rules only run on the 1st, and only once per calendar month.
     */
    public function test_monthly_runs_once_on_the_first(): void {
        $first = mktime(12, 0, 0, 6, 1, 2026);
        $mid = mktime(12, 0, 0, 6, 15, 2026);

        // Not the 1st of the month: never due, even if never run.
        $this->assertFalse(run_rules::is_due($this->rule('monthly', 0), $mid));

        // The 1st and never run: due.
        $this->assertTrue(run_rules::is_due($this->rule('monthly', 0), $first));

        // The 1st but already run earlier the same calendar month: not due.
        $earliersameday = mktime(1, 0, 0, 6, 1, 2026);
        $this->assertFalse(run_rules::is_due($this->rule('monthly', $earliersameday), $first));

        // The 1st and last run was a previous month: due.
        $lastmonth = mktime(12, 0, 0, 5, 15, 2026);
        $this->assertTrue(run_rules::is_due($this->rule('monthly', $lastmonth), $first));
    }

    /**
     * One-off date rules fire once, on or after their target date.
     */
    public function test_oncedate_fires_once_after_its_date(): void {
        $now = mktime(12, 0, 0, 6, 15, 2026);

        // No date set: never due.
        $this->assertFalse(run_rules::is_due($this->rule('oncedate', 0, 0), $now));

        // Date in the future: not yet.
        $this->assertFalse(run_rules::is_due($this->rule('oncedate', 0, $now + HOURSECS), $now));

        // Date reached and never run: due.
        $this->assertTrue(run_rules::is_due($this->rule('oncedate', 0, $now - HOURSECS), $now));

        // Date reached but the rule already ran after the date: not due again.
        $this->assertFalse(
            run_rules::is_due($this->rule('oncedate', $now - 60, $now - HOURSECS), $now)
        );

        // Date reached and the only prior run predates the target: due.
        $this->assertTrue(
            run_rules::is_due($this->rule('oncedate', $now - DAYSECS, $now - HOURSECS), $now)
        );
    }
}
