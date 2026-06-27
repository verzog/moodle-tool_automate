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
 * Tests for the bulk restore repository helper.
 *
 * These cover the listing, path-safety and queueing logic that both the web
 * page and the CLI rely on - in particular that only bare .mbz basenames
 * inside the configured directory ever resolve, so a crafted selection can't
 * reach files elsewhere on the server.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(restore_repository::class)]
final class restore_repository_test extends \advanced_testcase {
    /** Class name of the adhoc restore task. */
    private const TASK = '\\tool_automate\\task\\restore_course';

    /**
     * Create a throwaway directory with the given filenames in it.
     *
     * @param string[] $names Filenames to create (empty content).
     * @return string Absolute path to the new directory.
     */
    protected function make_dir_with(array $names): string {
        $dir = make_request_directory();
        foreach ($names as $name) {
            file_put_contents($dir . '/' . $name, 'x');
        }
        return $dir;
    }

    /**
     * How many restore_course adhoc tasks are currently queued.
     *
     * @return int
     */
    protected function queued(): int {
        global $DB;
        return $DB->count_records('task_adhoc', ['classname' => self::TASK]);
    }

    /**
     * The feature is off until the site setting is turned on.
     */
    public function test_is_enabled_reflects_setting(): void {
        $this->resetAfterTest();
        $this->assertFalse(restore_repository::is_enabled());
        set_config('allow_bulk_restore', 1, 'tool_automate');
        $this->assertTrue(restore_repository::is_enabled());
    }

    /**
     * Only .mbz files are listed, by basename, sorted, ignoring other files.
     */
    public function test_list_backups_returns_sorted_mbz_basenames(): void {
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['b.mbz', 'a.mbz', 'notes.txt', 'archive.zip', 'C.MBZ']);

        $files = restore_repository::list_backups($dir);

        $this->assertSame(['C.MBZ', 'a.mbz', 'b.mbz'], $files);
    }

    /**
     * A missing or empty directory lists nothing rather than erroring.
     */
    public function test_list_backups_missing_directory_is_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], restore_repository::list_backups('/no/such/dir/here'));
        $this->assertSame([], restore_repository::list_backups(''));
    }

    /**
     * is_backup_filename accepts bare .mbz names and rejects paths/others.
     */
    public function test_is_backup_filename(): void {
        $this->assertTrue(restore_repository::is_backup_filename('course.mbz'));
        $this->assertTrue(restore_repository::is_backup_filename('Course.MBZ'));
        $this->assertFalse(restore_repository::is_backup_filename('course.zip'));
        $this->assertFalse(restore_repository::is_backup_filename('../course.mbz'));
        $this->assertFalse(restore_repository::is_backup_filename('sub/course.mbz'));
        $this->assertFalse(restore_repository::is_backup_filename(''));
        $this->assertFalse(restore_repository::is_backup_filename('.mbz'));
    }

    /**
     * resolve() returns the real path for a genuine in-directory backup.
     */
    public function test_resolve_accepts_a_real_backup(): void {
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['good.mbz']);

        $this->assertSame(realpath($dir . '/good.mbz'), restore_repository::resolve('good.mbz', $dir));
    }

    /**
     * resolve() rejects traversal, non-mbz and missing files.
     */
    public function test_resolve_rejects_unsafe_or_missing(): void {
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['good.mbz', 'notes.txt']);

        $this->assertNull(restore_repository::resolve('../good.mbz', $dir));
        $this->assertNull(restore_repository::resolve('sub/good.mbz', $dir));
        $this->assertNull(restore_repository::resolve('notes.txt', $dir));
        $this->assertNull(restore_repository::resolve('missing.mbz', $dir));
        $this->assertNull(restore_repository::resolve('good.mbz', ''));
    }

    /**
     * The picker token survives a filename with characters that the
     * autocomplete element's tag cleaning would otherwise mangle (comma,
     * whitespace), and maps back to the exact basename.
     */
    public function test_token_round_trips_awkward_filenames(): void {
        $this->resetAfterTest();
        $name = 'Course, Spring  2026.mbz';
        $dir = $this->make_dir_with([$name, 'plain.mbz']);

        $token = restore_repository::token($name);
        // A hex hash passes through PARAM_TAGLIST cleaning unchanged.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        $this->assertSame($name, restore_repository::basename_for_token($token, $dir));
        // And the recovered basename still resolves to the real file.
        $this->assertSame(realpath($dir . '/' . $name), restore_repository::resolve($name, $dir));
        // An unknown token resolves to nothing.
        $this->assertNull(restore_repository::basename_for_token(sha1('nope.mbz'), $dir));
    }

    /**
     * queue() enqueues a restore_course adhoc task carrying the file, target
     * category and acting user.
     */
    public function test_queue_enqueues_a_restore_task(): void {
        global $DB;
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['good.mbz']);
        $path = realpath($dir . '/good.mbz');
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();

        $this->assertSame(0, $this->queued());
        restore_repository::queue($path, (int) $category->id, (int) $user->id);
        $this->assertSame(1, $this->queued());

        $record = $DB->get_record('task_adhoc', ['classname' => self::TASK], '*', MUST_EXIST);
        $custom = (object) json_decode($record->customdata);
        $this->assertSame($path, $custom->filepath);
        $this->assertSame((int) $category->id, (int) $custom->categoryid);
        $this->assertSame((int) $user->id, (int) $custom->userid);
        $this->assertEquals((int) $user->id, (int) $record->userid);
    }
}
