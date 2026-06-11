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
 * Base class for all actions. An action does one thing to one user.
 *
 * Add a new action by extending this class and registering it in
 * manager::get_action_types(). Actions are deliberately bounded and
 * named - there is no raw-SQL action in this plugin.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class action_base {
    /** @var array Decoded configuration for this action. */
    protected $config;

    /**
     * Constructor.
     *
     * @param array $config Decoded configuration (from the rule's stored JSON).
     */
    public function __construct(array $config = []) {
        $this->config = $config;
    }

    /**
     * Human-readable name shown in the rule form.
     *
     * @return string
     */
    abstract public static function get_name(): string;

    /**
     * Do the thing - or, in dry-run mode, report what would be done
     * without changing anything.
     *
     * @param \stdClass $user A full user record.
     * @param bool $dryrun If true, make no changes.
     * @return string A short message describing the outcome.
     */
    abstract public function execute(\stdClass $user, bool $dryrun): string;

    /**
     * Called once after the per-user loop finishes. Aggregating actions
     * (like 'generate report') accumulate state in execute() and emit
     * their real result here.
     *
     * Returning null means "no aggregate result" - the manager won't log
     * anything for this action's finalise step.
     *
     * @param bool $dryrun
     * @return string|null
     */
    public function finalise(bool $dryrun): ?string {
        unset($dryrun);
        return null;
    }

    /**
     * Add this action's config fields to a form.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        unset($mform);
    }

    /**
     * Pull this action's config out of submitted form data.
     *
     * @param \stdClass $formdata
     * @return array Decoded config to be JSON-encoded into configdata.
     */
    public static function extract_config(\stdClass $formdata): array {
        unset($formdata);
        return [];
    }

    /**
     * Map stored config back to form field defaults.
     *
     * @param array $config
     * @return array Field name => value.
     */
    public static function config_to_form_defaults(array $config): array {
        unset($config);
        return [];
    }

    /**
     * One-line summary of the action shown on the rule edit page.
     *
     * @param array $config
     * @return string
     */
    public static function describe(array $config): string {
        unset($config);
        return static::get_name();
    }
}
