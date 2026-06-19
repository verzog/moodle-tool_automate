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
 * Action: collect matched courses through the rule run and emit a CSV
 * report at the end. Mirrors the user-subject generate_report action.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_generate_report extends action_base {
    /** @var \stdClass[] Courses collected during the run. */
    protected array $matched = [];

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
        return get_string('act_course_generate_report', 'tool_automate');
    }

    /**
     * Remember this course; the report is built in finalise().
     *
     * @param \stdClass $subject
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        unset($dryrun);
        $this->matched[] = $subject;
        return get_string('reportqueued', 'tool_automate');
    }

    /**
     * Build and send the report.
     *
     * @param bool $dryrun
     * @return string|null
     */
    public function finalise(bool $dryrun): ?string {
        $recipient = trim((string) ($this->config['recipient'] ?? ''));
        $count = count($this->matched);

        if ($dryrun) {
            return get_string('reportwould', 'tool_automate', (object) [
                'count'     => $count,
                'recipient' => s($recipient ?: '?'),
            ]);
        }

        $csv = $this->build_csv();
        $savedurl = $this->save_to_filearea($csv);
        $sent = $recipient !== '' ? $this->email($recipient, $csv) : false;

        return get_string('reportsent', 'tool_automate', (object) [
            'count'     => $count,
            'recipient' => s($recipient ?: '-'),
            'sent'      => $sent ? get_string('yes') : get_string('no'),
            'url'       => $savedurl ?: '-',
        ]);
    }

    /**
     * Build a CSV blob of matched courses.
     *
     * @return string
     */
    protected function build_csv(): string {
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['id', 'shortname', 'fullname', 'idnumber', 'category', 'visible']);
        foreach ($this->matched as $course) {
            fputcsv($fh, [
                $course->id,
                $course->shortname ?? '',
                $course->fullname ?? '',
                $course->idnumber ?? '',
                $course->category ?? '',
                $course->visible ?? '',
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return (string) $csv;
    }

    /**
     * Save the CSV to Moodle's file area and return a URL.
     *
     * @param string $csv
     * @return string|null
     */
    protected function save_to_filearea(string $csv): ?string {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $info = [
            'contextid' => $context->id,
            'component' => 'tool_automate',
            'filearea'  => 'reports',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => 'automate-course-report-' . date('Ymd-His') . '.csv',
        ];
        $file = $fs->create_file_from_string($info, $csv);
        return $file ? (string) \moodle_url::make_pluginfile_url(
            $context->id,
            'tool_automate',
            'reports',
            0,
            '/',
            $info['filename'],
            false
        ) : null;
    }

    /**
     * Email the CSV to the recipient.
     *
     * @param string $recipient
     * @param string $csv
     * @return bool
     */
    protected function email(string $recipient, string $csv): bool {
        $user = \core_user::get_user_by_email($recipient);
        if (!$user) {
            $user = clone \core_user::get_noreply_user();
            $user->email = $recipient;
        }
        $from = \core_user::get_noreply_user();
        $subject = get_string('reportsubject', 'tool_automate', s(get_string('pluginname', 'tool_automate')));
        $tmp = make_request_directory();
        $attachname = 'automate-course-report.csv';
        $attachpath = $tmp . '/' . $attachname;
        file_put_contents($attachpath, $csv);
        return (bool) email_to_user($user, $from, $subject, '', '', $attachpath, $attachname);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement(
            'text',
            'config_recipient',
            get_string('reportrecipient', 'tool_automate'),
            ['size' => 40]
        );
        $mform->setType('config_recipient', PARAM_EMAIL);
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return ['recipient' => trim((string) ($formdata->config_recipient ?? ''))];
    }

    /**
     * Form defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return ['config_recipient' => $config['recipient'] ?? ''];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string(
            'act_course_generate_report_desc',
            'tool_automate',
            s($config['recipient'] ?? '-')
        );
    }
}
