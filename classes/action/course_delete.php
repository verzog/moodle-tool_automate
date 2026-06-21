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
 * Action: queue every course matched by the rule for background
 * deletion via Moodle's adhoc task queue. Pattern matching is
 * expressed through the rule's conditions (course_name_matches,
 * course_idnumber_matches, course_no_activity_days, etc).
 *
 * Three safety levers on the action config:
 *  - Confirmation phrase (DELETE) must be typed before anything is
 *    queued; without it the action is a per-course no-op.
 *  - Optional "hide the course immediately on queue" - flips
 *    course.visible = 0 at queue time so staff/students see the
 *    course disappear long before cron actually deletes it, giving
 *    a clear visual warning if the wrong courses got matched.
 *  - Optional "email site admins when courses are queued" - sends
 *    a single roll-up message once the rule run finishes, with the
 *    list of every queued course and the scheduled run time, so the
 *    deletion is visible to everyone with a stake in the site.
 *
 * The deletion itself is delayed: the action stores an hour-of-day
 * (default 02:00) and each queued task's next_run_time is set to the
 * next local occurrence of that hour. "Immediately" is also a valid
 * choice and just leaves next_run_time at the next cron tick.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_delete extends action_base {
    /** Confirmation phrase that has to be typed before this action will run. */
    public const CONFIRM_PHRASE = 'DELETE';

    /** Sentinel value for "no delay - run on the next cron tick". */
    public const DELAY_IMMEDIATE = -1;

    /** @var array<int,array{id:int,name:string,url:string}> Queued courses, captured for the admin notification. */
    protected array $queued = [];

    /** @var int|null Scheduled epoch when the queued tasks will run, used in the admin notification. */
    protected ?int $scheduledat = null;

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
     * Queue the matched course for background deletion.
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

        // Site-level kill-switch: when off, every queued deletion is a
        // no-op. The action is also hidden from the picker in this
        // state (see manager::get_action_types_for_subject), so a
        // newly-built rule won't even reach this code - this branch
        // exists for rules that were configured while the setting was
        // on but where it's been turned back off since.
        if (!get_config('tool_automate', 'allow_course_delete')) {
            return get_string('coursedeletedisabled', 'tool_automate', $name);
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

        // Hide first so the warning is visible to anyone on the site
        // the moment the rule runs - if the wrong courses were
        // matched, learners/teachers see them disappear immediately
        // and can raise the alarm long before cron actually deletes
        // anything. Cheap enough to do even when the deletion is
        // scheduled for cron tonight.
        if (!empty($this->config['hidefirst']) && (int) $subject->visible === 1) {
            course_change_visibility((int) $subject->id, false);
        }

        $delayhour = (int) ($this->config['delayhour'] ?? 2);
        $runat = self::compute_next_run_time($delayhour);
        $this->scheduledat = $runat ?? $this->scheduledat ?? time();

        $task = new \tool_automate\task\delete_course();
        $task->set_custom_data(['courseid' => (int) $subject->id]);
        if ($runat !== null) {
            $task->set_next_run_time($runat);
        }
        \core\task\manager::queue_adhoc_task($task);

        // Stash for the finalise() roll-up email.
        $this->queued[] = [
            'id'   => (int) $subject->id,
            'name' => $name,
            'url'  => (new \moodle_url('/course/view.php', ['id' => $subject->id]))->out(false),
        ];

        if ($runat !== null) {
            return get_string('coursedeletequeuedat', 'tool_automate', (object) [
                'course' => $name,
                'when'   => userdate($runat),
            ]);
        }
        return get_string('coursedeletequeued', 'tool_automate', $name);
    }

    /**
     * After the per-course loop: optionally email site admins a
     * roll-up of everything that just got queued.
     *
     * @param bool $dryrun
     * @return string|null
     */
    public function finalise(bool $dryrun): ?string {
        if ($dryrun || empty($this->queued) || empty($this->config['notifyadmins'])) {
            return null;
        }
        $admins = get_admins();
        if (!$admins) {
            return null;
        }

        $when = $this->scheduledat ? userdate($this->scheduledat) : userdate(time());
        $subject = get_string('coursedeleteadminemailsubject', 'tool_automate', count($this->queued));
        $lines = [];
        foreach ($this->queued as $row) {
            $lines[] = '- ' . $row['name'] . ' (id ' . $row['id'] . ') ' . $row['url'];
        }
        $body = get_string('coursedeleteadminemailbody', 'tool_automate', (object) [
            'count' => count($this->queued),
            'when'  => $when,
            'list'  => implode("\n", $lines),
        ]);

        $from = \core_user::get_noreply_user();
        $sent = 0;
        foreach ($admins as $admin) {
            if (email_to_user($admin, $from, $subject, $body)) {
                $sent++;
            }
        }
        return get_string('coursedeleteadminemailsent', 'tool_automate', (object) [
            'sent'  => $sent,
            'total' => count($admins),
        ]);
    }

    /**
     * Resolve the configured delay to a Unix timestamp, or null when
     * the admin asked for "immediate" (next cron tick).
     *
     * @param int $delayhour 0-23 for a specific local hour, or
     *                       self::DELAY_IMMEDIATE for no delay.
     * @return int|null
     */
    protected static function compute_next_run_time(int $delayhour): ?int {
        if ($delayhour < 0 || $delayhour > 23) {
            return null;
        }
        $tz = \core_date::get_user_timezone_object();
        $dt = new \DateTime('now', $tz);
        $target = clone $dt;
        $target->setTime($delayhour, 0, 0);
        // If today's slot already passed, push to tomorrow's.
        if ($target <= $dt) {
            $target->modify('+1 day');
        }
        return $target->getTimestamp();
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

        // Time-of-day for the actual deletion to run. Default 02:00,
        // i.e. overnight - the rule's Run now / cron trigger queues
        // the work, and the next 02:00 cron tick picks it up.
        $hours = [self::DELAY_IMMEDIATE => get_string('coursedeletedelay_immediate', 'tool_automate')];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = sprintf('%02d:00', $h);
        }
        $mform->addElement(
            'select',
            'config_delayhour',
            get_string('coursedeletedelay', 'tool_automate'),
            $hours
        );
        $mform->addHelpButton('config_delayhour', 'coursedeletedelay', 'tool_automate');

        $mform->addElement(
            'advcheckbox',
            'config_hidefirst',
            get_string('coursedeletehidefirst', 'tool_automate')
        );
        $mform->addHelpButton('config_hidefirst', 'coursedeletehidefirst', 'tool_automate');

        $mform->addElement(
            'advcheckbox',
            'config_notifyadmins',
            get_string('coursedeletenotifyadmins', 'tool_automate')
        );
        $mform->addHelpButton('config_notifyadmins', 'coursedeletenotifyadmins', 'tool_automate');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        $hour = (int) ($formdata->config_delayhour ?? 2);
        if ($hour !== self::DELAY_IMMEDIATE && ($hour < 0 || $hour > 23)) {
            $hour = 2;
        }
        return [
            'confirm'       => trim((string) ($formdata->config_confirm ?? '')),
            'delayhour'     => $hour,
            'hidefirst'     => !empty($formdata->config_hidefirst) ? 1 : 0,
            'notifyadmins'  => !empty($formdata->config_notifyadmins) ? 1 : 0,
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
            'config_confirm'      => (string) ($config['confirm'] ?? ''),
            'config_delayhour'    => array_key_exists('delayhour', $config) ? (int) $config['delayhour'] : 2,
            'config_hidefirst'    => isset($config['hidefirst']) ? (int) !empty($config['hidefirst']) : 1,
            'config_notifyadmins' => isset($config['notifyadmins']) ? (int) !empty($config['notifyadmins']) : 1,
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        $confirmed = trim((string) ($config['confirm'] ?? '')) === self::CONFIRM_PHRASE;
        if (!$confirmed) {
            return get_string('act_course_delete_desc_unconfirmed', 'tool_automate');
        }
        $hour = (int) ($config['delayhour'] ?? 2);
        $when = $hour === self::DELAY_IMMEDIATE
            ? get_string('coursedeletedelay_immediate', 'tool_automate')
            : sprintf('%02d:00', $hour);
        return get_string('act_course_delete_desc', 'tool_automate', $when);
    }
}
