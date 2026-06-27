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
 * Bulk restore form: pick backup files from the repository directory and a
 * target category, then either preview or queue the restores.
 *
 * The file list is a multi-select whose options are the .mbz basenames found
 * in the source directory; because Moodle validates a select against its known
 * options, only listed files can ever be submitted - a second line of defence
 * behind restore_repository::resolve().
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_form extends \moodleform {
    /**
     * Define the form fields.
     */
    protected function definition() {
        $mform = $this->_form;
        $files = $this->_customdata['files'] ?? [];
        $categories = $this->_customdata['categories'] ?? [];
        $sourcedir = (string) ($this->_customdata['sourcedir'] ?? '');

        $mform->addElement(
            'static',
            'sourcedirinfo',
            get_string('restoresourcedir', 'tool_automate'),
            \html_writer::tag('code', s($sourcedir))
        );

        if (empty($files)) {
            $mform->addElement('static', 'nofiles', '', get_string('restorenofiles', 'tool_automate'));
            return;
        }

        $options = [];
        foreach ($files as $file) {
            $options[$file] = $file;
        }
        $select = $mform->addElement(
            'select',
            'files',
            get_string('restorefiles', 'tool_automate'),
            $options
        );
        $select->setMultiple(true);
        $select->setSize(min(15, max(5, count($options))));
        $mform->addRule('files', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('files', 'restorefiles', 'tool_automate');

        $mform->addElement(
            'select',
            'categoryid',
            get_string('restoretargetcategory', 'tool_automate'),
            $categories
        );
        $mform->addRule('categoryid', null, 'required', null, 'client');
        $mform->addHelpButton('categoryid', 'restoretargetcategory', 'tool_automate');

        // Two submit buttons: a no-op preview and the real queue action, so the
        // admin can confirm the selection before anything is created.
        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'preview', get_string('restorepreview', 'tool_automate'));
        $buttons[] = $mform->createElement('submit', 'restore', get_string('restorequeue', 'tool_automate'));
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', ' ', false);
    }
}
