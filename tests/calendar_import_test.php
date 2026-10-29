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

namespace tool_automate;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the calendar folder import (soft integration with local_grpcalendarimport).
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(calendar_import::class)]
#[CoversClass(task\queue_calendar_imports::class)]
#[CoversClass(task\import_calendar_file::class)]
final class calendar_import_test extends \advanced_testcase {
    /**
     * Skip a test that needs the importer plugin when it is not installed.
     */
    private function require_importer(): void {
        if (!calendar_import::is_available()) {
            $this->markTestSkipped('local_grpcalendarimport v1.2.0 or later is not installed');
        }
    }

    /**
     * Only bare .csv and .tsv names are picked up.
     */
    public function test_is_import_filename(): void {
        $this->assertTrue(calendar_import::is_import_filename('events.csv'));
        $this->assertTrue(calendar_import::is_import_filename('EVENTS.TSV'));
        $this->assertFalse(calendar_import::is_import_filename('.csv'));
        $this->assertFalse(calendar_import::is_import_filename('.hidden.csv'));
        $this->assertFalse(calendar_import::is_import_filename('events.txt'));
        $this->assertFalse(calendar_import::is_import_filename('../events.csv'));
        $this->assertFalse(calendar_import::is_import_filename(''));
    }

    /**
     * Files are listed from the top level only, sorted.
     */
    public function test_list_files(): void {
        $this->resetAfterTest();
        $dir = make_request_directory();
        file_put_contents($dir . '/b.csv', 'x');
        file_put_contents($dir . '/a.tsv', 'x');
        file_put_contents($dir . '/notes.txt', 'x');
        mkdir($dir . '/processing');
        file_put_contents($dir . '/processing/c.csv', 'x');

        $this->assertSame(['a.tsv', 'b.csv'], calendar_import::list_files($dir));
        $this->assertSame([], calendar_import::list_files($dir . '/missing'));
    }

    /**
     * Group matching uses the shared wildcard rules.
     */
    public function test_matching_groups(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Cohort A']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Staff']);

        $names = function (array $groups): array {
            return array_values(array_map(fn($g) => $g->name, $groups));
        };
        $this->assertSame(['Cohort A', 'Staff'], $names(calendar_import::matching_groups($course->id, '')));
        $this->assertSame(['Cohort A'], $names(calendar_import::matching_groups($course->id, 'coh*')));
        $this->assertSame(['Staff'], $names(calendar_import::matching_groups($course->id, 'taf')));
        $this->assertSame([], calendar_import::matching_groups($course->id, 'Nobody*'));
    }

    /**
     * A full run: the scheduled task queues the file, the adhoc task imports it and files it away.
     */
    public function test_scheduled_import_end_to_end(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/events.csv',
            "name,courseid,groupid,timestart\nOrientation,{$course->id},{$group->id},1809298800\n"
        );
        set_config('calendar_source_dir', $dir, 'tool_automate');

        // Switched off: nothing happens.
        (new task\queue_calendar_imports())->execute();
        $this->assertFileExists($dir . '/events.csv');

        set_config('allow_calendar_import', 1, 'tool_automate');
        $this->expectOutputRegex('/queued 1 calendar import file/');
        (new task\queue_calendar_imports())->execute();

        $this->assertFileDoesNotExist($dir . '/events.csv');
        $this->assertCount(1, glob($dir . '/processing/*-events.csv'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_calendar_file::class));

        $this->runAdhocTasks(task\import_calendar_file::class);

        $this->assertSame(1, $DB->count_records('event', ['groupid' => $group->id, 'eventtype' => 'group']));
        $this->assertCount(0, glob($dir . '/processing/*.csv'));
        $processed = glob($dir . '/processed/*-events.csv');
        $this->assertCount(1, $processed);
        $this->assertFileExists($processed[0] . '.results.csv');
        $this->assertStringContainsString(',ok,', file_get_contents($processed[0] . '.results.csv'));
    }

    /**
     * A file left in processing with no task to import it is queued again, once.
     */
    public function test_orphaned_processing_file_is_requeued(): void {
        $this->resetAfterTest();

        $dir = make_request_directory();
        mkdir($dir . '/processing');
        $file = $dir . '/processing/20270503-090000-events.csv';
        file_put_contents($file, "name,courseid,groupid,timestart\n");

        $outcome = calendar_import::queue_waiting_files($dir);
        $this->assertSame(['queued' => 1, 'error' => ''], $outcome);
        $tasks = \core\task\manager::get_adhoc_tasks(task\import_calendar_file::class);
        $this->assertCount(1, $tasks);
        $this->assertSame($file, reset($tasks)->get_custom_data()->filepath);

        // Now a task owns it, so the next run leaves it alone.
        $this->assertSame(['queued' => 0, 'error' => ''], calendar_import::queue_waiting_files($dir));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_calendar_file::class));
    }

    /**
     * A file that cannot be imported at all goes to the failed folder with the reason.
     */
    public function test_unreadable_file_goes_to_failed(): void {
        $this->require_importer();
        $this->resetAfterTest();

        $dir = make_request_directory();
        mkdir($dir . '/processing');
        $file = $dir . '/processing/20270503-090000-bad.csv';
        file_put_contents($file, "name,courseid\nOrientation,2\n");

        $outcome = calendar_import::import_queued_file($file);

        $this->assertNotSame('', $outcome['error']);
        $this->assertSame($dir . '/failed/20270503-090000-bad.csv', $outcome['movedto']);
        $this->assertStringContainsString('groupid', file_get_contents($outcome['movedto'] . '.results.csv'));
    }

    /**
     * Skip duplicates defaults to on until the setting is saved.
     */
    public function test_skip_duplicates_default(): void {
        $this->resetAfterTest();
        $this->assertTrue(calendar_import::skip_duplicates());
        set_config('calendar_skip_duplicates', 0, 'tool_automate');
        $this->assertFalse(calendar_import::skip_duplicates());
    }
}
