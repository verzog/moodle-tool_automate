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
 * Action: queue an asynchronous copy of the matched course with a new
 * fullname/shortname. Uses Moodle's core copy_helper, which queues an
 * adhoc task to perform the backup + restore in the background.
 *
 * The copy never includes user data - this action is for templating new
 * courses off an existing one, not cloning enrolments and submissions.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_copy extends action_base {
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
        return get_string('act_course_copy', 'tool_automate');
    }

    /**
     * Queue an asynchronous copy.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        global $CFG, $DB;

        $fullnametmpl = trim((string) ($this->config['fullnametemplate'] ?? ''));
        $shortnametmpl = trim((string) ($this->config['shortnametemplate'] ?? ''));
        if ($fullnametmpl === '' || $shortnametmpl === '') {
            return get_string('coursecopynames', 'tool_automate');
        }

        $newfullname = self::interpolate($fullnametmpl, $subject);
        $newshortname = self::ensure_unique_shortname(self::interpolate($shortnametmpl, $subject));

        $targetcategory = (int) ($this->config['categoryid'] ?? 0);
        if (!$targetcategory) {
            $targetcategory = (int) $subject->category;
        } else if (!$DB->record_exists('course_categories', ['id' => $targetcategory])) {
            return get_string('categorygone', 'tool_automate');
        }

        if ($dryrun) {
            return get_string('coursewouldcopy', 'tool_automate', (object) [
                'source'    => format_string($subject->fullname),
                'shortname' => $newshortname,
            ]);
        }

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $formdata = (object) [
            'courseid'  => (int) $subject->id,
            'fullname'  => $newfullname,
            'shortname' => $newshortname,
            'category'  => $targetcategory,
            'visible'   => (int) ($subject->visible ?? 1),
            'startdate' => (int) ($subject->startdate ?? 0),
            'enddate'   => (int) ($subject->enddate ?? 0),
            'idnumber'  => '',
            'userdata'  => 0,
        ];
        $processed = \copy_helper::process_formdata($formdata);
        \copy_helper::create_copy($processed);

        return get_string('coursecopyqueued', 'tool_automate', (object) [
            'source'    => format_string($subject->fullname),
            'shortname' => $newshortname,
        ]);
    }

    /**
     * Replace {fullname}, {shortname}, {idnumber}, {date} placeholders.
     * {date} is YYYYMMDD-HHMMSS in the site timezone so generated
     * shortnames are unique even on the same day.
     *
     * @param string $template
     * @param \stdClass $course
     * @return string
     */
    protected static function interpolate(string $template, \stdClass $course): string {
        return strtr($template, [
            '{fullname}'  => (string) ($course->fullname ?? ''),
            '{shortname}' => (string) ($course->shortname ?? ''),
            '{idnumber}'  => (string) ($course->idnumber ?? ''),
            '{date}'      => date('Ymd-His'),
        ]);
    }

    /**
     * Append a numeric suffix until the shortname is unique. Moodle
     * requires shortname to be unique across courses; without this the
     * copy_helper fails when two rules fire in the same second or when
     * the template doesn't include {date}.
     *
     * @param string $candidate
     * @return string
     */
    protected static function ensure_unique_shortname(string $candidate): string {
        global $DB;
        $candidate = $candidate === '' ? 'copy' : $candidate;
        if (!$DB->record_exists('course', ['shortname' => $candidate])) {
            return $candidate;
        }
        $i = 2;
        while ($DB->record_exists('course', ['shortname' => $candidate . '-' . $i])) {
            $i++;
        }
        return $candidate . '-' . $i;
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $mform->addElement(
            'text',
            'config_fullnametemplate',
            get_string('coursecopyfullnametemplate', 'tool_automate'),
            ['size' => 50, 'placeholder' => '{fullname} (copy)']
        );
        $mform->setType('config_fullnametemplate', PARAM_TEXT);
        $mform->setDefault('config_fullnametemplate', '{fullname} (copy)');
        $mform->addHelpButton('config_fullnametemplate', 'coursecopyfullnametemplate', 'tool_automate');

        $mform->addElement(
            'text',
            'config_shortnametemplate',
            get_string('coursecopyshortnametemplate', 'tool_automate'),
            ['size' => 50, 'placeholder' => '{shortname}-copy-{date}']
        );
        $mform->setType('config_shortnametemplate', PARAM_TEXT);
        $mform->setDefault('config_shortnametemplate', '{shortname}-copy-{date}');
        $mform->addHelpButton('config_shortnametemplate', 'coursecopyshortnametemplate', 'tool_automate');

        $categories = ['' => get_string('coursecopysamecategory', 'tool_automate')];
        $categories += $DB->get_records_menu('course_categories', null, 'name', 'id, name');
        $mform->addElement(
            'select',
            'config_categoryid',
            get_string('coursecopytargetcategory', 'tool_automate'),
            $categories
        );
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'fullnametemplate'  => trim((string) ($formdata->config_fullnametemplate ?? '')),
            'shortnametemplate' => trim((string) ($formdata->config_shortnametemplate ?? '')),
            'categoryid'        => (int) ($formdata->config_categoryid ?? 0),
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
            'config_fullnametemplate'  => $config['fullnametemplate'] ?? '{fullname} (copy)',
            'config_shortnametemplate' => $config['shortnametemplate'] ?? '{shortname}-copy-{date}',
            'config_categoryid'        => (int) ($config['categoryid'] ?? 0),
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string(
            'act_course_copy_desc',
            'tool_automate',
            s($config['shortnametemplate'] ?? '')
        );
    }
}
