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
 * Tests for the bulk Canvas import helper.
 *
 * These cover the listing, path-safety, URL-parsing and mode logic that both
 * the web page and the CLI rely on - in particular that only bare .imscc/.zip
 * basenames inside the configured directory ever resolve, and that only
 * well-formed http(s) URLs survive parsing. The queueing methods delegate to
 * tool_canvasuplifter's launcher, which is not installed in this plugin's CI,
 * so they are exercised there rather than here.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(canvas_repository::class)]
final class canvas_repository_test extends \advanced_testcase {
    /** Class name of the adhoc import task queue_* enqueues. */
    private const IMPORT_TASK = '\\tool_automate\\task\\import_canvas';

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
     * The feature is off until the site setting is turned on.
     */
    public function test_is_enabled_reflects_setting(): void {
        $this->resetAfterTest();
        $this->assertFalse(canvas_repository::is_enabled());
        set_config('allow_bulk_canvas', 1, 'tool_automate');
        $this->assertTrue(canvas_repository::is_enabled());
    }

    /**
     * Only .imscc/.zip packages are listed, by basename, sorted, ignoring others.
     */
    public function test_list_packages_returns_sorted_package_basenames(): void {
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['b.imscc', 'a.imscc', 'notes.txt', 'course.zip', 'C.IMSCC']);

        $files = canvas_repository::list_packages($dir);

        $this->assertSame(['C.IMSCC', 'a.imscc', 'b.imscc', 'course.zip'], $files);
    }

    /**
     * A missing or empty directory lists nothing rather than erroring.
     */
    public function test_list_packages_missing_directory_is_empty(): void {
        $this->resetAfterTest();
        $this->assertSame([], canvas_repository::list_packages('/no/such/dir/here'));
        $this->assertSame([], canvas_repository::list_packages(''));
    }

    /**
     * is_package_filename accepts bare package names and rejects paths/others.
     */
    public function test_is_package_filename(): void {
        $this->assertTrue(canvas_repository::is_package_filename('course.imscc'));
        $this->assertTrue(canvas_repository::is_package_filename('Course.IMSCC'));
        $this->assertTrue(canvas_repository::is_package_filename('export.zip'));
        $this->assertFalse(canvas_repository::is_package_filename('course.mbz'));
        $this->assertFalse(canvas_repository::is_package_filename('../course.imscc'));
        $this->assertFalse(canvas_repository::is_package_filename('sub/course.imscc'));
        $this->assertFalse(canvas_repository::is_package_filename(''));
        $this->assertFalse(canvas_repository::is_package_filename('.imscc'));
    }

    /**
     * resolve() returns the real path for a genuine in-directory package and
     * rejects traversal, wrong-extension and missing files.
     */
    public function test_resolve_accepts_real_and_rejects_unsafe(): void {
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['good.imscc', 'notes.txt']);

        $this->assertSame(realpath($dir . '/good.imscc'), canvas_repository::resolve('good.imscc', $dir));
        $this->assertNull(canvas_repository::resolve('../good.imscc', $dir));
        $this->assertNull(canvas_repository::resolve('sub/good.imscc', $dir));
        $this->assertNull(canvas_repository::resolve('notes.txt', $dir));
        $this->assertNull(canvas_repository::resolve('missing.imscc', $dir));
        $this->assertNull(canvas_repository::resolve('good.imscc', ''));
    }

    /**
     * The picker token survives awkward filenames and maps back to the basename.
     */
    public function test_token_round_trips_awkward_filenames(): void {
        $this->resetAfterTest();
        $name = 'Course, Spring  2026.imscc';
        $dir = $this->make_dir_with([$name, 'plain.imscc']);

        $token = canvas_repository::token($name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        $this->assertSame($name, canvas_repository::basename_for_token($token, $dir));
        $this->assertNull(canvas_repository::basename_for_token(sha1('nope.imscc'), $dir));
    }

    /**
     * parse_urls keeps only well-formed http(s) URLs, one per line, de-duplicated,
     * and drops blanks, comments and junk.
     */
    public function test_parse_urls_filters_and_dedupes(): void {
        $text = "https://a.example.edu/c1.imscc\n"
            . "  http://b.example.edu/c2.imscc  \n"
            . "\n"
            . "# a comment\n"
            . "not-a-url\n"
            . "ftp://c.example.edu/c3.imscc\n"
            . "https://a.example.edu/c1.imscc\n"; // Duplicate of the first.

        $urls = canvas_repository::parse_urls($text);

        $this->assertSame([
            'https://a.example.edu/c1.imscc',
            'http://b.example.edu/c2.imscc',
        ], $urls);
    }

    /**
     * An empty or all-junk block yields no URLs.
     */
    public function test_parse_urls_empty(): void {
        $this->assertSame([], canvas_repository::parse_urls(''));
        $this->assertSame([], canvas_repository::parse_urls("# only a comment\n\n   \n"));
    }

    /**
     * normalise_mode only ever returns one of the two supported modes, defaulting
     * to the safe read-only analyse for anything unrecognised.
     */
    public function test_normalise_mode(): void {
        $this->assertSame(canvas_repository::MODE_BUILD, canvas_repository::normalise_mode('build'));
        $this->assertSame(canvas_repository::MODE_ANALYSE, canvas_repository::normalise_mode('analyse'));
        $this->assertSame(canvas_repository::MODE_ANALYSE, canvas_repository::normalise_mode('wat'));
        $this->assertSame(canvas_repository::MODE_ANALYSE, canvas_repository::normalise_mode(''));
    }

    /**
     * Without tool_canvasuplifter installed, job listing reports unavailable and
     * returns nothing rather than fataling on a missing class.
     */
    public function test_list_jobs_degrades_without_canvasuplifter(): void {
        $this->resetAfterTest();
        if (canvas_repository::is_available()) {
            $this->markTestSkipped('tool_canvasuplifter is installed in this environment');
        }
        $this->assertFalse(canvas_repository::jobs_listable());
        $this->assertSame([], canvas_repository::list_jobs(1));
    }

    /**
     * queue_file enqueues a tool_automate import_canvas adhoc task carrying the
     * file source and chosen options, acting as the queueing user. The task -
     * not the queue call - creates the Canvas Uplifter job, so the kill-switch
     * can still be re-checked at run time.
     */
    public function test_queue_file_enqueues_import_task(): void {
        global $DB;
        $this->resetAfterTest();
        $dir = $this->make_dir_with(['good.imscc']);
        $path = realpath($dir . '/good.imscc');
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();

        canvas_repository::queue_file(
            $path,
            (int) $category->id,
            (int) $user->id,
            canvas_repository::MODE_BUILD,
            true,
            'book'
        );

        $record = $DB->get_record('task_adhoc', ['classname' => self::IMPORT_TASK], '*', MUST_EXIST);
        $custom = (object) json_decode($record->customdata);
        $this->assertSame(canvas_repository::SOURCE_FILE, $custom->sourcetype);
        $this->assertSame($path, $custom->source);
        $this->assertSame((int) $category->id, (int) $custom->categoryid);
        $this->assertSame((int) $user->id, (int) $custom->userid);
        $this->assertSame(canvas_repository::MODE_BUILD, $custom->mode);
        $this->assertEquals(1, (int) $custom->quizfrombank);
        $this->assertSame('book', $custom->pagegrouping);
        $this->assertEquals((int) $user->id, (int) $record->userid);
    }

    /**
     * queue_url enqueues an import_canvas task carrying the URL source, and an
     * unrecognised mode is coerced to the safe read-only analyse.
     */
    public function test_queue_url_enqueues_import_task(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $category = $this->getDataGenerator()->create_category();

        canvas_repository::queue_url('https://x.edu/c.imscc', (int) $category->id, (int) $user->id, 'wat');

        $record = $DB->get_record('task_adhoc', ['classname' => self::IMPORT_TASK], '*', MUST_EXIST);
        $custom = (object) json_decode($record->customdata);
        $this->assertSame(canvas_repository::SOURCE_URL, $custom->sourcetype);
        $this->assertSame('https://x.edu/c.imscc', $custom->source);
        $this->assertSame(canvas_repository::MODE_ANALYSE, $custom->mode);
    }
}
