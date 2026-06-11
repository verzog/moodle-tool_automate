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
 * Add/edit form for a rule.
 *
 * For this first version the form shows the config for the single available
 * condition (email pattern) and action (cohort) directly. When more types are
 * added, this graduates to per-type config supplied by each class.
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

        $events = ['\core\event\user_created' => get_string('event_user_created', 'tool_automate')];
        $mform->addElement('select', 'eventname', get_string('eventname', 'tool_automate'), $events);
        $mform->hideIf('eventname', 'triggertype', 'neq', 'event');

        // Condition.
        $mform->addElement('header', 'conditionheader', get_string('conditionheading', 'tool_automate'));
        $condtypes = [];
        foreach (\tool_automate\manager::get_condition_types() as $type => $class) {
            $condtypes[$type] = $class::get_name();
        }
        $mform->addElement('select', 'conditiontype', get_string('conditiontype', 'tool_automate'), $condtypes);
        $mform->addElement(
            'text',
            'emailpattern',
            get_string('emailpattern', 'tool_automate'),
            ['size' => 40, 'placeholder' => '@example.com']
        );
        $mform->setType('emailpattern', PARAM_TEXT);
        $mform->addHelpButton('emailpattern', 'emailpattern', 'tool_automate');

        // Action.
        $mform->addElement('header', 'actionheader', get_string('actionheading', 'tool_automate'));
        $acttypes = [];
        foreach (\tool_automate\manager::get_action_types() as $type => $class) {
            $acttypes[$type] = $class::get_name();
        }
        $mform->addElement('select', 'actiontype', get_string('actiontype', 'tool_automate'), $acttypes);

        $cohorts = $DB->get_records_menu('cohort', null, 'name', 'id, name');
        $mform->addElement('select', 'cohortid', get_string('cohort', 'tool_automate'), $cohorts);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('savechanges', 'tool_automate'));
    }
}
