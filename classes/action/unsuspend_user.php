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
 * Action: unsuspend the user account.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unsuspend_user extends action_base {
    /**
     * Name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('act_unsuspend_user', 'tool_automate');
    }

    /**
     * Unsuspend.
     *
     * @param \stdClass $user
     * @param bool $dryrun
     * @return string
     */
    public function execute(\stdClass $user, bool $dryrun): string {
        global $DB;
        if (empty($user->suspended)) {
            return get_string('notsuspended', 'tool_automate');
        }
        if ($dryrun) {
            return get_string('wouldunsuspend', 'tool_automate');
        }
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);
        return get_string('unsuspended', 'tool_automate');
    }
}
