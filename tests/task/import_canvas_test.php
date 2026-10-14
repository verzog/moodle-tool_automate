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
use tool_automate\canvas_repository;

/**
 * Integration test for the import_canvas adhoc task.
 *
 * The importer (CourseUplift) is a soft dependency that this plugin's CI does not
 * install, so the test skips when it is absent.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(import_canvas::class)]
final class import_canvas_test extends \advanced_testcase {
    /**
     * A user who may manage Automate but has been denied the importer's own "use"
     * capability can't queue an import through Automate: the task skips the package.
     *
     * @return void
     */
    public function test_execute_skips_user_denied_importer_capability(): void {
        $this->resetAfterTest();
        if (!canvas_repository::is_available()) {
            $this->markTestSkipped('No importer plugin is installed in this environment');
        }
        set_config('allow_bulk_canvas', 1, 'tool_automate');
        $category = $this->getDataGenerator()->create_category();
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/automate:manage', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        $package = make_request_directory() . '/course.imscc';
        file_put_contents($package, 'not a real package');

        $task = new import_canvas();
        $task->set_custom_data([
            'sourcetype' => canvas_repository::SOURCE_FILE,
            'source' => $package,
            'categoryid' => $category->id,
            'userid' => $user->id,
            'mode' => canvas_repository::MODE_ANALYSE,
        ]);
        $this->expectOutputRegex('/may not use CourseUplift, skipping Canvas import/');
        $task->execute();

        $this->assertSame([], canvas_repository::list_jobs((int) $user->id));
    }
}
