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

    /** @var string|null Filename of the saved report, set in finalise(). */
    protected ?string $reportfilename = null;

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
        global $CFG;

        $wantenrolled = !empty($this->config['includeenrolledcount']);
        $wantcompletion = !empty($this->config['includecompletionrate']);
        $wantgrade = !empty($this->config['includeavggrade']);

        // Only load the completion / grade libraries when the matching
        // enrichment column is requested - a plain course CSV needs none.
        if ($wantcompletion) {
            require_once($CFG->libdir . '/completionlib.php');
        }
        if ($wantgrade) {
            require_once($CFG->libdir . '/gradelib.php');
            require_once($CFG->libdir . '/grade/querylib.php');
        }

        $header = ['id', 'shortname', 'fullname', 'idnumber', 'category', 'visible'];
        if ($wantenrolled) {
            $header[] = 'enrolledcount';
        }
        if ($wantcompletion) {
            $header[] = 'completedcount';
            $header[] = 'completionrate';
        }
        if ($wantgrade) {
            $header[] = 'averagegrade';
        }

        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $header);
        foreach ($this->matched as $course) {
            $row = [
                $course->id,
                $course->shortname ?? '',
                $course->fullname ?? '',
                $course->idnumber ?? '',
                $course->category ?? '',
                $course->visible ?? '',
            ];
            $enrolled = ($wantenrolled || $wantcompletion || $wantgrade)
                ? self::enrolled_users($course)
                : [];
            if ($wantenrolled) {
                $row[] = count($enrolled);
            }
            if ($wantcompletion) {
                [$done, $rate] = self::course_completion_stats($course, $enrolled);
                $row[] = $done;
                $row[] = $rate;
            }
            if ($wantgrade) {
                $row[] = self::course_average_grade($course, $enrolled);
            }
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return (string) $csv;
    }

    /**
     * Active enrolled users in a course (id-only stubs are fine, but we
     * pull the id list for the stats helpers below).
     *
     * @param \stdClass $course
     * @return int[] Array of user ids.
     */
    protected static function enrolled_users(\stdClass $course): array {
        $context = \context_course::instance((int) $course->id, IGNORE_MISSING);
        if (!$context) {
            return [];
        }
        $users = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);
        return array_map(fn($u) => (int) $u->id, $users);
    }

    /**
     * Completion completion rate for a course. Returns [completed,
     * "X%"] or [0, "n/a"] when completion isn't enabled or the course
     * has no enrolled users.
     *
     * @param \stdClass $course
     * @param int[] $userids
     * @return array{0:int,1:string}
     */
    protected static function course_completion_stats(\stdClass $course, array $userids): array {
        $info = new \completion_info($course);
        if (!$info->is_enabled() || empty($userids)) {
            return [0, 'n/a'];
        }
        $done = 0;
        foreach ($userids as $userid) {
            if ($info->is_course_complete($userid)) {
                $done++;
            }
        }
        $pct = round(($done / count($userids)) * 100, 1);
        return [$done, $pct . '%'];
    }

    /**
     * Average course total grade across the enrolled users, formatted
     * with the course's display preference. Empty string when nobody
     * has a graded value.
     *
     * @param \stdClass $course
     * @param int[] $userids
     * @return string
     */
    protected static function course_average_grade(\stdClass $course, array $userids): string {
        if (empty($userids)) {
            return '';
        }
        $sum = 0.0;
        $n = 0;
        foreach ($userids as $userid) {
            $grade = grade_get_course_grade($userid, (int) $course->id);
            if ($grade && isset($grade->grade) && $grade->grade !== null) {
                $sum += (float) $grade->grade;
                $n++;
            }
        }
        if ($n === 0) {
            return '';
        }
        return (string) round($sum / $n, 2);
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
            // Timestamp plus a short random suffix so two reports
            // generated in the same second can't collide on a filename.
            'filename'  => 'automate-course-report-' . date('Ymd-His')
                . '-' . strtolower(random_string(6)) . '.csv',
        ];
        $file = $fs->create_file_from_string($info, $csv);
        if (!$file) {
            return null;
        }
        $this->reportfilename = $info['filename'];
        return (string) \moodle_url::make_pluginfile_url(
            $context->id,
            'tool_automate',
            'reports',
            0,
            '/',
            $info['filename'],
            false
        );
    }

    /**
     * Link to the on-screen view of the report saved during finalise().
     *
     * @return string|null
     */
    public function get_result_url(): ?string {
        if ($this->reportfilename === null) {
            return null;
        }
        return (new \moodle_url(
            '/admin/tool/automate/report.php',
            ['file' => $this->reportfilename]
        ))->out(false);
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

        // Optional course-level enrichment columns. Each one queries
        // the matched courses for activity / completion / grade stats,
        // which costs real DB time on big sites - off by default.
        $mform->addElement(
            'advcheckbox',
            'config_includeenrolledcount',
            get_string('includeenrolledcount', 'tool_automate')
        );
        $mform->addElement(
            'advcheckbox',
            'config_includecompletionrate',
            get_string('includecompletionrate', 'tool_automate')
        );
        $mform->addElement(
            'advcheckbox',
            'config_includeavggrade',
            get_string('includeavggrade', 'tool_automate')
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
            'recipient'              => trim((string) ($formdata->config_recipient ?? '')),
            'includeenrolledcount'   => !empty($formdata->config_includeenrolledcount) ? 1 : 0,
            'includecompletionrate'  => !empty($formdata->config_includecompletionrate) ? 1 : 0,
            'includeavggrade'        => !empty($formdata->config_includeavggrade) ? 1 : 0,
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
            'config_recipient'              => $config['recipient'] ?? '',
            'config_includeenrolledcount'   => !empty($config['includeenrolledcount']) ? 1 : 0,
            'config_includecompletionrate'  => !empty($config['includecompletionrate']) ? 1 : 0,
            'config_includeavggrade'        => !empty($config['includeavggrade']) ? 1 : 0,
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
            'act_course_generate_report_desc',
            'tool_automate',
            s($config['recipient'] ?? '-')
        );
    }
}
