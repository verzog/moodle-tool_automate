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

namespace tool_automate\condition;

/**
 * Condition: the user's email matches a wildcard pattern (e.g. *@sccaorg.au).
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_matches extends condition_base {
    /**
     * Human-readable name shown in the rule form.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('cond_email_matches', 'tool_automate');
    }

    /**
     * Does the user's email address match the configured wildcard pattern?
     *
     * @param \stdClass $user A full user record.
     * @return bool
     */
    public function matches(\stdClass $user): bool {
        $pattern = trim($this->config['pattern'] ?? '');
        if ($pattern === '' || empty($user->email)) {
            return false;
        }
        // Turn the wildcard pattern into a safe, case-insensitive regex.
        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
        return (bool) preg_match($regex, $user->email);
    }
}
