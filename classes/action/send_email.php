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
 * Action: send the user an email. Subject is plain text; body is HTML.
 *
 * Placeholders: {firstname}, {lastname}, {fullname}, {email}, {username}.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_email extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_send_email', 'tool_automate');
    }

    /**
     * Send.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        $subject = self::substitute((string) ($this->config['subject'] ?? ''), $user);
        $body = self::substitute((string) ($this->config['body'] ?? ''), $user);
        if (trim($subject) === '' || trim(strip_tags($body)) === '') {
            return get_string('emailempty', 'tool_automate');
        }
        if (empty($user->email)) {
            return get_string('emailnoaddress', 'tool_automate');
        }
        if ($dryrun) {
            return get_string('emailwouldsend', 'tool_automate', $user->email);
        }
        $from = \core_user::get_noreply_user();
        $sent = email_to_user($user, $from, $subject, html_to_text($body), $body);
        return $sent
            ? get_string('emailsent', 'tool_automate', $user->email)
            : get_string('emailfailed', 'tool_automate', $user->email);
    }

    /**
     * Replace placeholders in a string.
     *
     * @param string $text
     * @param \stdClass $user
     * @return string
     */
    protected static function substitute(string $text, \stdClass $user): string {
        $map = [
            '{firstname}' => $user->firstname ?? '',
            '{lastname}'  => $user->lastname ?? '',
            '{fullname}'  => fullname($user),
            '{email}'     => $user->email ?? '',
            '{username}'  => $user->username ?? '',
        ];
        return strtr($text, $map);
    }

    /**
     * Form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        $mform->addElement('text', 'config_subject', get_string('emailsubject', 'tool_automate'),
            ['size' => 60]);
        $mform->setType('config_subject', PARAM_TEXT);
        $mform->addRule('config_subject', null, 'required', null, 'client');

        $mform->addElement('editor', 'config_body', get_string('emailbody', 'tool_automate'),
            null, ['enable_filemanagement' => false]);
        $mform->setType('config_body', PARAM_RAW);
        $mform->addHelpButton('config_body', 'emailbody', 'tool_automate');
    }

    /**
     * Extract.
     *
     * @param \stdClass $formdata
     * @return array
     */
    public static function extract_config(\stdClass $formdata): array {
        $body = is_array($formdata->config_body ?? null)
            ? (string) ($formdata->config_body['text'] ?? '')
            : (string) ($formdata->config_body ?? '');
        return [
            'subject' => (string) ($formdata->config_subject ?? ''),
            'body'    => $body,
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
            'config_subject' => $config['subject'] ?? '',
            'config_body'    => [
                'text'   => $config['body'] ?? '',
                'format' => FORMAT_HTML,
            ],
        ];
    }

    /**
     * Summary.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        return get_string('act_send_email_desc', 'tool_automate', s($config['subject'] ?? ''));
    }
}
