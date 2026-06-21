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

namespace tool_automate\task;

/**
 * Adhoc task: delete a single course in the background.
 *
 * Queued by the course_delete action so a rule that matches many
 * courses can return immediately rather than blocking the Run now
 * request (or the cron worker that triggered the rule) on a long
 * chain of synchronous delete_course() calls.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_course extends \core\task\adhoc_task {
    /**
     * Run the deletion.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $data = (object) $this->get_custom_data();
        $courseid = (int) ($data->courseid ?? 0);
        if ($courseid <= 0 || $courseid === (int) SITEID) {
            return;
        }
        // The matched course might have already been removed by an
        // earlier task or by an admin between queue and execute - that
        // is not an error worth raising.
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            mtrace('tool_automate: course ' . $courseid . ' already gone, skipping');
            return;
        }
        delete_course($courseid, false);
    }
}
