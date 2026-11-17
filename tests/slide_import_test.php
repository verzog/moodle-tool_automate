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
 * Tests for importing queued slide decks (soft integration with the PowerPoint import plugins).
 *
 * The rules that queue decks are covered by {@see watch_folder_test}; this covers what happens
 * to a deck once it is queued.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(slide_import::class)]
#[CoversClass(task\import_slide_file::class)]
final class slide_import_test extends \advanced_testcase {
    /**
     * Skip a test that needs an importer plugin when it is not installed.
     *
     * @param string $type 'book' or 'lesson'.
     */
    private function require_importer(string $type): void {
        if (!isset(slide_import::available_types()[$type])) {
            $this->markTestSkipped('The PowerPoint import plugin for ' . $type . ' is not installed');
        }
    }

    /**
     * Switch watch folders on, with a base folder holding one watch folder called "inbox".
     *
     * @return string The watch folder's path.
     */
    private function enable(): string {
        $base = make_request_directory();
        mkdir($base . '/inbox');
        set_config('watch_base_dir', $base, 'tool_automate');
        set_config('allow_watch_folders', 1, 'tool_automate');
        return $base . '/inbox';
    }

    /**
     * Copy an importer plugin's sample deck (nine slides) into a folder.
     *
     * @param string $dir Destination folder.
     * @param string $name Destination file name.
     */
    private function drop_deck(string $dir, string $name): void {
        global $CFG;
        foreach (['/mod/book/tool/importpptx', '/local/lessonimportpptx'] as $plugindir) {
            $fixture = $CFG->dirroot . $plugindir . '/tests/fixtures/sample.pptx';
            if (is_file($fixture)) {
                copy($fixture, $dir . '/' . $name);
                return;
            }
        }
        $this->markTestSkipped('No PowerPoint import plugin fixture is available');
    }

    /**
     * The one results file in a folder, as rows keyed by heading.
     *
     * @param string $dir
     * @return array[]
     */
    private function results(string $dir): array {
        $files = glob($dir . '/*.results.csv');
        $this->assertCount(1, $files);
        $rows = array_map(fn($line) => str_getcsv($line, ',', '"', ''), file($files[0], FILE_IGNORE_NEW_LINES));
        $header = array_shift($rows);
        return array_map(fn($row) => array_combine($header, $row), $rows);
    }

    /**
     * Only bare deck names are picked up.
     */
    public function test_is_deck_filename(): void {
        $this->assertTrue(slide_import::is_deck_filename('Week 1 slides.pptx'));
        $this->assertTrue(slide_import::is_deck_filename('HANDOUT.PDF'));
        $this->assertFalse(slide_import::is_deck_filename('.pptx'));
        $this->assertFalse(slide_import::is_deck_filename('.hidden.pptx'));
        $this->assertFalse(slide_import::is_deck_filename('deck.pptx.php'));
        $this->assertFalse(slide_import::is_deck_filename('deck.pptx.target.json'));
        $this->assertFalse(slide_import::is_deck_filename('../deck.pptx'));
        $this->assertFalse(slide_import::is_deck_filename('sub/deck.pptx'));
        $this->assertFalse(slide_import::is_deck_filename('sub\\deck.pptx'));
        $this->assertFalse(slide_import::is_deck_filename(''));
    }

    /**
     * Targets read back from a folder are cleaned, and unusable ones say why.
     */
    public function test_clean_target(): void {
        $this->resetAfterTest();
        $this->require_importer('book');

        $target = slide_import::clean_target([
            'courseid' => '7',
            'name' => ' <b>Week 1</b> ',
            'type' => 'BOOK',
            'section' => '-3',
            'mode' => 'X',
        ]);
        $this->assertSame(7, $target['courseid']);
        $this->assertSame('Week 1', $target['name']);
        $this->assertSame('book', $target['type']);
        $this->assertSame(0, $target['section']);
        $this->assertSame('editable', $target['mode']);
        $this->assertSame('', $target['error']);

        $this->assertSame('slideimport_notarget', slide_import::clean_target(['name' => 'N', 'type' => 'book'])['error']);
        $this->assertSame('slideimport_notarget', slide_import::clean_target(['courseid' => 7, 'type' => 'book'])['error']);
        $this->assertSame('slideimport_notarget', slide_import::clean_target(['courseid' => ['x'], 'name' => 'N'])['error']);
        $this->assertSame('slideimport_badtype', slide_import::clean_target(['courseid' => 7, 'name' => 'N'])['error']);
        $bad = slide_import::clean_target(['courseid' => 7, 'name' => 'N', 'type' => 'quiz']);
        $this->assertSame('slideimport_badtype', $bad['error']);
    }

    /**
     * A queued deck is imported into a new activity, or added to one that has the same name.
     */
    public function test_queued_deck_is_imported(): void {
        global $DB;
        $this->require_importer('lesson');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'BIO101']);
        $dir = $this->enable();
        $target = ['courseid' => $course->id, 'name' => 'Week 1', 'type' => 'lesson', 'section' => 1];
        $this->drop_deck($dir, 'One.pptx');
        $this->drop_deck($dir, 'Two.pptx');
        $this->assertTrue(slide_import::queue_deck($dir, 'One.pptx', $target));
        $this->assertTrue(slide_import::queue_deck($dir, 'Two.pptx', $target));

        $this->expectOutputRegex('/created 9 lesson page.*created 9 lesson page/s');
        $this->runAdhocTasks(task\import_slide_file::class);

        $lesson = $DB->get_record('lesson', ['course' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Week 1', $lesson->name);
        $this->assertEquals(18, $DB->count_records('lesson_pages', ['lessonid' => $lesson->id]));
        $this->assertSame([], glob($dir . '/processing/*'));
        $this->assertCount(2, slide_import::list_files($dir . '/processed'));
        $results = glob($dir . '/processed/*.results.csv');
        $this->assertCount(2, $results);
        $this->assertStringContainsString(',BIO101,"Week 1",lesson,ok,9,', file_get_contents($results[0]));
    }

    /**
     * A deck with no usable target, or whose course has gone, goes to "failed" with the reason.
     */
    public function test_unplaceable_deck_goes_to_failed(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $dir = $this->enable();
        mkdir($dir . '/processing');
        $this->drop_deck($dir . '/processing', '20260101-000000-No target.pptx');

        $outcome = slide_import::import_queued_file($dir . '/processing/20260101-000000-No target.pptx');
        $this->assertStringContainsString('no readable target file', $outcome['error']);
        $this->assertSame($dir . '/failed/20260101-000000-No target.pptx', $outcome['movedto']);
        $this->assertSame('error', $this->results($dir . '/failed')[0]['status']);
        unlink(glob($dir . '/failed/*.results.csv')[0]);

        $this->drop_deck($dir, 'Gone.pptx');
        slide_import::queue_deck($dir, 'Gone.pptx', ['courseid' => 999999, 'name' => 'Gone', 'type' => 'book']);
        $this->expectOutputRegex('/slide import of .*Gone\.pptx failed/');
        $this->runAdhocTasks(task\import_slide_file::class);

        $this->assertStringContainsString('no longer exists', $this->results($dir . '/failed')[0]['message']);
        $this->assertEquals(0, $DB->count_records('book'));
    }

    /**
     * Results files neutralise spreadsheet formulas taken from file names.
     */
    public function test_results_neutralise_formulas(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable();
        mkdir($dir . '/processing');
        $this->drop_deck($dir . '/processing', '=cmd.pptx');

        slide_import::import_queued_file($dir . '/processing/=cmd.pptx');

        $this->assertSame("'=cmd.pptx", $this->results($dir . '/failed')[0]['file']);
    }

    /**
     * A deck whose name is too long to take the target file suffix is left where it was.
     */
    public function test_overlong_name_is_not_queued(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable();
        $long = str_repeat('a', 228) . '.pptx';
        $this->drop_deck($dir, $long);

        $this->assertFalse(slide_import::queue_deck($dir, $long, ['courseid' => 2, 'name' => 'Long', 'type' => 'book']));

        $this->assertSame([$long], slide_import::list_files($dir));
        $this->assertSame([], glob($dir . '/processing/*'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * The import task refuses a path that is not in a watch folder's processing subfolder.
     */
    public function test_task_refuses_paths_outside_watch_folders(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->enable();
        $elsewhere = make_request_directory();
        mkdir($elsewhere . '/processing');
        $path = $elsewhere . '/processing/Deck.pptx';
        $this->drop_deck($elsewhere . '/processing', 'Deck.pptx');
        file_put_contents(
            $path . slide_import::TARGET_SUFFIX,
            json_encode(['courseid' => $course->id, 'name' => 'Week 1', 'type' => 'book'])
        );

        $task = new task\import_slide_file();
        $task->set_custom_data(['filepath' => $path]);
        $this->expectOutputRegex('/is not in a watch folder/');
        $task->execute();

        $this->assertFileExists($path);
        $this->assertEquals(0, $DB->count_records('book'));
    }
}
