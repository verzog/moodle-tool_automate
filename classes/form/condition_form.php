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
 * Add/edit form for a condition. The condition type is fixed when the
 * form is constructed (so the per-type config fields can be added).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $type = (string) ($this->_customdata['type'] ?? '');
        $types = \tool_automate\manager::get_condition_types();
        if (!isset($types[$type])) {
            throw new \moodle_exception('invalidparameter');
        }
        $class = $types[$type];

        $polarities = [
            \tool_automate\manager::POLARITY_MATCH    => get_string('polarity_match', 'tool_automate'),
            \tool_automate\manager::POLARITY_NOTMATCH => get_string('polarity_notmatch', 'tool_automate'),
        ];
        $mform->addElement('select', 'polarity', get_string('polarity', 'tool_automate'), $polarities);
        $mform->setDefault('polarity', \tool_automate\manager::POLARITY_MATCH);
        $mform->addHelpButton('polarity', 'polarity', 'tool_automate');

        $class::add_config_form_elements($mform);

        $mform->addElement('hidden', 'ruleid');
        $mform->setType('ruleid', PARAM_INT);
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'type');
        $mform->setType('type', PARAM_ALPHANUMEXT);

        $this->add_action_buttons(true, get_string('savechanges', 'tool_automate'));
    }
}
