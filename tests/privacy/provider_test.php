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

namespace tool_automate\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the tool_automate privacy provider.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Insert a rule authored by the given user.
     *
     * @param int $usermodified
     * @param string $name
     * @return int rule id
     */
    protected function make_rule(int $usermodified, string $name = 'Rule'): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('tool_automate_rule', (object) [
            'name'         => $name,
            'description'  => 'A rule',
            'subject'      => 'user',
            'triggertype'  => 'manual',
            'schedule'     => 'hourly',
            'enabled'      => 1,
            'usermodified' => $usermodified,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a log row for a rule, optionally attributed to a user.
     *
     * @param int $ruleid
     * @param int|null $userid
     */
    protected function make_log(int $ruleid, ?int $userid): void {
        global $DB;
        $DB->insert_record('tool_automate_log', (object) [
            'ruleid'      => $ruleid,
            'userid'      => $userid,
            'dryrun'      => 0,
            'outcome'     => 'actioned',
            'message'     => 'Did the thing',
            'timecreated' => time(),
        ]);
    }

    /**
     * Insert an assign_role action on a rule, configured by the given user.
     *
     * @param int $ruleid
     * @param int $authorid
     * @return int action id
     */
    protected function make_assign_role_action(int $ruleid, int $authorid): int {
        global $DB;
        return (int) $DB->insert_record('tool_automate_action', (object) [
            'ruleid'     => $ruleid,
            'type'       => 'assign_role',
            'configdata' => json_encode(['roleid' => 0, 'authorid' => $authorid]),
            'sortorder'  => 0,
        ]);
    }

    /**
     * Read the stored authorid back off an action.
     *
     * @param int $actionid
     * @return int
     */
    protected function action_authorid(int $actionid): int {
        global $DB;
        $config = (array) json_decode(
            (string) $DB->get_field('tool_automate_action', 'configdata', ['id' => $actionid]),
            true
        );
        return (int) ($config['authorid'] ?? 0);
    }

    /**
     * The metadata describes both stored tables.
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('tool_automate');
        $items = provider::get_metadata($collection)->get_collection();

        $tables = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('tool_automate_rule', $tables);
        $this->assertContains('tool_automate_log', $tables);
    }

    /**
     * A user is reported at the system context iff they authored a rule
     * or appear in a log row; otherwise no context is returned.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $nobody = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $author->id);
        $this->make_log($ruleid, (int) $subject->id);

        $this->assertCount(1, provider::get_contexts_for_userid((int) $author->id)->get_contextids());
        $this->assertCount(1, provider::get_contexts_for_userid((int) $subject->id)->get_contextids());
        $this->assertCount(0, provider::get_contexts_for_userid((int) $nobody->id)->get_contextids());
    }

    /**
     * The reverse lookup finds both rule authors and logged subjects.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $author->id);
        $this->make_log($ruleid, (int) $subject->id);
        $this->make_log($ruleid, null);

        $userlist = new userlist(\context_system::instance(), 'tool_automate');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();

        $this->assertContains((int) $author->id, $userids);
        $this->assertContains((int) $subject->id, $userids);
    }

    /**
     * Export produces data for a user who has some, and nothing for one
     * who doesn't.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $author = $this->getDataGenerator()->create_user();
        $nobody = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $author->id);
        $this->make_log($ruleid, (int) $author->id);

        $this->export_context_data_for_user((int) $author->id, $context, 'tool_automate');
        $this->assertTrue(writer::with_context($context)->has_any_data());

        writer::reset();
        $this->export_context_data_for_user((int) $nobody->id, $context, 'tool_automate');
        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * Deleting one user anonymises their rule attribution and removes
     * their log rows, leaving other users untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $context = \context_system::instance();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();

        $aliceruleid = $this->make_rule((int) $alice->id, 'Alice rule');
        $bobruleid = $this->make_rule((int) $bob->id, 'Bob rule');
        $this->make_log($aliceruleid, (int) $alice->id);
        $this->make_log($bobruleid, (int) $bob->id);

        $approved = new approved_contextlist($alice, 'tool_automate', [$context->id]);
        provider::delete_data_for_user($approved);

        // Alice's rule is anonymised (kept as config), her log row gone.
        $this->assertEquals(0, $DB->get_field('tool_automate_rule', 'usermodified', ['id' => $aliceruleid]));
        $this->assertFalse($DB->record_exists('tool_automate_log', ['userid' => $alice->id]));

        // Bob is untouched.
        $this->assertEquals($bob->id, $DB->get_field('tool_automate_rule', 'usermodified', ['id' => $bobruleid]));
        $this->assertTrue($DB->record_exists('tool_automate_log', ['userid' => $bob->id]));
    }

    /**
     * Deleting a set of users only affects the listed users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();
        $context = \context_system::instance();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();

        $aliceruleid = $this->make_rule((int) $alice->id);
        $bobruleid = $this->make_rule((int) $bob->id);
        $this->make_log($aliceruleid, (int) $alice->id);
        $this->make_log($bobruleid, (int) $bob->id);

        $approved = new approved_userlist($context, 'tool_automate', [(int) $alice->id]);
        provider::delete_data_for_users($approved);

        $this->assertEquals(0, $DB->get_field('tool_automate_rule', 'usermodified', ['id' => $aliceruleid]));
        $this->assertFalse($DB->record_exists('tool_automate_log', ['userid' => $alice->id]));
        $this->assertEquals($bob->id, $DB->get_field('tool_automate_rule', 'usermodified', ['id' => $bobruleid]));
        $this->assertTrue($DB->record_exists('tool_automate_log', ['userid' => $bob->id]));
    }

    /**
     * A context-wide purge anonymises every rule and deletes every log
     * row, including the user-null aggregate rows.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        $context = \context_system::instance();
        $alice = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $alice->id);
        $this->make_log($ruleid, (int) $alice->id);
        $this->make_log($ruleid, null);

        provider::delete_data_for_all_users_in_context($context);

        $this->assertEquals(0, $DB->count_records_select('tool_automate_rule', 'usermodified <> 0'));
        $this->assertEquals(0, $DB->count_records('tool_automate_log'));
    }

    /**
     * A user who only configured an assign_role action (and authored no
     * rule and no log row) is still discovered as having data here.
     */
    public function test_action_configurer_is_discovered(): void {
        $this->resetAfterTest();
        $configurer = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $other->id);
        $this->make_assign_role_action($ruleid, (int) $configurer->id);

        $this->assertCount(1, provider::get_contexts_for_userid((int) $configurer->id)->get_contextids());

        $userlist = new userlist(\context_system::instance(), 'tool_automate');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $configurer->id, $userlist->get_userids());
    }

    /**
     * Deleting a user anonymises the configurer they recorded on an
     * assign_role action, but leaves another user's action intact.
     */
    public function test_delete_anonymises_action_configurer(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $alice = $this->getDataGenerator()->create_user();
        $bob = $this->getDataGenerator()->create_user();

        $ruleid = $this->make_rule((int) $alice->id);
        $aliceaction = $this->make_assign_role_action($ruleid, (int) $alice->id);
        $bobaction = $this->make_assign_role_action($ruleid, (int) $bob->id);

        $approved = new approved_contextlist($alice, 'tool_automate', [$context->id]);
        provider::delete_data_for_user($approved);

        $this->assertEquals(0, $this->action_authorid($aliceaction));
        $this->assertEquals((int) $bob->id, $this->action_authorid($bobaction));
    }
}
