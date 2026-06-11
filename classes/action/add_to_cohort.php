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
 * Action: add the user to a cohort (cohort sync then handles enrolment).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_to_cohort extends action_base {
    /**
     * Human-readable name shown in the rule form.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_add_to_cohort', 'tool_automate');
    }

    /**
     * Add the user to the configured cohort, or report what would happen.
     *
     * @param \stdClass $user A full user record.
     * @param bool $dryrun If true, make no changes.
     * @return string A short message describing the outcome.
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $cohortid = (int) ($this->config['cohortid'] ?? 0);
        if (!$cohortid || !$DB->record_exists('cohort', ['id' => $cohortid])) {
            return get_string('cohortgone', 'tool_automate');
        }
        $cohortname = $DB->get_field('cohort', 'name', ['id' => $cohortid]);

        if (cohort_is_member($cohortid, $user->id)) {
            return get_string('cohortalready', 'tool_automate', $cohortname);
        }
        if ($dryrun) {
            return get_string('cohortwouldadd', 'tool_automate', $cohortname);
        }
        cohort_add_member($cohortid, $user->id);
        return get_string('cohortadded', 'tool_automate', $cohortname);
    }
}
