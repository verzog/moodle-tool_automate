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

use tool_automate\calendar_import;

/**
 * Action: add a group calendar event to the matching groups of a course.
 *
 * Needs local_grpcalendarimport (a soft dependency): the action only appears in the picker
 * when that plugin is installed, and does nothing if it is later removed. Events that already
 * exist (same name, group, start time) are always skipped, so a scheduled rule can run
 * repeatedly without creating copies.
 *
 * @package    tool_automate
 * @copyright  2026 Vernon Spain
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_create_group_event extends action_base {
    /** Default event length in seconds. */
    const DEFAULT_DURATION = HOURSECS;

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
        return get_string('act_course_create_group_event', 'tool_automate');
    }

    /**
     * Whether the current user may add this action to a rule.
     *
     * @return bool
     */
    public static function is_available_to_current_user(): bool {
        return calendar_import::is_available()
            && has_capability(calendar_import::CAPABILITY, \context_system::instance());
    }

    /**
     * Create the event in each matching group of the course.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        $coursename = format_string($subject->fullname);
        if (!calendar_import::is_available()) {
            return get_string('calendarevent_unavailable', 'tool_automate');
        }
        if (!$this->author_may_create()) {
            return get_string('calendarevent_notallowed', 'tool_automate');
        }

        $name = trim((string) ($this->config['name'] ?? ''));
        $timestart = (int) ($this->config['timestart'] ?? 0);
        if ($name === '' || $timestart <= 0) {
            return get_string('calendarevent_incomplete', 'tool_automate');
        }

        $groups = calendar_import::matching_groups((int) $subject->id, (string) ($this->config['grouppattern'] ?? ''));
        if (empty($groups)) {
            return get_string('calendarevent_nogroups', 'tool_automate', $coursename);
        }

        $importer = calendar_import::IMPORTER;
        $counts = ['created' => 0, 'skipped' => 0, 'errors' => 0];
        $firsterror = '';
        foreach ($groups as $group) {
            if ($importer::event_exists($name, (int) $subject->id, (int) $group->id, $timestart, 'group')) {
                $counts['skipped']++;
                continue;
            }
            if ($dryrun) {
                $counts['created']++;
                continue;
            }
            $result = $importer::create_event($this->build_row((int) $subject->id, (int) $group->id), true, 0);
            if ($result['status'] === 'ok') {
                $counts['created']++;
            } else if ($result['status'] === 'skip') {
                $counts['skipped']++;
            } else {
                $counts['errors']++;
                $firsterror = $firsterror ?: $result['message'];
            }
        }

        $a = (object) ($counts + ['course' => $coursename, 'error' => $firsterror]);
        if ($dryrun) {
            return get_string('calendarevent_wouldcreate', 'tool_automate', $a);
        }
        return get_string($counts['errors'] ? 'calendarevent_resulterrors' : 'calendarevent_result', 'tool_automate', $a);
    }

    /**
     * Build the importer row for one group.
     *
     * @param int $courseid Course id.
     * @param int $groupid Group id.
     * @return array Row keyed by {event} column names.
     */
    protected function build_row(int $courseid, int $groupid): array {
        return [
            'name' => trim((string) ($this->config['name'] ?? '')),
            'courseid' => $courseid,
            'groupid' => $groupid,
            'timestart' => (int) ($this->config['timestart'] ?? 0),
            'timeduration' => (int) ($this->config['timeduration'] ?? self::DEFAULT_DURATION),
            'description' => (string) ($this->config['description'] ?? ''),
            'location' => (string) ($this->config['location'] ?? ''),
            'eventtype' => 'group',
        ];
    }

    /**
     * Whether the user who configured this action may still create calendar imports.
     *
     * Checked at run time, so removing someone's importer capability also stops rules they set
     * up. Fails closed when the recorded author no longer exists.
     *
     * @return bool
     */
    protected function author_may_create(): bool {
        $authorid = (int) ($this->config['authorid'] ?? 0);
        if ($authorid <= 0) {
            $authorid = (int) ($this->rule->usermodified ?? 0);
        }
        if ($authorid <= 0) {
            return has_capability(calendar_import::CAPABILITY, \context_system::instance());
        }
        $author = \core_user::get_user($authorid);
        if (!$author || !empty($author->deleted) || !empty($author->suspended)) {
            return false;
        }
        return has_capability(calendar_import::CAPABILITY, \context_system::instance(), $author);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        if (!calendar_import::is_available()) {
            $mform->addElement('static', 'config_unavailable', '', get_string('calendarevent_unavailable', 'tool_automate'));
            return;
        }
        $mform->addElement('text', 'config_name', get_string('calendarevent_name', 'tool_automate'), ['size' => 50]);
        $mform->setType('config_name', PARAM_TEXT);
        $mform->addRule('config_name', null, 'required', null, 'client');
        $mform->addRule('config_name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('date_time_selector', 'config_timestart', get_string('calendarevent_timestart', 'tool_automate'));

        $mform->addElement('duration', 'config_timeduration', get_string('calendarevent_timeduration', 'tool_automate'));
        $mform->setDefault('config_timeduration', self::DEFAULT_DURATION);

        $mform->addElement('text', 'config_location', get_string('calendarevent_location', 'tool_automate'), ['size' => 50]);
        $mform->setType('config_location', PARAM_TEXT);

        $mform->addElement(
            'textarea',
            'config_description',
            get_string('calendarevent_description', 'tool_automate'),
            ['rows' => 4, 'cols' => 50]
        );
        $mform->setType('config_description', PARAM_TEXT);

        $mform->addElement(
            'text',
            'config_grouppattern',
            get_string('calendarevent_grouppattern', 'tool_automate'),
            ['size' => 40]
        );
        $mform->setType('config_grouppattern', PARAM_TEXT);
        $mform->addHelpButton('config_grouppattern', 'calendarevent_grouppattern', 'tool_automate');
    }

    /**
     * Extract config. Records who configured the action, for the run-time capability check.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        global $USER;
        return [
            'name' => trim((string) ($formdata->config_name ?? '')),
            'timestart' => (int) ($formdata->config_timestart ?? 0),
            'timeduration' => max(0, (int) ($formdata->config_timeduration ?? self::DEFAULT_DURATION)),
            'location' => trim((string) ($formdata->config_location ?? '')),
            'description' => trim((string) ($formdata->config_description ?? '')),
            'grouppattern' => trim((string) ($formdata->config_grouppattern ?? '')),
            'authorid' => (int) $USER->id,
        ];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_name' => (string) ($config['name'] ?? ''),
            'config_timestart' => (int) ($config['timestart'] ?? 0) ?: time(),
            'config_timeduration' => (int) ($config['timeduration'] ?? self::DEFAULT_DURATION),
            'config_location' => (string) ($config['location'] ?? ''),
            'config_description' => (string) ($config['description'] ?? ''),
            'config_grouppattern' => (string) ($config['grouppattern'] ?? ''),
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $pattern = trim((string) ($config['grouppattern'] ?? ''));
        $a = (object) [
            'name' => s((string) ($config['name'] ?? '')),
            'date' => userdate((int) ($config['timestart'] ?? 0), get_string('strftimedatetimeshort', 'langconfig')),
            'groups' => $pattern === '' ? get_string('calendarevent_allgroups', 'tool_automate') : s($pattern),
        ];
        return get_string('act_course_create_group_event_desc', 'tool_automate', $a);
    }
}
