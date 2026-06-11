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
 * How to combine a rule's conditions. Rendered inside the Conditions
 * section of edit.php once a second condition is added — when a rule
 * only has zero or one conditions the choice is moot.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class logic_form extends \moodleform {
    /**
     * Define the form fields.
     */
    protected function definition() {
        $mform = $this->_form;

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

        $mform->addElement('hidden', 'ruleid');
        $mform->setType('ruleid', PARAM_INT);
        $mform->addElement('hidden', 'updatelogic', 1);
        $mform->setType('updatelogic', PARAM_INT);

        $mform->addElement('submit', 'savelogic', get_string('savelogic', 'tool_automate'));
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
