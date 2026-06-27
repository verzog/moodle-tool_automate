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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Integration test for the restore_course adhoc task.
 *
 * Backs up a real course to an .mbz on disk, then runs the task to confirm it
 * restores that backup into a brand-new course in the requested category. Also
 * checks the task is a quiet no-op when its backup file has gone missing.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(restore_course::class)]
final class restore_course_test extends \advanced_testcase {
    /**
     * Back a course up and write the .mbz into a throwaway directory.
     *
     * @param \stdClass $course Course to back up.
     * @return string Absolute path to the written .mbz file.
     */
    protected function backup_course_to_file(\stdClass $course): string {
        global $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->execute_plan();
        $results = $bc->get_results();
        $bc->destroy();

        $this->assertArrayHasKey('backup_destination', $results);
        $file = $results['backup_destination'];
        $this->assertNotEmpty($file);

        $path = make_request_directory() . '/course.mbz';
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * A queued backup is restored into a new course in the target category.
     */
    public function test_execute_restores_backup_into_new_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_bulk_restore', 1, 'tool_automate');

        $generator = $this->getDataGenerator();
        $source = $generator->create_course(['fullname' => 'Repo Source', 'shortname' => 'reposrc']);
        $targetcat = $generator->create_category();

        $path = $this->backup_course_to_file($source);

        // Remove the original so the restored course's shortname is free -
        // mirrors restoring a backup of a course no longer on the site.
        delete_course($source->id, false);
        $this->assertFalse($DB->record_exists('course', ['shortname' => 'reposrc']));

        $task = new restore_course();
        $task->set_custom_data([
            'filepath'   => $path,
            'categoryid' => (int) $targetcat->id,
            'userid'     => (int) get_admin()->id,
        ]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $restored = $DB->get_record('course', ['shortname' => 'reposrc']);
        $this->assertNotFalse($restored);
        $this->assertEquals($targetcat->id, $restored->category);
        $this->assertEquals('Repo Source', $restored->fullname);
    }

    /**
     * With the site kill-switch off, a queued task is a no-op even when its
     * backup file is present - it never reaches extraction or course creation.
     */
    public function test_execute_respects_kill_switch(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('allow_bulk_restore', 0, 'tool_automate');
        $targetcat = $this->getDataGenerator()->create_category();
        $path = make_request_directory() . '/dummy.mbz';
        file_put_contents($path, 'not a real backup');

        $before = $DB->count_records('course');

        $task = new restore_course();
        $task->set_custom_data([
            'filepath'   => $path,
            'categoryid' => (int) $targetcat->id,
            'userid'     => (int) get_admin()->id,
        ]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals($before, $DB->count_records('course'));
    }

    /**
     * If the backup file has vanished before the task runs, it is a no-op and
     * does not create a course.
     */
    public function test_execute_missing_file_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $targetcat = $this->getDataGenerator()->create_category();

        $before = $DB->count_records('course');

        $task = new restore_course();
        $task->set_custom_data([
            'filepath'   => '/no/such/backup.mbz',
            'categoryid' => (int) $targetcat->id,
            'userid'     => (int) get_admin()->id,
        ]);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals($before, $DB->count_records('course'));
    }
}
