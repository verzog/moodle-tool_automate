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
 * Action: email every teacher (editingteacher) in the course.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_email_teachers extends action_base {
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
        return get_string('act_course_email_teachers', 'tool_automate');
    }

    /**
     * Email the course's editing teachers.
     *
     * @param \stdClass $subject A course record.
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $subject, bool $dryrun): string {
        $subjectline = trim((string) ($this->config['subject'] ?? ''));
        $body = trim((string) ($this->config['body'] ?? ''));
        if ($subjectline === '' || $body === '') {
            return get_string('emailempty', 'tool_automate');
        }

        $context = \context_course::instance((int) $subject->id, IGNORE_MISSING);
        if (!$context) {
            return get_string('coursegone', 'tool_automate');
        }
        $roleid = $this->editingteacher_roleid();
        if (!$roleid) {
            // A 0 role id is treated by get_role_users() as "any role", which
            // would email every enrolled user. Bail instead.
            return get_string('noteachers', 'tool_automate', format_string($subject->fullname));
        }
        // Pull u.* so email_to_user() can honour its suspended /
        // auth=nologin / deleted skip checks. Selecting a narrow column
        // list dropped those fields, which made the mail helper send to
        // accounts it would otherwise have filtered.
        $teachers = get_role_users($roleid, $context, false, 'u.*');
        if (!$teachers) {
            return get_string('noteachers', 'tool_automate', format_string($subject->fullname));
        }

        $renderedsubject = self::interpolate($subjectline, $subject);
        $renderedbody = self::interpolate($body, $subject);

        if ($dryrun) {
            return get_string('coursewouldemailteachers', 'tool_automate', (object) [
                'course' => format_string($subject->fullname),
                'count'  => count($teachers),
            ]);
        }

        $from = \core_user::get_noreply_user();
        $sent = 0;
        foreach ($teachers as $teacher) {
            if (email_to_user($teacher, $from, $renderedsubject, $renderedbody)) {
                $sent++;
            }
        }
        return get_string('courseemailedteachers', 'tool_automate', (object) [
            'course' => format_string($subject->fullname),
            'count'  => $sent,
        ]);
    }

    /**
     * Resolve the editing-teacher role id. Looks up by archetype rather
     * than by hard-coded shortname so a renamed editingteacher role still
     * resolves. Returns 0 when no such role exists - the caller MUST
     * treat 0 as "no teachers" rather than passing it to get_role_users(),
     * which interprets an empty role id as "any role".
     *
     * @return int
     */
    protected function editingteacher_roleid(): int {
        global $DB;
        $id = $DB->get_field('role', 'id', ['archetype' => 'editingteacher']);
        return $id ? (int) $id : 0;
    }

    /**
     * Replace {course}, {shortname}, {idnumber} placeholders.
     *
     * @param string $template
     * @param \stdClass $course
     * @return string
     */
    protected static function interpolate(string $template, \stdClass $course): string {
        return strtr($template, [
            '{course}'    => (string) ($course->fullname ?? ''),
            '{shortname}' => (string) ($course->shortname ?? ''),
            '{idnumber}'  => (string) ($course->idnumber ?? ''),
        ]);
    }

    /**
     * Form fields.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement('text', 'config_subject', get_string('emailsubject', 'tool_automate'), ['size' => 50]);
        $mform->setType('config_subject', PARAM_TEXT);
        $mform->addElement('textarea', 'config_body', get_string('emailbody', 'tool_automate'), [
            'rows' => 6, 'cols' => 60,
        ]);
        $mform->setType('config_body', PARAM_TEXT);
        $mform->addHelpButton('config_body', 'courseemailbody', 'tool_automate');
    }

    /**
     * Extract config.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        return [
            'subject' => trim((string) ($formdata->config_subject ?? '')),
            'body'    => trim((string) ($formdata->config_body ?? '')),
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
            'config_subject' => $config['subject'] ?? '',
            'config_body'    => $config['body'] ?? '',
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('act_course_email_teachers_desc', 'tool_automate', s($config['subject'] ?? ''));
    }
}
