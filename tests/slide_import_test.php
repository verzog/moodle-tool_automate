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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the slide folder import (soft integration with the PowerPoint import plugins).
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(slide_import::class)]
#[CoversClass(task\queue_slide_imports::class)]
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
     * Switch the folder import on for a new, empty folder.
     *
     * @param string $mapping One of the slide_import::MAPPING_* constants.
     * @return string The folder.
     */
    private function enable(string $mapping = slide_import::MAPPING_FILENAME): string {
        $dir = make_request_directory();
        set_config('slide_source_dir', $dir, 'tool_automate');
        set_config('slide_mapping', $mapping, 'tool_automate');
        set_config('allow_slide_import', 1, 'tool_automate');
        return $dir;
    }

    /**
     * Run the scheduled task, then every import task it queued.
     */
    private function run_import(): void {
        (new task\queue_slide_imports())->execute();
        $this->runAdhocTasks(task\import_slide_file::class);
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
        $this->assertTrue(slide_import::is_deck_filename('BIO101__Week 1.pptx'));
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
     * The course and activity name are split on the first double underscore.
     *
     * @param string $filename
     * @param array|null $expected
     */
    #[DataProvider('parse_filename_provider')]
    public function test_parse_filename(string $filename, ?array $expected): void {
        $this->assertSame($expected, slide_import::parse_filename($filename));
    }

    /**
     * Data provider for {@see self::test_parse_filename()}.
     *
     * @return array[]
     */
    public static function parse_filename_provider(): array {
        return [
            'plain' => ['BIO101__Week 1 slides.pptx', ['course' => 'BIO101', 'name' => 'Week 1 slides']],
            'single underscores kept' => ['BIO_101__Week_1.pptx', ['course' => 'BIO_101', 'name' => 'Week_1']],
            'later separators belong to the name' => ['A__B__C.pdf', ['course' => 'A', 'name' => 'B__C']],
            'spaces trimmed' => [' A __ B .pptx', ['course' => 'A', 'name' => 'B']],
            'no separator' => ['Week 1 slides.pptx', null],
            'no course' => ['__Week 1.pptx', null],
            'no name' => ['BIO101__.pptx', null],
        ];
    }

    /**
     * A course is found by ID number first, then short name; the site course never matches.
     */
    public function test_find_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $byid = $generator->create_course(['idnumber' => 'BIO101', 'shortname' => 'Biology']);
        $byshort = $generator->create_course(['shortname' => 'CHEM']);
        // This course's short name is another course's ID number: the ID number wins.
        $generator->create_course(['shortname' => 'BIO101']);

        $this->assertEquals($byid->id, slide_import::find_course('BIO101')->id);
        $this->assertEquals($byshort->id, slide_import::find_course('CHEM')->id);
        $this->assertNull(slide_import::find_course('NOPE'));
        $this->assertNull(slide_import::find_course(''));
        $this->assertNull(slide_import::find_course(get_site()->shortname));
    }

    /**
     * Targets are cleaned whatever their source, and unusable ones say why.
     */
    public function test_clean_target(): void {
        $this->resetAfterTest();
        $this->require_importer('book');

        $target = slide_import::clean_target(['course' => ' C1 ', 'name' => '<b>Week 1</b>', 'section' => '-3', 'mode' => 'X']);
        $this->assertSame('C1', $target['course']);
        $this->assertSame('Week 1', $target['name']);
        $this->assertSame(0, $target['section']);
        $this->assertSame('editable', $target['mode']);
        $this->assertSame('', $target['error']);
        $this->assertArrayHasKey($target['type'], slide_import::available_types());

        $this->assertSame('slideimport_notarget', slide_import::clean_target(['course' => 'C1'])['error']);
        $this->assertSame('slideimport_notarget', slide_import::clean_target(['course' => ['x'], 'name' => 'N'])['error']);
        $bad = slide_import::clean_target(['course' => 'C1', 'name' => 'N', 'type' => 'quiz']);
        $this->assertSame('slideimport_badtype', $bad['error']);
    }

    /**
     * A manifest needs its three columns; extra columns and a byte order mark are tolerated.
     */
    public function test_read_manifest(): void {
        $dir = make_request_directory();
        file_put_contents(
            $dir . '/ok.csv',
            "\xEF\xBB\xBFFile, Course ,name,notes,TYPE\na.pptx,C1,Week 1,ignored,lesson\n\n\"b, final.pptx\",C2,\"Week 2\"\n"
        );
        $parsed = slide_import::read_manifest($dir . '/ok.csv');
        $this->assertSame('', $parsed['error']);
        $this->assertCount(2, $parsed['rows']);
        $this->assertSame(
            [
                'line' => 2,
                'file' => 'a.pptx',
                'course' => 'C1',
                'name' => 'Week 1',
                'type' => 'lesson',
                'section' => '',
                'mode' => '',
            ],
            $parsed['rows'][0]
        );
        $this->assertSame('b, final.pptx', $parsed['rows'][1]['file']);
        $this->assertSame(4, $parsed['rows'][1]['line']);

        file_put_contents($dir . '/bad.csv', "file,name\na.pptx,Week 1\n");
        $parsed = slide_import::read_manifest($dir . '/bad.csv');
        $this->assertSame([], $parsed['rows']);
        $this->assertNotSame('', $parsed['error']);
    }

    /**
     * A full run, file name mapping: the deck becomes a new activity in the named course.
     *
     * @param string $type 'book' or 'lesson'.
     * @param string $table The table holding one row per imported slide.
     * @param string $field The column in that table pointing at the activity.
     */
    #[DataProvider('type_provider')]
    public function test_filename_import_end_to_end(string $type, string $table, string $field): void {
        global $DB;
        $this->require_importer($type);
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'BIO101']);
        $dir = make_request_directory();
        set_config('slide_source_dir', $dir, 'tool_automate');
        set_config('slide_default_type', $type, 'tool_automate');
        $this->drop_deck($dir, 'BIO101__Week 1 slides.pptx');

        // Switched off: nothing happens.
        (new task\queue_slide_imports())->execute();
        $this->assertFileExists($dir . '/BIO101__Week 1 slides.pptx');

        set_config('allow_slide_import', 1, 'tool_automate');
        $this->expectOutputRegex('/queued 1 slide deck.*created 9 ' . $type . ' page/s');
        $this->run_import();

        $this->assertFileDoesNotExist($dir . '/BIO101__Week 1 slides.pptx');
        $this->assertSame([], slide_import::list_files($dir . '/processing'));
        $this->assertSame([], glob($dir . '/processing/*'));
        $this->assertCount(1, slide_import::list_files($dir . '/processed'));
        $result = $this->results($dir . '/processed')[0];
        $this->assertSame('ok', $result['status']);
        $this->assertSame('9', $result['created']);
        $this->assertSame('Week 1 slides', $result['activity']);

        $instances = $DB->get_records($type, ['course' => $course->id]);
        $this->assertCount(1, $instances);
        $instance = reset($instances);
        $this->assertSame('Week 1 slides', $instance->name);
        $this->assertEquals(9, $DB->count_records($table, [$field => $instance->id]));
    }

    /**
     * Data provider: each activity type with the table and column its slides are stored in.
     *
     * @return array[]
     */
    public static function type_provider(): array {
        return [
            'book' => ['book', 'book_chapters', 'bookid'],
            'lesson' => ['lesson', 'lesson_pages', 'lessonid'],
        ];
    }

    /**
     * A second deck for the same activity is added to it rather than creating a duplicate.
     */
    public function test_existing_activity_is_reused(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CHEM']);
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Slides']);
        set_config('slide_default_type', 'book', 'tool_automate');
        $dir = $this->enable();
        $this->drop_deck($dir, 'CHEM__Slides.pptx');

        $this->expectOutputRegex('/created 9 book page/');
        $this->run_import();

        $this->assertEquals(1, $DB->count_records('book', ['course' => $course->id]));
        $this->assertEquals(9, $DB->count_records('book_chapters', ['bookid' => $book->id]));
    }

    /**
     * Decks that cannot be placed go to "failed" with the reason, and nothing is created.
     *
     * @param string $filename The dropped deck's name.
     * @param string $message Part of the expected message.
     */
    #[DataProvider('failed_filename_provider')]
    public function test_unplaceable_deck_goes_to_failed(string $filename, string $message): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $books = $DB->count_records('book');
        $dir = $this->enable();
        $this->drop_deck($dir, $filename);

        $this->expectOutputRegex('/slide import of .* failed/');
        $this->run_import();

        $this->assertSame([], glob($dir . '/processing/*'));
        $this->assertCount(1, slide_import::list_files($dir . '/failed'));
        $result = $this->results($dir . '/failed')[0];
        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString($message, $result['message']);
        $this->assertEquals($books, $DB->count_records('book'));
    }

    /**
     * Data provider for {@see self::test_unplaceable_deck_goes_to_failed()}.
     *
     * @return array[]
     */
    public static function failed_filename_provider(): array {
        return [
            'name does not follow the pattern' => ['Week 1 slides.pptx', 'does not say which course'],
            'unknown course' => ['NOPE__Week 1.pptx', 'No course has'],
            'formula-like course' => ['=cmd__Week 1.pptx', 'No course has'],
        ];
    }

    /**
     * Results files neutralise spreadsheet formulas taken from file names.
     */
    public function test_results_neutralise_formulas(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $dir = $this->enable();
        $this->drop_deck($dir, '=cmd__Week 1.pptx');

        $this->expectOutputRegex('/failed/');
        $this->run_import();

        $this->assertSame("'=cmd", $this->results($dir . '/failed')[0]['course']);
    }

    /**
     * A full run, manifest mapping: listed decks are imported, the rest are left alone.
     */
    public function test_manifest_import_end_to_end(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['idnumber' => 'BIO101']);
        set_config('slide_default_type', 'book', 'tool_automate');
        $dir = $this->enable(slide_import::MAPPING_MANIFEST);
        $this->drop_deck($dir, 'week1.pptx');
        $this->drop_deck($dir, 'unlisted.pptx');
        file_put_contents(
            $dir . '/batch.csv',
            "file,course,name,section\n"
            . "week1.pptx,BIO101,Week 1,2\n"
            . "week1.pptx,BIO101,Week 1 again,2\n"
            . "missing.pptx,BIO101,Week 2,2\n"
            . "../week1.pptx,BIO101,Week 3,2\n"
        );

        $this->expectOutputRegex('/queued 1 slide deck.*created 9 book page/s');
        $this->run_import();

        // The unlisted deck is untouched; the manifest is filed with what happened to each row.
        $this->assertSame(['unlisted.pptx'], slide_import::list_files($dir));
        $this->assertSame([], slide_import::list_files($dir, ['csv']));
        $this->assertCount(1, slide_import::list_files($dir . '/processed', ['pptx']));
        $manifests = glob($dir . '/processed/*batch.csv.results.csv');
        $this->assertCount(1, $manifests);
        $rows = array_map(fn($line) => str_getcsv($line, ',', '"', ''), file($manifests[0], FILE_IGNORE_NEW_LINES));
        $this->assertSame(['queued', 'error', 'error', 'error'], array_column(array_slice($rows, 1), 2));

        $book = $DB->get_record('book', ['course' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Week 1', $book->name);
        $cm = get_coursemodule_from_instance('book', $book->id, 0, true, MUST_EXIST);
        $this->assertEquals(2, $cm->sectionnum);
        $this->assertEquals(9, $DB->count_records('book_chapters', ['bookid' => $book->id]));
    }

    /**
     * A manifest without the required columns is filed under "failed" and queues nothing.
     */
    public function test_bad_manifest_goes_to_failed(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable(slide_import::MAPPING_MANIFEST);
        $this->drop_deck($dir, 'week1.pptx');
        file_put_contents($dir . '/batch.csv', "deck,where\nweek1.pptx,BIO101\n");

        (new task\queue_slide_imports())->execute();

        $this->assertSame(['week1.pptx'], slide_import::list_files($dir));
        $this->assertCount(1, glob($dir . '/failed/*batch.csv'));
        $this->assertCount(1, glob($dir . '/failed/*batch.csv.results.csv'));
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * A deck left in processing with no task to import it is queued again.
     */
    public function test_orphaned_processing_file_is_requeued(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable();
        mkdir($dir . '/processing');
        $this->drop_deck($dir . '/processing', '20260101-000000-BIO101__Week 1.pptx');

        $this->expectOutputRegex('/queued 1 slide deck/');
        (new task\queue_slide_imports())->execute();
        (new task\queue_slide_imports())->execute();

        $tasks = \core\task\manager::get_adhoc_tasks(task\import_slide_file::class);
        $this->assertCount(1, $tasks);
    }

    /**
     * With the manifest mapping, a deck whose target file is missing is never placed by its name.
     */
    public function test_manifest_orphan_without_target_goes_to_failed(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_course(['idnumber' => 'BIO101']);
        $dir = $this->enable(slide_import::MAPPING_MANIFEST);
        mkdir($dir . '/processing');
        $this->drop_deck($dir . '/processing', '20260101-000000-BIO101__Week 1.pptx');

        $this->expectOutputRegex('/slide import of .* failed/');
        $this->run_import();

        $this->assertCount(1, slide_import::list_files($dir . '/failed'));
        $this->assertEquals(0, $DB->count_records('book'));
    }

    /**
     * A deck whose name is too long to take the target file suffix is left alone; others still queue.
     */
    public function test_overlong_name_does_not_block_the_folder(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable();
        $long = 'BIO101__' . str_repeat('a', 220) . '.pptx';
        $this->drop_deck($dir, $long);
        $this->drop_deck($dir, 'BIO101__Week 1.pptx');

        $this->expectOutputRegex('/queued 1 slide deck.*could not move 1 slide import file/s');
        (new task\queue_slide_imports())->execute();

        $this->assertSame([$long], slide_import::list_files($dir));
        $this->assertCount(1, slide_import::list_files($dir . '/processing'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(task\import_slide_file::class));
    }

    /**
     * The import task refuses a path outside the configured folder's processing subfolder.
     */
    public function test_task_refuses_paths_outside_the_folder(): void {
        global $DB;
        $this->require_importer('book');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_course(['idnumber' => 'BIO101']);
        $this->enable();
        $elsewhere = make_request_directory();
        $this->drop_deck($elsewhere, 'BIO101__Week 1.pptx');
        file_put_contents(
            $elsewhere . '/BIO101__Week 1.pptx' . slide_import::TARGET_SUFFIX,
            json_encode(['course' => 'BIO101', 'name' => 'Week 1'])
        );

        $task = new task\import_slide_file();
        $task->set_custom_data(['filepath' => $elsewhere . '/BIO101__Week 1.pptx']);
        $this->expectOutputRegex('/is not in the slide import folder/');
        $task->execute();

        $this->assertFileExists($elsewhere . '/BIO101__Week 1.pptx');
        $this->assertEquals(0, $DB->count_records('book'));
    }

    /**
     * Switching the import off stops queued work, leaving the deck where it is.
     */
    public function test_switched_off_leaves_queued_deck_in_place(): void {
        $this->require_importer('book');
        $this->resetAfterTest();
        $dir = $this->enable();
        $this->drop_deck($dir, 'BIO101__Week 1.pptx');
        (new task\queue_slide_imports())->execute();
        set_config('allow_slide_import', 0, 'tool_automate');

        $this->expectOutputRegex('/slide import is switched off/');
        $this->runAdhocTasks(task\import_slide_file::class);

        $this->assertCount(1, slide_import::list_files($dir . '/processing'));
    }
}
