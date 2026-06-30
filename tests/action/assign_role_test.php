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
 * Tests that the assign_role action will only ever store a role the
 * configuring user is actually allowed to assign at system context.
 *
 * The role assigned by this action lands at system context with no
 * further capability check at run time, so the privilege boundary has to
 * be enforced where the config is saved. The form picker only constrains
 * the browser; extract_config() is the real gate, and a role outside the
 * configuring user's assignable set must be dropped to 0 (a run-time
 * no-op) even when it is submitted directly.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(assign_role::class)]
final class assign_role_test extends \advanced_testcase {
    /**
     * A site admin may assign any role, so a normal selection survives.
     */
    public function test_admin_keeps_assignable_role(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $studentid = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        $config = assign_role::extract_config((object) ['config_roleid' => $studentid]);

        $this->assertSame($studentid, $config['roleid']);
    }

    /**
     * A user who is not allowed to assign a privileged role at system
     * context cannot smuggle it in via a crafted POST: extract_config
     * drops it to 0 rather than storing it for the engine to grant.
     */
    public function test_unassignable_role_is_dropped(): void {
        global $DB;
        $this->resetAfterTest();

        // A teacher in one course is nobody's role-assigner at system
        // context - get_assignable_roles() there is empty for them.
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $config = assign_role::extract_config((object) ['config_roleid' => $managerid]);

        $this->assertSame(0, $config['roleid']);
    }

    /**
     * A role id that does not exist at all is also dropped to 0.
     */
    public function test_unknown_role_is_dropped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $config = assign_role::extract_config((object) ['config_roleid' => 999999]);

        $this->assertSame(0, $config['roleid']);
    }
}
