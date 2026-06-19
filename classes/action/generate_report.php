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
 * Action: build a report of the users matched by this rule run, save it
 * to Moodle's file storage, and email it to a configured recipient.
 *
 * Aggregates per-user invocations in execute(), emits in finalise() at
 * the end of the rule run.
 *
 * Content modes:
 *  - csv      : CSV attachment of matched users.
 *  - summary  : Short text summary in the body, no attachment.
 *  - both     : Summary in body and CSV attachment.
 *  - trigger  : Just send a link to an existing Moodle report (id stored
 *               in $config['reporturl']).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_report extends action_base {
    /** @var \stdClass[] Users collected during the run. */
    protected array $matched = [];

    /** @var string|null Filename of the saved report, set in finalise(). */
    protected ?string $reportfilename = null;

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_generate_report', 'tool_automate');
    }

    /**
     * Per-user invocation - just remember this user; the actual report is
     * built and sent in finalise().
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        unset($dryrun);
        $this->matched[] = $user;
        return get_string('reportqueued', 'tool_automate');
    }

    /**
     * Build the report and send it.
     *
     * @param bool $dryrun
     * @return string|null
     */
    public function finalise(bool $dryrun): ?string {
        global $CFG;

        $mode = (string) ($this->config['content'] ?? 'csv');
        $recipient = trim((string) ($this->config['recipient'] ?? ''));
        $rulename = trim((string) ($this->config['rulename'] ?? get_string('pluginname', 'tool_automate')));
        $count = count($this->matched);

        $body = $this->build_summary($rulename, $count);
        $attachment = null;
        if (in_array($mode, ['csv', 'both'], true)) {
            $attachment = $this->build_csv();
        }
        if ($mode === 'trigger') {
            $url = trim((string) ($this->config['reporturl'] ?? ''));
            $body .= "\n\n" . get_string('reporttriggerlink', 'tool_automate', $url ?: '?');
        }

        if ($dryrun) {
            $a = (object) ['count' => $count, 'recipient' => s($recipient ?: '?')];
            return get_string('reportwould', 'tool_automate', $a);
        }

        $savedurl = $this->save_to_filearea($body, $attachment);
        $sent = $recipient !== '' ? $this->email($recipient, $rulename, $body, $attachment) : false;

        return get_string('reportsent', 'tool_automate', (object) [
            'count'     => $count,
            'recipient' => s($recipient ?: '-'),
            'sent'      => $sent ? get_string('yes') : get_string('no'),
            'url'       => $savedurl ?: '-',
        ]);
    }

    /**
     * Build the plain-text summary block.
     *
     * @param string $rulename
     * @param int $count
     * @return string
     */
    protected function build_summary(string $rulename, int $count): string {
        $when = userdate(time());
        $a = (object) ['rule' => s($rulename), 'when' => $when, 'count' => $count];
        return get_string('reportsummary', 'tool_automate', $a);
    }

    /**
     * Build a CSV blob of matched users. When the action is configured
     * to enrich rows with course data, also emit completion / activity
     * completion / course grade columns for the configured course
     * scope (one specific course, or one row per enrolled course).
     *
     * @return string
     */
    protected function build_csv(): string {
        global $CFG;

        $courseid = (int) ($this->config['enrichcourseid'] ?? 0);
        $wantcompletion = !empty($this->config['includecompletion']);
        $wantactivity = !empty($this->config['includeactivitycompletion']);
        $wantgrade = !empty($this->config['includegrade']);
        $enrich = $courseid !== 0 && ($wantcompletion || $wantactivity || $wantgrade);

        // Only pull in the completion / grade libraries when enrichment is
        // actually configured - a plain user CSV needs none of them.
        // querylib.php (where grade_get_course_grade() lives) is under
        // Moodle's <dirroot>/grade/, not under <dirroot>/lib - $CFG->libdir
        // points at lib/, so the right base for this one require is
        // dirroot.
        if ($enrich) {
            require_once($CFG->libdir . '/completionlib.php');
            if ($wantgrade) {
                require_once($CFG->libdir . '/gradelib.php');
                require_once($CFG->dirroot . '/grade/querylib.php');
            }
        }

        $header = ['id', 'username', 'firstname', 'lastname', 'email', 'idnumber'];
        if ($enrich) {
            $header[] = 'courseid';
            $header[] = 'courseshortname';
            $header[] = 'coursefullname';
            if ($wantcompletion) {
                $header[] = 'completionstatus';
                $header[] = 'completiondate';
            }
            if ($wantactivity) {
                $header[] = 'activitiescomplete';
                $header[] = 'activitiestotal';
            }
            if ($wantgrade) {
                $header[] = 'coursegrade';
            }
        }

        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, $header);
        foreach ($this->matched as $user) {
            $base = [
                $user->id,
                $user->username ?? '',
                $user->firstname ?? '',
                $user->lastname ?? '',
                $user->email ?? '',
                $user->idnumber ?? '',
            ];
            if (!$enrich) {
                fputcsv($fh, $base);
                continue;
            }
            $courses = self::courses_for_user($user, $courseid);
            if (!$courses) {
                fputcsv($fh, array_merge($base, array_fill(0, count($header) - count($base), '')));
                continue;
            }
            foreach ($courses as $course) {
                $row = $base;
                $row[] = $course->id;
                $row[] = $course->shortname ?? '';
                $row[] = $course->fullname ?? '';
                [$status, $date] = $wantcompletion
                    ? self::user_course_completion($user, $course)
                    : ['', ''];
                if ($wantcompletion) {
                    $row[] = $status;
                    $row[] = $date;
                }
                if ($wantactivity) {
                    [$done, $total] = self::user_activity_completion($user, $course);
                    $row[] = $done;
                    $row[] = $total;
                }
                if ($wantgrade) {
                    $row[] = self::user_course_grade($user, $course);
                }
                fputcsv($fh, $row);
            }
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return (string) $csv;
    }

    /**
     * Resolve which courses to report for a given user. `courseid` of
     * -1 means every enrolled course; positive ids mean that specific
     * course; 0 (no enrichment) never reaches here.
     *
     * @param \stdClass $user
     * @param int $courseid
     * @return \stdClass[]
     */
    protected static function courses_for_user(\stdClass $user, int $courseid): array {
        global $DB;
        if ($courseid > 0) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            return $course ? [$course] : [];
        }
        if ($courseid === -1) {
            $courses = enrol_get_users_courses((int) $user->id, true, 'id, shortname, fullname, idnumber');
            return $courses ?: [];
        }
        return [];
    }

    /**
     * Course completion status + completion date for a user. Returns
     * ['', ''] when completion isn't enabled on the course.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return array{0:string,1:string}
     */
    protected static function user_course_completion(\stdClass $user, \stdClass $course): array {
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return ['n/a', ''];
        }
        $status = $info->is_course_complete((int) $user->id) ? 'completed' : 'in progress';
        $date = '';
        $completion = \completion_completion::fetch([
            'userid' => (int) $user->id,
            'course' => (int) $course->id,
        ]);
        if ($completion && !empty($completion->timecompleted)) {
            $date = userdate($completion->timecompleted, get_string('strftimedatetime', 'langconfig'));
        }
        return [$status, $date];
    }

    /**
     * Number of activities the user has completed in the course, and
     * the total number with completion tracking enabled.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return array{0:int,1:int}
     */
    protected static function user_activity_completion(\stdClass $user, \stdClass $course): array {
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return [0, 0];
        }
        $activities = $info->get_activities();
        $total = count($activities);
        $done = 0;
        foreach ($activities as $activity) {
            $data = $info->get_data($activity, false, (int) $user->id);
            if (in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $done++;
            }
        }
        return [$done, $total];
    }

    /**
     * The user's current course total grade, formatted with the course's
     * grade display preference. Returns '' when no graded value exists.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @return string
     */
    protected static function user_course_grade(\stdClass $user, \stdClass $course): string {
        $grade = grade_get_course_grade((int) $user->id, (int) $course->id);
        if (!$grade || !isset($grade->str_grade) || $grade->str_grade === '-') {
            return '';
        }
        return (string) $grade->str_grade;
    }

    /**
     * Save the report content to Moodle's file area and return a URL.
     *
     * @param string $body
     * @param string|null $csv
     * @return string|null
     */
    protected function save_to_filearea(string $body, ?string $csv): ?string {
        $fs = get_file_storage();
        $context = \context_system::instance();
        // Timestamp plus a short random suffix so two reports generated
        // in the same second can't collide on a filename.
        $filename = 'automate-report-' . date('Ymd-His') . '-' . strtolower(random_string(6));
        $info = [
            'contextid' => $context->id,
            'component' => 'tool_automate',
            'filearea'  => 'reports',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $filename . ($csv !== null ? '.csv' : '.txt'),
        ];
        $content = $csv !== null ? $csv : $body;
        $file = $fs->create_file_from_string($info, $content);
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
     * Email the report to the recipient.
     *
     * @param string $recipient
     * @param string $rulename
     * @param string $body
     * @param string|null $csv
     * @return bool
     */
    protected function email(string $recipient, string $rulename, string $body, ?string $csv): bool {
        global $CFG;
        $user = \core_user::get_user_by_email($recipient);
        if (!$user) {
            $user = \core_user::get_noreply_user();
            $user = clone $user;
            $user->email = $recipient;
        }
        $from = \core_user::get_noreply_user();
        $subject = get_string('reportsubject', 'tool_automate', s($rulename));

        $attachname = '';
        $attachpath = '';
        if ($csv !== null) {
            $tmp = make_request_directory();
            $attachname = 'automate-report.csv';
            $attachpath = $tmp . '/' . $attachname;
            file_put_contents($attachpath, $csv);
        }
        return (bool) email_to_user($user, $from, $subject, $body, '', $attachpath, $attachname);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        global $DB;
        $modes = [
            'csv'     => get_string('reportmode_csv', 'tool_automate'),
            'summary' => get_string('reportmode_summary', 'tool_automate'),
            'both'    => get_string('reportmode_both', 'tool_automate'),
            'trigger' => get_string('reportmode_trigger', 'tool_automate'),
        ];
        $mform->addElement('select', 'config_content', get_string('reportcontent', 'tool_automate'), $modes);

        $recipientlabel = get_string('reportrecipient', 'tool_automate');
        $mform->addElement('text', 'config_recipient', $recipientlabel, ['size' => 40]);
        $mform->setType('config_recipient', PARAM_EMAIL);

        $urllabel = get_string('reporturl', 'tool_automate');
        $mform->addElement('text', 'config_reporturl', $urllabel, ['size' => 60]);
        $mform->setType('config_reporturl', PARAM_URL);
        $mform->hideIf('config_reporturl', 'config_content', 'neq', 'trigger');

        // Optional course-progress enrichment. The course picker doubles
        // as the on/off switch: 0 means no extra columns, -1 means one
        // row per matched user per enrolled course, any other id picks
        // that course. Toggles are always visible so admins notice them
        // (a hideIf wouldn't survive the inline-AJAX form swap anyway).
        $courses = $DB->get_records_menu('course', null, 'fullname', 'id, fullname', 0, 500);
        unset($courses[SITEID]);
        $courseopts = [
            0  => get_string('coursescope_none', 'tool_automate'),
            -1 => get_string('coursescope_enrolled', 'tool_automate'),
        ] + $courses;
        $mform->addElement(
            'select',
            'config_enrichcourseid',
            get_string('coursescope', 'tool_automate'),
            $courseopts
        );
        $mform->addHelpButton('config_enrichcourseid', 'coursescope', 'tool_automate');

        $mform->addElement(
            'advcheckbox',
            'config_includecompletion',
            get_string('includecompletion', 'tool_automate')
        );

        $mform->addElement(
            'advcheckbox',
            'config_includeactivitycompletion',
            get_string('includeactivitycompletion', 'tool_automate')
        );

        $mform->addElement(
            'advcheckbox',
            'config_includegrade',
            get_string('includegrade', 'tool_automate')
        );
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'content'                   => (string) ($formdata->config_content ?? 'csv'),
            'recipient'                 => trim((string) ($formdata->config_recipient ?? '')),
            'reporturl'                 => trim((string) ($formdata->config_reporturl ?? '')),
            'enrichcourseid'            => (int) ($formdata->config_enrichcourseid ?? 0),
            'includecompletion'         => !empty($formdata->config_includecompletion) ? 1 : 0,
            'includeactivitycompletion' => !empty($formdata->config_includeactivitycompletion) ? 1 : 0,
            'includegrade'              => !empty($formdata->config_includegrade) ? 1 : 0,
        ];
    }

    /**
     * Defaults.
     *
     * @param array $config
     * @return array
     */
    public static function config_to_form_defaults(array $config): array {
        return [
            'config_content'                   => $config['content'] ?? 'csv',
            'config_recipient'                 => $config['recipient'] ?? '',
            'config_reporturl'                 => $config['reporturl'] ?? '',
            'config_enrichcourseid'            => (int) ($config['enrichcourseid'] ?? 0),
            'config_includecompletion'         => !empty($config['includecompletion']) ? 1 : 0,
            'config_includeactivitycompletion' => !empty($config['includeactivitycompletion']) ? 1 : 0,
            'config_includegrade'              => !empty($config['includegrade']) ? 1 : 0,
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $mode = $config['content'] ?? 'csv';
        $a = (object) [
            'mode'      => get_string('reportmode_' . $mode, 'tool_automate'),
            'recipient' => s($config['recipient'] ?? '-'),
        ];
        return get_string('act_generate_report_desc', 'tool_automate', $a);
    }
}
