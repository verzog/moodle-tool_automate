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

/**
 * Action: permanently delete the matched courses. Pattern matching is
 * expressed through the rule's conditions (course_name_matches,
 * course_idnumber_matches, course_no_activity_days, etc); this action
 * just deletes whatever the rule picked.
 *
 * Destructive and irreversible. Refuses to run unless the admin has
 * typed the exact confirmation phrase into the action's config, and
 * never touches the site course.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_delete extends action_base {
    /** Confirmation phrase that has to be typed before this action will run. */
    public const CONFIRM_PHRASE = 'DELETE';

    /**
     * Subject discriminator.
     *
     * @return string
     */
    public static function get_subject(): string {
        return 'course';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_course_delete', 'tool_automate');
    }

    /**
     * Delete the matched course.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $name = format_string($subject->fullname ?? ('#' . $subject->id));

        // The site front page (SITEID, typically 1) is not a deletable
        // course. Refuse silently rather than letting delete_course
        // raise an exception that aborts the whole rule run.
        if ((int) $subject->id === (int) SITEID) {
            return get_string('coursedeleteskippedsite', 'tool_automate', $name);
        }

        // Required safety: the admin has to type the confirmation
        // phrase exactly into the action config. Without it the action
        // is a no-op on every matched course - we'd rather skip than
        // delete by accident if someone wires this up without reading
        // the warning.
        $confirm = trim((string) ($this->config['confirm'] ?? ''));
        if ($confirm !== self::CONFIRM_PHRASE) {
            return get_string('coursedeleteunconfirmed', 'tool_automate', $name);
        }

        if ($dryrun) {
            return get_string('coursewoulddelete', 'tool_automate', $name);
        }

        delete_course((int) $subject->id, false);
        return get_string('coursedeleted', 'tool_automate', $name);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        // Loud warning above the confirmation field so the admin sees
        // what they're agreeing to before typing the phrase.
        $mform->addElement(
            'static',
            'config_deletewarning',
            '',
            \html_writer::div(
                get_string('coursedeletewarning', 'tool_automate'),
                'alert alert-danger'
            )
        );

        $a = (object) ['phrase' => self::CONFIRM_PHRASE];
        $mform->addElement(
            'text',
            'config_confirm',
            get_string('coursedeleteconfirmlabel', 'tool_automate', $a),
            ['size' => 20]
        );
        $mform->setType('config_confirm', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('config_confirm', 'coursedeleteconfirmlabel', 'tool_automate');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['confirm' => trim((string) ($formdata->config_confirm ?? ''))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_confirm' => (string) ($config['confirm'] ?? '')];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $confirmed = trim((string) ($config['confirm'] ?? '')) === self::CONFIRM_PHRASE;
        return get_string(
            $confirmed ? 'act_course_delete_desc' : 'act_course_delete_desc_unconfirmed',
            'tool_automate'
        );
    }
}
