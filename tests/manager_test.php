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
 * Tests for the rule engine.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(manager::class)]
#[CoversClass(condition\email_matches::class)]
#[CoversClass(action\add_to_cohort::class)]
final class manager_test extends \advanced_testcase {
    /**
     * Create a rule with an email_matches condition and an add_to_cohort action.
     *
     * @param string $pattern Wildcard email pattern.
     * @param int $cohortid Target cohort id.
     * @return int The new rule id.
     */
    protected function create_rule(string $pattern, int $cohortid): int {
        global $DB;
        $now = time();
        $ruleid = $DB->insert_record('tool_automate_rule', (object) [
            'name'         => 'Test rule',
            'description'  => '',
            'triggertype'  => 'manual',
            'eventname'    => null,
            'enabled'      => 1,
            'usermodified' => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('tool_automate_condition', (object) [
            'ruleid'     => $ruleid,
            'type'       => 'email_matches',
            'configdata' => json_encode(['pattern' => $pattern]),
            'sortorder'  => 0,
        ]);
        $DB->insert_record('tool_automate_action', (object) [
            'ruleid'     => $ruleid,
            'type'       => 'add_to_cohort',
            'configdata' => json_encode(['cohortid' => $cohortid]),
            'sortorder'  => 0,
        ]);
        return $ruleid;
    }

    /**
     * The email_matches condition matches wildcards case-insensitively.
     */
    public function test_email_matches_condition(): void {
        $condition = new condition\email_matches(['pattern' => '*@sccaorg.au']);

        $this->assertTrue($condition->matches((object) ['email' => 'jane@sccaorg.au']));
        $this->assertTrue($condition->matches((object) ['email' => 'Jane@SCCAORG.AU']));
        $this->assertFalse($condition->matches((object) ['email' => 'jane@example.com']));
        $this->assertFalse($condition->matches((object) ['email' => '']));

        // An empty pattern never matches.
        $empty = new condition\email_matches(['pattern' => '']);
        $this->assertFalse($empty->matches((object) ['email' => 'jane@sccaorg.au']));

        // A pattern with no wildcard is treated as a substring match.
        $substring = new condition\email_matches(['pattern' => '@example.com']);
        $this->assertTrue($substring->matches((object) ['email' => 'alice@example.com']));
        $this->assertTrue($substring->matches((object) ['email' => 'Alice@Example.com']));
        $this->assertFalse($substring->matches((object) ['email' => 'alice@other.org']));
    }

    /**
     * A dry run reports the matching users but changes nothing.
     */
    public function test_run_rule_dry_run_makes_no_changes(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $match = $generator->create_user(['email' => 'jane@sccaorg.au']);
        $generator->create_user(['email' => 'bob@example.com']);
        $cohort = $generator->create_cohort();
        $ruleid = $this->create_rule('*@sccaorg.au', (int) $cohort->id);

        $results = manager::run_rule($ruleid, true);

        $this->assertCount(1, $results);
        $this->assertEquals($match->id, $results[0]->userid);
        $this->assertEquals('actioned', $results[0]->outcome);
        $this->assertFalse($DB->record_exists('cohort_members', ['cohortid' => $cohort->id]));

        // The dry run is still logged.
        $this->assertTrue($DB->record_exists(
            'tool_automate_log',
            ['ruleid' => $ruleid, 'userid' => $match->id, 'dryrun' => 1]
        ));
    }

    /**
     * A real run adds only the matching users to the cohort and logs it.
     */
    public function test_run_rule_adds_matching_users_to_cohort(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $match = $generator->create_user(['email' => 'jane@sccaorg.au']);
        $other = $generator->create_user(['email' => 'bob@example.com']);
        $cohort = $generator->create_cohort();
        $ruleid = $this->create_rule('*@sccaorg.au', (int) $cohort->id);

        $results = manager::run_rule($ruleid, false);

        $this->assertCount(1, $results);
        $this->assertTrue($DB->record_exists(
            'cohort_members',
            ['cohortid' => $cohort->id, 'userid' => $match->id]
        ));
        $this->assertFalse($DB->record_exists(
            'cohort_members',
            ['cohortid' => $cohort->id, 'userid' => $other->id]
        ));
        $this->assertTrue($DB->record_exists(
            'tool_automate_log',
            ['ruleid' => $ruleid, 'userid' => $match->id, 'dryrun' => 0]
        ));

        // Running again reports the existing membership without erroring.
        $results = manager::run_rule($ruleid, false);
        $this->assertCount(1, $results);
        $this->assertEquals('actioned', $results[0]->outcome);
    }

    /**
     * Restricting a run to one user only evaluates that user.
     */
    public function test_run_rule_restricted_to_one_user(): void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $first = $generator->create_user(['email' => 'jane@sccaorg.au']);
        $second = $generator->create_user(['email' => 'joe@sccaorg.au']);
        $cohort = $generator->create_cohort();
        $ruleid = $this->create_rule('*@sccaorg.au', (int) $cohort->id);

        $results = manager::run_rule($ruleid, false, (int) $second->id);

        $this->assertCount(1, $results);
        $this->assertEquals($second->id, $results[0]->userid);
        $this->assertFalse($DB->record_exists(
            'cohort_members',
            ['cohortid' => $cohort->id, 'userid' => $first->id]
        ));
    }
}
