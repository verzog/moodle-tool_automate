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
 * Integration test for the delete_course adhoc task.
 *
 * The destructive work is gated by the "Allow course delete" kill-switch,
 * which the task must re-check at run time (not just when it was queued) so an
 * admin who turns it off can stop pending deletions.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(delete_course::class)]
final class delete_course_test extends \advanced_testcase {
    /**
     * With the kill-switch on, a queued task deletes its course.
     */
    public function test_execute_deletes_course_when_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_course_delete', 1, 'tool_automate');

        $course = $this->getDataGenerator()->create_course(['shortname' => 'doomed']);

        $task = new delete_course();
        $task->set_custom_data(['courseid' => (int) $course->id]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('course', ['id' => $course->id]));
    }

    /**
     * With the kill-switch off, a queued task is a no-op even though the course
     * still exists - turning the setting off must stop pending deletions.
     */
    public function test_execute_respects_kill_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_course_delete', 0, 'tool_automate');

        $course = $this->getDataGenerator()->create_course(['shortname' => 'spared']);

        $task = new delete_course();
        $task->set_custom_data(['courseid' => (int) $course->id]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('course', ['id' => $course->id]));
    }

    /**
     * A task whose course has already gone (or never existed) is a quiet
     * no-op, not an error.
     */
    public function test_execute_missing_course_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_course_delete', 1, 'tool_automate');

        $before = $DB->count_records('course');

        $task = new delete_course();
        $task->set_custom_data(['courseid' => 987654]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals($before, $DB->count_records('course'));
    }

    /**
     * The task never deletes the front page, regardless of the setting.
     */
    public function test_execute_never_deletes_site_course(): void {
        global $DB, $SITE;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_course_delete', 1, 'tool_automate');

        $task = new delete_course();
        $task->set_custom_data(['courseid' => (int) $SITE->id]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('course', ['id' => $SITE->id]));
    }
}
