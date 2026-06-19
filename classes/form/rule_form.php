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
 * Step 1-3 of the new-rule wizard: name, description, subject. Trigger
 * is collected separately in trigger_form so the page reads:
 * Rule -> Subject -> Conditions -> Actions -> Trigger.
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
        $mform = $this->_form;
        $lockedsubject = !empty($this->_customdata['lockedsubject']);

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

        // Step 3: subject. Locked once the rule has conditions or actions
        // so switching subject can't strand incompatible attached records.
        $subjects = [
            'user'   => get_string('subject_user', 'tool_automate'),
            'course' => get_string('subject_course', 'tool_automate'),
        ];
        $subjectel = $mform->addElement(
            'select',
            'subject',
            get_string('subject', 'tool_automate'),
            $subjects
        );
        $mform->setDefault('subject', 'user');
        $mform->addHelpButton('subject', 'subject', 'tool_automate');
        if ($lockedsubject) {
            $subjectel->freeze();
            $mform->addElement(
                'static',
                'subjectlocked',
                '',
                get_string('subjectlocked', 'tool_automate')
            );
        }

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'tool_automate'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons(true, get_string('savechanges', 'tool_automate'));
    }
}
