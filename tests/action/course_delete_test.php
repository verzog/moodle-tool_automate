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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the safety gating on the destructive course_delete action.
 *
 * The action never deletes inline; on a successful, fully-confirmed run
 * it only queues a delete_course adhoc task. Each guard (site course,
 * site kill-switch, confirmation phrase, dry run) must be a no-op that
 * queues nothing.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(course_delete::class)]
final class course_delete_test extends \advanced_testcase {
    /** Class name of the adhoc deletion task. */
    private const TASK = '\\tool_automate\\task\\delete_course';

    /**
     * How many delete_course adhoc tasks are currently queued.
     *
     * @return int
     */
    protected function queued(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => self::TASK]);
    }

    /**
     * With everything enabled and confirmed, a matched course is queued
     * for background deletion but is NOT deleted inline.
     */
    public function test_confirmed_run_queues_a_background_task(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allow_course_delete', 1, 'tool_automate');
        $course = $this->getDataGenerator()->create_course();

        $action = new course_delete([
            'confirm'   => course_delete::CONFIRM_PHRASE,
            'delayhour' => course_delete::DELAY_IMMEDIATE,
        ]);
        $result = $action->execute($course, false);

        $this->assertEquals(1, $this->queued());
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals(
            get_string('coursedeletequeued', 'tool_automate', format_string($course->fullname)),
            $result
        );
    }

    /**
     * Without the confirmation phrase the action is a no-op.
     */
    public function test_missing_confirmation_phrase_queues_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allow_course_delete', 1, 'tool_automate');
        $course = $this->getDataGenerator()->create_course();

        $result = (new course_delete([]))->execute($course, false);

        $this->assertEquals(0, $this->queued());
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals(
            get_string('coursedeleteunconfirmed', 'tool_automate', format_string($course->fullname)),
            $result
        );
    }

    /**
     * With the site kill-switch off, even a confirmed action does nothing.
     */
    public function test_site_kill_switch_off_queues_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allow_course_delete', 0, 'tool_automate');
        $course = $this->getDataGenerator()->create_course();

        $result = (new course_delete([
            'confirm' => course_delete::CONFIRM_PHRASE,
        ]))->execute($course, false);

        $this->assertEquals(0, $this->queued());
        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
        $this->assertEquals(
            get_string('coursedeletedisabled', 'tool_automate', format_string($course->fullname)),
            $result
        );
    }

    /**
     * A dry run reports what would happen but queues nothing.
     */
    public function test_dry_run_queues_nothing(): void {
        $this->resetAfterTest();
        set_config('allow_course_delete', 1, 'tool_automate');
        $course = $this->getDataGenerator()->create_course();

        $result = (new course_delete([
            'confirm' => course_delete::CONFIRM_PHRASE,
        ]))->execute($course, true);

        $this->assertEquals(0, $this->queued());
        $this->assertEquals(
            get_string('coursewoulddelete', 'tool_automate', format_string($course->fullname)),
            $result
        );
    }

    /**
     * The site front page is never deletable, even when fully confirmed.
     */
    public function test_site_course_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('allow_course_delete', 1, 'tool_automate');
        $site = $DB->get_record('course', ['id' => SITEID]);

        $result = (new course_delete([
            'confirm' => course_delete::CONFIRM_PHRASE,
        ]))->execute($site, false);

        $this->assertEquals(0, $this->queued());
        $this->assertTrue($DB->record_exists('course', ['id' => SITEID]));
        $this->assertEquals(
            get_string('coursedeleteskippedsite', 'tool_automate', format_string($site->fullname)),
            $result
        );
    }
}
