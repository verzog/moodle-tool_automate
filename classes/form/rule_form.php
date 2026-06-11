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
 * Add/edit form for a rule (metadata only). Conditions and actions are
 * managed below the form on the same page via their own edit pages.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_form extends \moodleform {
    /**
     * Define the form fields.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('rulename', 'tool_automate'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'description',
            get_string('description', 'tool_automate'),
            ['rows' => 2, 'cols' => 50]
        );
        $mform->setType('description', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'tool_automate'));

        // Trigger.
        $triggers = [
            'cron'   => get_string('trigger_cron', 'tool_automate'),
            'event'  => get_string('trigger_event', 'tool_automate'),
            'manual' => get_string('trigger_manual', 'tool_automate'),
        ];
        $mform->addElement('select', 'triggertype', get_string('triggertype', 'tool_automate'), $triggers);

        $events = [
            '\core\event\user_created'     => get_string('event_user_created', 'tool_automate'),
            '\core\event\user_updated'     => get_string('event_user_updated', 'tool_automate'),
            '\core\event\user_loggedin'    => get_string('event_user_loggedin', 'tool_automate'),
            '\core\event\course_completed' => get_string('event_course_completed', 'tool_automate'),
            '\core\event\role_assigned'    => get_string('event_role_assigned', 'tool_automate'),
        ];
        $mform->addElement('select', 'eventname', get_string('eventname', 'tool_automate'), $events);
        $mform->hideIf('eventname', 'triggertype', 'neq', 'event');

        $courses = $DB->get_records_menu('course', null, 'fullname', 'id, fullname', 0, 500);
        unset($courses[SITEID]);
        $mform->addElement('select', 'courseid', get_string('course', 'tool_automate'), $courses);
        $mform->hideIf('courseid', 'triggertype', 'neq', 'event');
        $mform->hideIf('courseid', 'eventname', 'neq', '\core\event\course_completed');

        $roles = role_get_names(\context_system::instance(), ROLENAME_ALIAS, true);
        $roleoptions = [];
        foreach ($roles as $r) {
            $roleoptions[$r->id] = $r->localname;
        }
        $mform->addElement('select', 'roleid', get_string('role', 'tool_automate'), $roleoptions);
        $mform->hideIf('roleid', 'triggertype', 'neq', 'event');
        $mform->hideIf('roleid', 'eventname', 'neq', '\core\event\role_assigned');

        // Logic.
        $logics = [
            'all'        => get_string('logic_all', 'tool_automate'),
            'any'        => get_string('logic_any', 'tool_automate'),
            'expression' => get_string('logic_expression', 'tool_automate'),
        ];
        $mform->addElement('select', 'logic', get_string('logic', 'tool_automate'), $logics);
        $mform->addElement(
            'textarea',
            'expression',
            get_string('expression', 'tool_automate'),
            ['rows' => 2, 'cols' => 50, 'placeholder' => 'c1 AND (c2 OR c3)']
        );
        $mform->setType('expression', PARAM_TEXT);
        $mform->addHelpButton('expression', 'expression', 'tool_automate');
        $mform->hideIf('expression', 'logic', 'neq', 'expression');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('savechanges', 'tool_automate'));
    }

    /**
     * Server-side validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (($data['logic'] ?? '') === 'expression') {
            $expr = trim((string) ($data['expression'] ?? ''));
            if ($expr === '') {
                $errors['expression'] = get_string('required');
            } else if ($err = \tool_automate\expression::validate($expr)) {
                $errors['expression'] = $err;
            }
        }
        return $errors;
    }
}
