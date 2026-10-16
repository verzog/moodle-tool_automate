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
use tool_automate\calendar_import;

/**
 * Tests for the "Create group calendar event" course action.
 *
 * These need local_grpcalendarimport v1.2.0 or later and are skipped without it.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(course_create_group_event::class)]
final class course_create_group_event_test extends \advanced_testcase {
    /** @var int Event start used by the tests: 03/05/2027 09:00 Australia/Sydney. */
    private const TIMESTART = 1809298800;

    /**
     * Skip when the importer plugin is not installed.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!calendar_import::is_available()) {
            $this->markTestSkipped('local_grpcalendarimport v1.2.0 or later is not installed');
        }
    }

    /**
     * Build the action as an admin would configure it.
     *
     * @param string $pattern Group name pattern.
     * @return course_create_group_event
     */
    private function make_action(string $pattern): course_create_group_event {
        $config = course_create_group_event::extract_config((object) [
            'config_name' => 'Orientation',
            'config_timestart' => self::TIMESTART,
            'config_timeduration' => 5400,
            'config_location' => 'Room 101',
            'config_description' => 'Welcome session',
            'config_grouppattern' => $pattern,
        ]);
        return new course_create_group_event($config);
    }

    /**
     * Count group events in a course.
     *
     * @param int $courseid
     * @return int
     */
    private function count_events(int $courseid): int {
        global $DB;
        return $DB->count_records('event', ['courseid' => $courseid, 'eventtype' => 'group']);
    }

    /**
     * Only groups matching the pattern get the event, and a second run skips them.
     */
    public function test_creates_events_in_matching_groups_once(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $cohorta = $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Cohort A']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Cohort B']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Staff']);

        $action = $this->make_action('cohort*');
        $action->execute($course, false);

        $this->assertSame(2, $this->count_events($course->id));
        $event = $DB->get_record('event', ['groupid' => $cohorta->id], '*', MUST_EXIST);
        $this->assertSame('Orientation', $event->name);
        $this->assertEquals(self::TIMESTART, $event->timestart);
        $this->assertEquals(5400, $event->timeduration);
        $this->assertSame('Room 101', $event->location);

        $message = $action->execute($course, false);
        $this->assertSame(2, $this->count_events($course->id));
        $this->assertStringContainsString('created 0 event(s), 2 already existed', $message);
    }

    /**
     * A blank pattern means every group in the course.
     */
    public function test_blank_pattern_means_all_groups(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Cohort A']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Staff']);

        $this->make_action('')->execute($course, false);

        $this->assertSame(2, $this->count_events($course->id));
    }

    /**
     * A dry run reports what it would do and creates nothing.
     */
    public function test_dry_run_creates_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Cohort A']);

        $message = $this->make_action('Cohort')->execute($course, true);

        $this->assertSame(0, $this->count_events($course->id));
        $this->assertStringContainsString('would create 1 event(s)', $message);
    }

    /**
     * A course with no matching groups is reported, not an error.
     */
    public function test_no_matching_groups(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Skin checks']);
        $this->getDataGenerator()->create_group(['courseid' => $course->id, 'name' => 'Staff']);

        $message = $this->make_action('Cohort*')->execute($course, false);

        $this->assertSame(get_string('calendarevent_nogroups', 'tool_automate', 'Skin checks'), $message);
    }

    /**
     * The action stops working once its author loses the importer capability.
     */
    public function test_author_without_capability_is_refused(): void {
        global $DB;
        $this->resetAfterTest();

        $author = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(calendar_import::CAPABILITY, CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $author->id, \context_system::instance()->id);
        $this->setUser($author);
        $action = $this->make_action('');

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        // Take the capability away, then run the rule from cron (no logged-in user).
        unassign_capability(calendar_import::CAPABILITY, $roleid, \context_system::instance()->id);
        $this->setUser(null);
        $message = $action->execute($course, false);

        $this->assertSame(get_string('calendarevent_notallowed', 'tool_automate'), $message);
        $this->assertSame(0, $DB->count_records('event', ['courseid' => $course->id]));
    }

    /**
     * The action is only offered to users who hold the importer capability.
     */
    public function test_picker_needs_importer_capability(): void {
        $this->resetAfterTest();

        $this->setAdminUser();
        $this->assertArrayHasKey('course_create_group_event', \tool_automate\manager::get_action_types_for_subject('course'));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertArrayNotHasKey('course_create_group_event', \tool_automate\manager::get_action_types_for_subject('course'));
    }

    /**
     * The summary names the event, date and groups.
     */
    public function test_describe(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $summary = course_create_group_event::describe(['name' => 'Orientation', 'timestart' => self::TIMESTART]);

        $this->assertStringContainsString('"Orientation"', $summary);
        $this->assertStringContainsString(get_string('calendarevent_allgroups', 'tool_automate'), $summary);
    }
}
