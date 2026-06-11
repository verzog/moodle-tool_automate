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
 * Scheduled task that runs all enabled "schedule" rules.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_rules extends \core\task\scheduled_task {
    /**
     * Human-readable task name shown in the task admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskrunrules', 'tool_automate');
    }

    /**
     * Run all enabled cron-triggered rules.
     */
    public function execute(): void {
        global $DB;
        $rules = $DB->get_records('tool_automate_rule', [
            'enabled'     => 1,
            'triggertype' => 'cron',
        ]);
        foreach ($rules as $rule) {
            \tool_automate\manager::run_rule((int) $rule->id, false);
        }
    }
}
