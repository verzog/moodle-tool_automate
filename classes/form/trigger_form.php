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

namespace tool_automate\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Step 5 of the new-rule wizard: when should this rule run? Posts to
 * edit.php and is disambiguated from the rule form by an "updatetrigger"
 * marker.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trigger_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $subject = (string) ($this->_customdata['subject'] ?? 'user');

        $triggers = [
            'cron'   => get_string('trigger_cron', 'tool_automate'),
            'event'  => get_string('trigger_event', 'tool_automate'),
            'manual' => get_string('trigger_manual', 'tool_automate'),
        ];
        $mform->addElement('select', 'triggertype', get_string('triggertype', 'tool_automate'), $triggers);

        $schedules = [
            'hourly'   => get_string('schedule_hourly', 'tool_automate'),
            'daily'    => get_string('schedule_daily', 'tool_automate'),
            'monthly'  => get_string('schedule_monthly', 'tool_automate'),
            'oncedate' => get_string('schedule_oncedate', 'tool_automate'),
        ];
        $mform->addElement('select', 'schedule', get_string('schedule', 'tool_automate'), $schedules);
        $mform->setDefault('schedule', 'hourly');
        $mform->hideIf('schedule', 'triggertype', 'neq', 'cron');

        $mform->addElement(
            'date_time_selector',
            'scheduledate',
            get_string('scheduledate', 'tool_automate'),
            ['optional' => false]
        );
        $mform->hideIf('scheduledate', 'triggertype', 'neq', 'cron');
        $mform->hideIf('scheduledate', 'schedule', 'neq', 'oncedate');

        if ($subject === 'course') {
            $events = [
                '\core\event\course_created' => get_string('event_course_created', 'tool_automate'),
                '\core\event\course_updated' => get_string('event_course_updated', 'tool_automate'),
            ];
        } else {
            $events = [
                '\core\event\user_created'     => get_string('event_user_created', 'tool_automate'),
                '\core\event\user_updated'     => get_string('event_user_updated', 'tool_automate'),
                '\core\event\user_loggedin'    => get_string('event_user_loggedin', 'tool_automate'),
                '\core\event\course_completed' => get_string('event_course_completed', 'tool_automate'),
                '\core\event\role_assigned'    => get_string('event_role_assigned', 'tool_automate'),
            ];
        }
        $mform->addElement('select', 'eventname', get_string('eventname', 'tool_automate'), $events);
        $mform->hideIf('eventname', 'triggertype', 'neq', 'event');

        if ($subject === 'user') {
            $courses = $DB->get_records_menu('course', null, 'fullname', 'id, fullname', 0, 500);
            unset($courses[SITEID]);
            $mform->addElement('select', 'courseid', get_string('course', 'tool_automate'), $courses);
            $mform->hideIf('courseid', 'triggertype', 'neq', 'event');
            $mform->hideIf('courseid', 'eventname', 'neq', '\core\event\course_completed');

            $roleoptions = role_get_names(\context_system::instance(), ROLENAME_ALIAS, true);
            $mform->addElement('select', 'roleid', get_string('role', 'tool_automate'), $roleoptions);
            $mform->hideIf('roleid', 'triggertype', 'neq', 'event');
            $mform->hideIf('roleid', 'eventname', 'neq', '\core\event\role_assigned');
        }

        $mform->addElement('hidden', 'ruleid');
        $mform->setType('ruleid', PARAM_INT);
        $mform->addElement('hidden', 'updatetrigger', 1);
        $mform->setType('updatetrigger', PARAM_INT);

        $mform->addElement('submit', 'savetrigger', get_string('savetrigger', 'tool_automate'));
    }

    /**
     * Validate that a "pick a date" cron rule has a future date set.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['triggertype'] ?? '') === 'cron' && ($data['schedule'] ?? '') === 'oncedate') {
            $when = (int) ($data['scheduledate'] ?? 0);
            if ($when <= 0) {
                $errors['scheduledate'] = get_string('required');
            }
        }
        return $errors;
    }
}
