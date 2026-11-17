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

use tool_automate\slide_import;
use tool_automate\watch_folder;

/**
 * Shared behaviour of the actions that import a slide deck from a watch folder.
 *
 * Nothing is imported while the rule runs. The deck is moved into its folder's "processing"
 * subfolder and an adhoc task is queued for it, so a
 * rule run from the browser returns straight away and decks are imported one at a time.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class file_import_base extends action_base {
    /** @var int Decks this action has queued during the current rule run. */
    protected int $queued = 0;

    /** @var bool[] Paths of the decks an import action has already taken during the current rule run. */
    protected static array $taken = [];

    /**
     * Start a new rule run: no deck has been taken yet.
     *
     * The engine gives every action its rule before it runs any of them, so this clears the
     * record left by an earlier run in the same request (a preview followed by a real run).
     *
     * @param \stdClass $rule
     */
    public function set_rule(\stdClass $rule): void {
        parent::set_rule($rule);
        self::$taken = [];
    }

    /**
     * Subject discriminator.
     *
     * @return string
     */
    public static function get_subject(): string {
        return 'file';
    }

    /**
     * The course the deck goes into, as {@see self::execute()} should describe and use it.
     *
     * @param bool $dryrun When true, nothing may be created.
     * @return array Keys: error (string, empty when usable), courseid (int; 0 on a dry run
     *               that would create the course) and label (string, for messages).
     */
    abstract protected function resolve_destination(bool $dryrun): array;

    /**
     * Queue the deck for import, or say what would happen.
     *
     * @param \stdClass $subject A file record from watch_folder::list_files().
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        $filename = (string) ($subject->name ?? '');
        $folderpath = watch_folder::folder_path((string) ($subject->folder ?? ''));
        if (!watch_folder::is_enabled() || $folderpath === null || !slide_import::is_deck_filename($filename)) {
            return get_string('fileimport_unavailable', 'tool_automate');
        }
        $type = (string) ($this->config['type'] ?? '');
        $api = slide_import::available_types()[$type] ?? null;
        if ($api === null) {
            return get_string('slideimport_badtype', 'tool_automate');
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, $api::extensions(), true)) {
            return get_string('slideimport_badextension', 'tool_automate', $extension);
        }
        $name = \core_text::substr(trim(clean_param(pathinfo($filename, PATHINFO_FILENAME), PARAM_TEXT)), 0, 255);
        if ($name === '') {
            return get_string('fileimport_noname', 'tool_automate');
        }
        // A deck is moved out of its folder when it is queued, so it can only go to one place.
        // A second import action in the same rule says so, in preview as well as for real.
        $path = (string) ($subject->path ?? '');
        if (isset(self::$taken[$path])) {
            return get_string('fileimport_taken', 'tool_automate');
        }
        if ($this->queued >= slide_import::BATCH) {
            return get_string('fileimport_nextrun', 'tool_automate', slide_import::BATCH);
        }

        $destination = $this->resolve_destination($dryrun);
        if ($destination['error'] !== '') {
            return $destination['error'];
        }
        $a = (object) [
            'file' => $filename,
            'name' => $name,
            'type' => get_string('slidetype_' . $type, 'tool_automate'),
            'course' => $destination['label'],
        ];
        if ($dryrun) {
            $this->queued++;
            self::$taken[$path] = true;
            return get_string('fileimport_would', 'tool_automate', $a);
        }

        $target = [
            'courseid' => (int) $destination['courseid'],
            'name' => $name,
            'type' => $type,
            'section' => max(0, (int) ($this->config['section'] ?? 0)),
            'mode' => (string) ($this->config['mode'] ?? 'editable'),
        ];
        if (!slide_import::queue_deck($folderpath, $filename, $target)) {
            return get_string('slideimport_rowmovefailed', 'tool_automate');
        }
        $this->queued++;
        self::$taken[$path] = true;
        return get_string('fileimport_queued', 'tool_automate', $a);
    }

    /**
     * The user whose permissions are checked when the rule runs: whoever configured the
     * action, or failing that whoever last saved the rule.
     *
     * Checked at run time, so removing someone's permission also stops rules they set up.
     *
     * @return \stdClass|null Null when there is no such user, or they are deleted or suspended.
     */
    protected function author(): ?\stdClass {
        $authorid = (int) ($this->config['authorid'] ?? 0);
        if ($authorid <= 0) {
            $authorid = (int) ($this->rule->usermodified ?? 0);
        }
        $author = $authorid > 0 ? \core_user::get_user($authorid) : false;
        if (!$author || !empty($author->deleted) || !empty($author->suspended)) {
            return null;
        }
        return $author;
    }

    /**
     * Add the fields every slide import action shares: activity type, section and mode.
     *
     * @param \MoodleQuickForm $mform
     */
    protected static function add_common_form_elements(\MoodleQuickForm $mform): void {
        $types = [];
        foreach (array_keys(slide_import::available_types()) as $type) {
            $types[$type] = get_string('slidetype_' . $type, 'tool_automate');
        }
        $mform->addElement('select', 'config_type', get_string('setting_slide_default_type', 'tool_automate'), $types);

        $mform->addElement('text', 'config_section', get_string('fileimport_section', 'tool_automate'), ['size' => 4]);
        $mform->setType('config_section', PARAM_INT);
        $mform->setDefault('config_section', 0);
        $mform->addHelpButton('config_section', 'fileimport_section', 'tool_automate');

        $mform->addElement('select', 'config_mode', get_string('setting_slide_import_mode', 'tool_automate'), [
            'editable' => get_string('slidemode_editable', 'tool_automate'),
            'images' => get_string('slidemode_images', 'tool_automate'),
        ]);
        $mform->addHelpButton('config_mode', 'fileimport_mode', 'tool_automate');
    }

    /**
     * Pull the shared fields out of submitted form data, recording who configured the action.
     *
     * @param \stdClass $formdata
     * @return array
     */
    protected static function extract_common_config(\stdClass $formdata): array {
        global $USER;
        $type = (string) ($formdata->config_type ?? '');
        return [
            'type' => isset(slide_import::IMPORTERS[$type]) ? $type : '',
            'section' => max(0, (int) ($formdata->config_section ?? 0)),
            'mode' => ($formdata->config_mode ?? '') === 'images' ? 'images' : 'editable',
            'authorid' => (int) $USER->id,
        ];
    }

    /**
     * Map the shared stored config back to form defaults.
     *
     * @param array $config
     * @return array
     */
    protected static function common_form_defaults(array $config): array {
        return [
            'config_type' => (string) ($config['type'] ?? ''),
            'config_section' => (int) ($config['section'] ?? 0),
            'config_mode' => (string) ($config['mode'] ?? 'editable'),
        ];
    }

    /**
     * The activity type's display name, for summaries.
     *
     * @param array $config
     * @return string
     */
    protected static function type_label(array $config): string {
        $type = (string) ($config['type'] ?? '');
        return isset(slide_import::IMPORTERS[$type]) ? get_string('slidetype_' . $type, 'tool_automate') : '?';
    }
}
