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
 * Tests that the assign_role action only ever grants a role the relevant
 * user is actually allowed to assign at system context.
 *
 * The role lands at system context with no further capability check
 * inside role_assign(), so the privilege boundary is enforced twice:
 * extract_config() gates what the form can save, and execute() re-gates
 * the stored role against the rule author at run time (covering legacy
 * configs and direct DB tampering). A role outside the allowed set is
 * dropped to 0 / skipped, never assigned.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(assign_role::class)]
final class assign_role_test extends \advanced_testcase {
    /**
     * Create a role that is assignable at the system context.
     *
     * The stock student/teacher roles are not system-assignable, so a
     * positive case needs a role explicitly allowed at that level.
     *
     * @param string $shortname
     * @return int Role id.
     */
    private function make_system_assignable_role(string $shortname): int {
        $roleid = create_role(ucfirst($shortname), $shortname, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        return $roleid;
    }

    /**
     * extract_config() keeps a role the configuring admin may assign at
     * system context.
     */
    public function test_admin_keeps_assignable_role(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = $this->make_system_assignable_role('sysassignable');

        $config = assign_role::extract_config((object) ['config_roleid' => $roleid]);

        $this->assertSame($roleid, $config['roleid']);
    }

    /**
     * extract_config() drops a role the configuring user may not assign at
     * system context, even when it is submitted directly (a crafted POST
     * that bypasses the form picker).
     */
    public function test_unassignable_role_is_dropped(): void {
        global $DB;
        $this->resetAfterTest();

        // A teacher in one course can assign nothing at system context.
        $teacher = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $config = assign_role::extract_config((object) ['config_roleid' => $managerid]);

        $this->assertSame(0, $config['roleid']);
    }

    /**
     * A role id that does not exist is dropped to 0.
     */
    public function test_unknown_role_is_dropped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $config = assign_role::extract_config((object) ['config_roleid' => 999999]);

        $this->assertSame(0, $config['roleid']);
    }

    /**
     * execute() grants the role when the rule's author may assign it.
     */
    public function test_execute_assigns_when_author_allowed(): void {
        global $DB;
        $this->resetAfterTest();
        $admin = get_admin();
        $roleid = $this->make_system_assignable_role('sysassignable');
        $target = $this->getDataGenerator()->create_user();
        $systemid = (int) \context_system::instance()->id;

        $action = new assign_role(['roleid' => $roleid]);
        $action->set_rule((object) ['usermodified' => (int) $admin->id]);
        $result = $action->execute($target, false);

        $this->assertTrue(user_has_role_assignment($target->id, $roleid, $systemid));
        $this->assertEquals(
            get_string('roleassigned', 'tool_automate', role_get_name($DB->get_record('role', ['id' => $roleid]))),
            $result
        );
    }

    /**
     * execute() refuses a stored role the rule's author cannot assign -
     * the run-time guard that protects legacy / tampered configs the
     * save-time gate never saw.
     */
    public function test_execute_skips_role_author_cannot_assign(): void {
        global $DB;
        $this->resetAfterTest();

        // Author is an ordinary user with no role-assignment rights.
        $author = $this->getDataGenerator()->create_user();
        $target = $this->getDataGenerator()->create_user();
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $systemid = (int) \context_system::instance()->id;

        // Stored config grants Manager at system context - the exact
        // escalation the old picker allowed.
        $action = new assign_role(['roleid' => $managerid]);
        $action->set_rule((object) ['usermodified' => (int) $author->id]);
        $result = $action->execute($target, false);

        $this->assertFalse(user_has_role_assignment($target->id, $managerid, $systemid));
        $this->assertEquals(
            get_string('rolenotassignable', 'tool_automate', role_get_name($DB->get_record('role', ['id' => $managerid]))),
            $result
        );
    }
}
