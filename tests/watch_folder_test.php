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
 * Tests for watch-folder ("Find files that...") rules.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(watch_folder::class)]
#[CoversClass(condition\file_in_folder::class)]
#[CoversClass(condition\file_name_matches::class)]
#[CoversClass(action\file_import_base::class)]
#[CoversClass(action\file_import_to_course::class)]
#[CoversClass(action\file_import_to_holding::class)]
#[CoversClass(admin\setting_watch_base_dir::class)]
final class watch_folder_test extends \advanced_testcase {
    /**
     * Skip a test that needs the Book importer when it is not installed.
     */
    private function require_importer(): void {
        if (!isset(slide_import::available_types()['book'])) {
            $this->markTestSkipped('The PowerPoint import plugin for Book is not installed');
        }
    }

    /**
     * Switch watch folders on, with a base folder holding the given watch folders.
     *
     * @param string[] $folders Watch folder names to create.
     * @return string The base folder.
     */
    private function enable(array $folders = ['inbox']): string {
        $base = make_request_directory();
        foreach ($folders as $folder) {
            mkdir($base . '/' . $folder);
        }
        set_config('watch_base_dir', $base, 'tool_automate');
        set_config('allow_watch_folders', 1, 'tool_automate');
        return $base;
    }

    /**
     * Copy the Book importer's sample deck (nine slides) into a folder.
     *
     * @param string $dir Destination folder.
     * @param string $name Destination file name.
     */
    private function drop_deck(string $dir, string $name): void {
        global $CFG;
        copy($CFG->dirroot . '/mod/book/tool/importpptx/tests/fixtures/sample.pptx', $dir . '/' . $name);
    }

    /**
     * Create a file rule with one folder condition and one action.
     *
     * @param string $folder The watch folder the rule reads.
     * @param string $actiontype The action's type code.
     * @param array $actionconfig The action's config.
     * @return int The rule id.
     */
    private function create_rule(string $folder, string $actiontype, array $actionconfig): int {
        global $DB, $USER;
        $ruleid = $DB->insert_record('tool_automate_rule', (object) [
            'name' => 'Week slides',
            'subject' => 'file',
            'enabled' => 1,
            'triggertype' => 'manual',
            'logic' => 'all',
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('tool_automate_condition', (object) [
            'ruleid' => $ruleid,
            'type' => 'file_in_folder',
            'configdata' => json_encode(['folder' => $folder]),
            'sortorder' => 0,
        ]);
        $DB->insert_record('tool_automate_action', (object) [
            'ruleid' => $ruleid,
            'type' => $actiontype,
            'configdata' => json_encode($actionconfig + ['type' => 'book', 'authorid' => $USER->id]),
            'sortorder' => 0,
        ]);
        return $ruleid;
    }

    /**
     * Watch folder names are bare, visible names, and never the filing subfolders.
     */
    public function test_is_folder_name(): void {
        $this->assertTrue(watch_folder::is_folder_name('inbox'));
        $this->assertTrue(watch_folder::is_folder_name('Biology 101'));
        $this->assertFalse(watch_folder::is_folder_name(''));
        $this->assertFalse(watch_folder::is_folder_name('.hidden'));
        $this->assertFalse(watch_folder::is_folder_name('..'));
        $this->assertFalse(watch_folder::is_folder_name('a/b'));
        $this->assertFalse(watch_folder::is_folder_name('a\\b'));
        $this->assertFalse(watch_folder::is_folder_name('processing'));
        $this->assertFalse(watch_folder::is_folder_name('Processed'));
        $this->assertFalse(watch_folder::is_folder_name('failed'));
    }

    /**
     * Only real folders directly inside the base folder are watch folders; links are ignored.
     */
    public function test_list_folders_and_files(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable(['inbox', 'biology', 'processing']);
        $elsewhere = make_request_directory();
        symlink($elsewhere, $base . '/linked');
        file_put_contents($base . '/loose.pptx', 'x');
        file_put_contents($base . '/inbox/b.pptx', 'x');
        file_put_contents($base . '/inbox/a.pdf', 'x');
        file_put_contents($base . '/inbox/notes.txt', 'x');
        file_put_contents($elsewhere . '/secret.pptx', 'x');
        symlink($elsewhere . '/secret.pptx', $base . '/biology/link.pptx');

        $this->assertSame(['.', 'biology', 'inbox'], watch_folder::list_folders());
        $this->assertNull(watch_folder::folder_path('linked'));
        $this->assertNull(watch_folder::folder_path('../' . basename($elsewhere)));
        $files = watch_folder::list_files();
        $this->assertSame(['loose.pptx', 'a.pdf', 'b.pptx'], array_column($files, 'name'));
        $this->assertSame(['.', 'inbox', 'inbox'], array_column($files, 'folder'));
        $this->assertSame([1, 2, 3], array_column($files, 'id'));
    }

    /**
     * A linked "processing" subfolder is never followed, and linked decks are never accepted.
     */
    public function test_linked_processing_folder_is_refused(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable(['inbox', 'other']);
        $elsewhere = make_request_directory();
        $this->drop_deck($elsewhere, 'Outside.pptx');
        symlink($elsewhere, $base . '/inbox/processing');
        mkdir($base . '/other/processing');
        symlink($elsewhere . '/Outside.pptx', $base . '/other/processing/Linked.pptx');

        $this->assertSame(0, watch_folder::requeue_orphans());
        $this->assertSame('outside', slide_import::check_queued_path($base . '/inbox/processing/Outside.pptx'));
        $this->assertSame('outside', slide_import::check_queued_path($base . '/other/processing/Linked.pptx'));

        // A deck cannot be queued through a linked processing subfolder either.
        $this->drop_deck($base . '/inbox', 'Deck.pptx');
        $this->assertFalse(slide_import::queue_deck($base . '/inbox', 'Deck.pptx', ['courseid' => 2, 'name' => 'Deck']));
        $this->assertFileExists($base . '/inbox/Deck.pptx');
        $this->assertFileExists($elsewhere . '/Outside.pptx');
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * Every waiting file reaches the rule's conditions, however many are waiting ahead of it.
     */
    public function test_all_waiting_files_are_considered(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable(['a-busy', 'z-mine']);
        for ($i = 0; $i < 600; $i++) {
            touch($base . '/a-busy/deck' . $i . '.pptx');
        }
        touch($base . '/z-mine/Wanted.pptx');

        $files = watch_folder::list_files();
        $this->assertCount(601, $files);
        $this->assertSame('Wanted.pptx', end($files)->name);
    }

    /**
     * A deck goes to one place: a second import action in the same rule says it was taken.
     */
    public function test_second_import_action_reports_deck_taken(): void {
        global $DB, $USER;
        $this->require_importer();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $base = $this->enable();
        $this->drop_deck($base . '/inbox', 'Deck.pptx');
        $ruleid = $this->create_rule('inbox', 'file_import_to_course', ['courseid' => $course->id]);
        $DB->insert_record('tool_automate_action', (object) [
            'ruleid' => $ruleid,
            'type' => 'file_import_to_course',
            'configdata' => json_encode(['courseid' => $other->id, 'type' => 'book', 'authorid' => $USER->id]),
            'sortorder' => 1,
        ]);

        foreach ([true, false] as $dryrun) {
            $results = manager::run_rule($ruleid, $dryrun);
            $this->assertCount(2, $results);
            $this->assertStringContainsString($dryrun ? 'Would import' : 'Queued', $results[0]->message);
            $this->assertStringContainsString('already taken', $results[1]->message);
        }
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * Watch folders need the switch, a base folder and an importer.
     */
    public function test_is_enabled(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $this->assertFalse(watch_folder::is_enabled());
        $this->enable();
        $this->assertTrue(watch_folder::is_enabled());
        set_config('allow_watch_folders', 0, 'tool_automate');
        $this->assertFalse(watch_folder::is_enabled());
        $this->assertSame('user', manager::clean_subject('file'));
        set_config('allow_watch_folders', 1, 'tool_automate');
        $this->assertSame('file', manager::clean_subject('file'));
        $this->assertSame('user', manager::clean_subject('nonsense'));
    }

    /**
     * The two file conditions test the folder and the name.
     */
    public function test_conditions(): void {
        $file = (object) ['id' => 1, 'folder' => 'inbox', 'name' => 'Week 1 slides.pptx', 'path' => '/x'];
        $this->assertTrue((new condition\file_in_folder(['folder' => 'inbox']))->matches($file));
        $this->assertFalse((new condition\file_in_folder(['folder' => 'biology']))->matches($file));
        $this->assertFalse((new condition\file_in_folder(['folder' => '']))->matches($file));
        $this->assertTrue((new condition\file_name_matches(['pattern' => 'week*.pptx']))->matches($file));
        $this->assertFalse((new condition\file_name_matches(['pattern' => 'Lecture*']))->matches($file));
        $this->assertFalse((new condition\file_name_matches(['pattern' => '']))->matches($file));
    }

    /**
     * A rule imports a deck from its folder into the chosen course, leaving other folders alone.
     */
    public function test_import_to_course_end_to_end(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $base = $this->enable(['inbox', 'other']);
        $this->drop_deck($base . '/inbox', 'Week 1 slides.pptx');
        $this->drop_deck($base . '/other', 'Not mine.pptx');
        $ruleid = $this->create_rule('inbox', 'file_import_to_course', ['courseid' => $course->id, 'section' => 1]);

        // Preview: says what would happen and changes nothing.
        $results = manager::run_rule($ruleid, true);
        $this->assertCount(1, $results);
        $this->assertStringContainsString('Would import "Week 1 slides.pptx"', $results[0]->message);
        $this->assertSame('inbox/Week 1 slides.pptx', $results[0]->fullname);
        $this->assertFileExists($base . '/inbox/Week 1 slides.pptx');
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));

        $results = manager::run_rule($ruleid, false);
        $this->assertCount(1, $results);
        $this->assertSame('actioned', $results[0]->outcome);
        $this->assertStringContainsString('Queued', $results[0]->message);
        $this->assertFileDoesNotExist($base . '/inbox/Week 1 slides.pptx');
        $this->assertFileExists($base . '/other/Not mine.pptx');

        $this->expectOutputRegex('/created 9 book page/');
        $this->runAdhocTasks(task\import_slide_file::class);

        $book = $DB->get_record('book', ['course' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Week 1 slides', $book->name);
        $this->assertEquals(1, get_coursemodule_from_instance('book', $book->id, 0, true, MUST_EXIST)->sectionnum);
        $this->assertEquals(9, $DB->count_records('book_chapters', ['bookid' => $book->id]));
        $this->assertCount(1, slide_import::list_files($base . '/inbox/processed'));
        $this->assertCount(1, glob($base . '/inbox/processed/*.results.csv'));

        // Nothing is waiting any more, so a second run does nothing.
        $this->assertSame([], manager::run_rule($ruleid, false));
    }

    /**
     * A rule can watch the base folder itself, so the decks' own folder can be the setting.
     */
    public function test_base_folder_is_a_watch_folder(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $this->require_importer();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $base = $this->enable([]);
        $this->drop_deck($base, 'Week 1 slides.pptx');
        $ruleid = $this->create_rule(watch_folder::BASE, 'file_import_to_course', ['courseid' => $course->id]);

        $this->assertSame(['.'], watch_folder::list_folders());
        $this->assertSame('The base folder itself', watch_folder::folder_label('.'));
        $this->assertStringContainsString('The base folder itself', condition\file_in_folder::describe(['folder' => '.']));
        $setting = new admin\setting_watch_base_dir('tool_automate/watch_base_dir', 'Base', '', '', PARAM_RAW_TRIMMED);
        $this->assertStringContainsString('The base folder itself', $setting->output_html($base));

        $results = manager::run_rule($ruleid, false);
        $this->assertSame('Week 1 slides.pptx', $results[0]->fullname);
        $this->assertStringContainsString('Queued', $results[0]->message);
        $this->expectOutputRegex('/created 9 book page/');
        $this->runAdhocTasks(task\import_slide_file::class);

        $this->assertEquals(1, $DB->count_records('book', ['course' => $course->id]));
        $this->assertCount(1, slide_import::list_files($base . '/processed'));
    }

    /**
     * The base folder is not offered while it is also the slide import folder.
     */
    public function test_base_folder_shared_with_slide_import_is_not_a_watch_folder(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable(['inbox']);
        mkdir($base . '/processed');
        set_config('slide_source_dir', $base, 'tool_automate');
        file_put_contents($base . '/loose.pptx', 'x');

        $this->assertSame(['inbox'], watch_folder::list_folders());
        $this->assertNull(watch_folder::folder_path('.'));
        $this->assertSame([], watch_folder::list_files());
        $this->assertFalse((new condition\file_in_folder(['folder' => 'processed']))->matches((object) ['folder' => 'processed']));

        rmdir($base . '/inbox');
        $setting = new admin\setting_watch_base_dir('tool_automate/watch_base_dir', 'Base', '', '', PARAM_RAW_TRIMMED);
        $this->assertStringContainsString('No watch folders a rule can pick', $setting->output_html($base));
    }

    /**
     * A rule creates one hidden holding course, then reuses it on later runs.
     */
    public function test_import_to_holding_course(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $base = $this->enable();
        $this->drop_deck($base . '/inbox', 'Deck one.pptx');
        $ruleid = $this->create_rule('inbox', 'file_import_to_holding', ['categoryid' => $category->id]);
        $courses = $DB->count_records('course');

        $results = manager::run_rule($ruleid, true);
        $this->assertStringContainsString('a new temporary course', $results[0]->message);
        $this->assertEquals($courses, $DB->count_records('course'));

        manager::run_rule($ruleid, false);
        $holding = watch_folder::find_holding_course($ruleid);
        $this->assertNotNull($holding);
        $this->assertEquals(0, $holding->visible);
        $this->assertEquals($category->id, $holding->category);
        $this->assertStringContainsString('Week slides', $holding->fullname);

        $this->drop_deck($base . '/inbox', 'Deck two.pptx');
        manager::run_rule($ruleid, false);
        $this->assertEquals($courses + 1, $DB->count_records('course'));

        $this->expectOutputRegex('/created 9 book page.*created 9 book page/s');
        $this->runAdhocTasks(task\import_slide_file::class);
        $this->assertEquals(2, $DB->count_records('book', ['course' => $holding->id]));
    }

    /**
     * Once the author may no longer create courses, the rule stops adding to its holding course.
     */
    public function test_holding_course_reuse_rechecks_the_author(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $author = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'coursecreator'], MUST_EXIST);
        role_assign($roleid, $author->id, \context_coursecat::instance($category->id));
        $this->setUser($author);
        $base = $this->enable();
        $this->drop_deck($base . '/inbox', 'Deck one.pptx');
        $ruleid = $this->create_rule('inbox', 'file_import_to_holding', ['categoryid' => $category->id]);

        $results = manager::run_rule($ruleid, false);
        $this->assertStringContainsString('Queued', $results[0]->message);
        $this->assertNotNull(watch_folder::find_holding_course($ruleid));

        role_unassign($roleid, $author->id, \context_coursecat::instance($category->id)->id);
        $this->drop_deck($base . '/inbox', 'Deck two.pptx');
        $results = manager::run_rule($ruleid, false);

        $this->assertStringContainsString('no longer allowed', $results[0]->message);
        $this->assertFileExists($base . '/inbox/Deck two.pptx');
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * The author's permission is checked when the rule runs, not just when it was set up.
     */
    public function test_author_permission_is_checked_at_run_time(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $category = $this->getDataGenerator()->create_category();
        $author = $this->getDataGenerator()->create_user();
        $this->setUser($author);
        $base = $this->enable();
        $this->drop_deck($base . '/inbox', 'Deck.pptx');
        $tocourse = $this->create_rule('inbox', 'file_import_to_course', ['courseid' => $course->id]);
        $toholding = $this->create_rule('inbox', 'file_import_to_holding', ['categoryid' => $category->id]);
        $courses = $DB->count_records('course');

        foreach ([$tocourse, $toholding] as $ruleid) {
            $results = manager::run_rule($ruleid, false);
            $this->assertStringContainsString('no longer allowed', $results[0]->message);
        }
        $this->assertFileExists($base . '/inbox/Deck.pptx');
        $this->assertEquals($courses, $DB->count_records('course'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * Switching watch folders off stops a rule finding files, and stops queued decks importing.
     */
    public function test_switched_off(): void {
        global $DB;
        $this->require_importer();
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $base = $this->enable();
        $this->drop_deck($base . '/inbox', 'Deck.pptx');
        $ruleid = $this->create_rule('inbox', 'file_import_to_course', ['courseid' => $course->id]);
        manager::run_rule($ruleid, false);
        $this->drop_deck($base . '/inbox', 'Later.pptx');
        set_config('allow_watch_folders', 0, 'tool_automate');

        $this->assertSame([], manager::run_rule($ruleid, false));
        $this->expectOutputRegex('/slide import is switched off/');
        $this->runAdhocTasks(task\import_slide_file::class);

        $this->assertEquals(0, $DB->count_records('book'));
        $this->assertCount(1, slide_import::list_files($base . '/inbox/processing'));
        $this->assertFileExists($base . '/inbox/Later.pptx');
    }

    /**
     * Queued paths are only accepted from the slide import folder or a watch folder.
     */
    public function test_check_queued_path(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable();
        $elsewhere = make_request_directory();

        $this->assertSame('ok', slide_import::check_queued_path($base . '/inbox/processing/deck.pptx'));
        $this->assertSame('outside', slide_import::check_queued_path($base . '/inbox/deck.pptx'));
        $this->assertSame('ok', slide_import::check_queued_path($base . '/processing/deck.pptx'));
        $this->assertSame('outside', slide_import::check_queued_path($base . '/missing/processing/deck.pptx'));
        $this->assertSame('outside', slide_import::check_queued_path($elsewhere . '/processing/deck.pptx'));
        $this->assertSame('outside', slide_import::check_queued_path($base . '/inbox/processing/deck.php'));
        set_config('allow_watch_folders', 0, 'tool_automate');
        $this->assertSame('off', slide_import::check_queued_path($base . '/inbox/processing/deck.pptx'));
    }

    /**
     * A deck a rule moved into processing but never queued is picked up by the scheduled task.
     */
    public function test_orphans_are_requeued(): void {
        $this->require_importer();
        $this->resetAfterTest();
        $base = $this->enable();
        mkdir($base . '/inbox/processing');
        $this->drop_deck($base . '/inbox/processing', '20260101-000000-Deck.pptx');

        $this->expectOutputRegex('/queued 1 slide deck\(s\) left waiting in watch folders/');
        (new task\queue_slide_imports())->execute();
        (new task\queue_slide_imports())->execute();

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }
}
