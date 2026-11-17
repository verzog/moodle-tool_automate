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

/**
 * Watch folders: the server folders that "Find files that..." rules read from.
 *
 * A site administrator sets one base folder. A rule can only name a subfolder directly inside
 * it, never a path, so someone who may manage rules cannot point Automate at an arbitrary part
 * of the server. Everything found in those folders is untrusted input: folder and file names
 * must be bare names, and links are ignored.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watch_folder {
    /** ID number prefix of the holding course a rule creates; the rule id follows. */
    public const HOLDING_IDNUMBER = 'tool_automate_holding_';

    /**
     * Whether rules may read watch folders at all.
     *
     * Off by default. Also needs an importer plugin and a base folder, because the only things
     * a file rule can do today are slide imports.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('tool_automate', 'allow_watch_folders')
            && slide_import::is_available()
            && self::get_base_dir() !== '';
    }

    /**
     * The configured base folder, without a trailing separator.
     *
     * @return string Empty when not configured.
     */
    public static function get_base_dir(): string {
        $dir = trim((string) get_config('tool_automate', 'watch_base_dir'));
        return $dir === '' ? '' : rtrim($dir, '/\\');
    }

    /**
     * Is this a usable watch folder name: a bare, visible name that is not one of the
     * subfolders Automate files decks into?
     *
     * @param string $name
     * @return bool
     */
    public static function is_folder_name(string $name): bool {
        if ($name === '' || $name[0] === '.' || $name !== basename($name) || strpbrk($name, "/\\\0") !== false) {
            return false;
        }
        if ($name !== clean_param($name, PARAM_FILE)) {
            return false;
        }
        $reserved = [slide_import::DIR_PROCESSING, slide_import::DIR_PROCESSED, slide_import::DIR_FAILED];
        return !in_array(strtolower($name), $reserved, true);
    }

    /**
     * The path of a watch folder, if it exists as a real folder inside the base folder.
     *
     * @param string $folder The folder's name.
     * @return string|null Null when the name is not usable or there is no such folder.
     */
    public static function folder_path(string $folder): ?string {
        $base = self::get_base_dir();
        if ($base === '' || !self::is_folder_name($folder)) {
            return null;
        }
        $path = $base . DIRECTORY_SEPARATOR . $folder;
        return (is_dir($path) && !is_link($path)) ? $path : null;
    }

    /**
     * List the watch folders that exist now.
     *
     * @return string[] Sorted folder names.
     */
    public static function list_folders(): array {
        $base = self::get_base_dir();
        if ($base === '' || !is_dir($base) || !is_readable($base)) {
            return [];
        }
        $folders = [];
        foreach ((scandir($base) ?: []) as $entry) {
            if (self::folder_path($entry) !== null) {
                $folders[] = $entry;
            }
        }
        sort($folders, SORT_STRING);
        return $folders;
    }

    /**
     * List the files waiting in every watch folder, as the records a file rule runs against.
     *
     * Every waiting file is listed, so a rule's conditions see them all. The limit on how many
     * are queued per run is applied afterwards, by the import actions, to the files that match.
     *
     * @return \stdClass[] Each has id (a counter starting at 1, only meaningful within this
     *                     list), folder, name and path.
     */
    public static function list_files(): array {
        $files = [];
        foreach (self::list_folders() as $folder) {
            $dir = (string) self::folder_path($folder);
            foreach (slide_import::list_files($dir) as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                $files[] = (object) ['id' => count($files) + 1, 'folder' => $folder, 'name' => $name, 'path' => $path];
            }
        }
        return $files;
    }

    /**
     * Queue an import task for decks left in a watch folder's processing subfolder with no task
     * to import them, for example after a crash between moving a deck and queueing it.
     *
     * @return int How many decks were queued.
     */
    public static function requeue_orphans(): int {
        $queued = 0;
        foreach (self::list_folders() as $folder) {
            $queued += slide_import::requeue_orphans((string) self::folder_path($folder));
        }
        return $queued;
    }

    /**
     * Find the holding course a rule created earlier.
     *
     * @param int $ruleid
     * @return \stdClass|null The course record.
     */
    public static function find_holding_course(int $ruleid): ?\stdClass {
        global $DB;
        $courses = $DB->get_records('course', ['idnumber' => self::HOLDING_IDNUMBER . $ruleid], 'id', '*', 0, 1);
        $course = reset($courses);
        return $course ?: null;
    }

    /**
     * Create a rule's holding course: hidden, and labelled as temporary.
     *
     * One per rule. Automate never deletes it; a person moves the content out and removes it.
     *
     * @param \stdClass $rule The rule record (id and name are used).
     * @param int $categoryid The category to create it in.
     * @return \stdClass The new course record.
     */
    public static function create_holding_course(\stdClass $rule, int $categoryid): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $shortname = 'automate-holding-' . (int) $rule->id;
        $suffix = 1;
        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $suffix++;
            $shortname = 'automate-holding-' . (int) $rule->id . '-' . $suffix;
        }
        $name = \core_text::substr(get_string('holdingcourse_name', 'tool_automate', $rule->name), 0, 254);
        return create_course((object) [
            'category' => $categoryid,
            'fullname' => $name,
            'shortname' => $shortname,
            'idnumber' => self::HOLDING_IDNUMBER . (int) $rule->id,
            'summary' => get_string('holdingcourse_summary', 'tool_automate'),
            'summaryformat' => FORMAT_PLAIN,
            'visible' => 0,
        ]);
    }
}
