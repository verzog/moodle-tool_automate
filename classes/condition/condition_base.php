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
 * Base class for all conditions.
 *
 * A condition answers one question: does this rule apply to this user?
 * Add a new condition by extending this class and registering it in
 * manager::get_condition_types().
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class condition_base {
    /** @var array Decoded configuration for this condition. */
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
     * Does this rule apply to the given user?
     *
     * @param \stdClass $user A full user record.
     * @return bool
     */
    abstract public function matches(\stdClass $user): bool;

    /**
     * Add this condition's config fields to a form.
     *
     * Each implementation prefixes its field names with "config_" so the
     * generic condition_form can read them back unambiguously.
     *
     * @param \MoodleQuickForm $mform
     */
    public static function add_config_form_elements(\MoodleQuickForm $mform): void {
        unset($mform);
    }

    /**
     * Pull this condition's config out of submitted form data.
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
     * @param array $config Decoded configdata.
     * @return array Field name => value, ready for $mform->set_data().
     */
    public static function config_to_form_defaults(array $config): array {
        unset($config);
        return [];
    }

    /**
     * One-line summary of the condition shown on the rule edit page.
     *
     * @param array $config Decoded configdata.
     * @return string
     */
    public static function describe(array $config): string {
        unset($config);
        return static::get_name();
    }

    /**
     * Optional SQL pre-filter to narrow the candidate user set on a cron
     * scan. Returning ['', []] means "no pre-filter".
     *
     * @param array $config Decoded configdata.
     * @return array [string $sqlfragment, array $params] where the
     *               fragment references the user table alias `u`.
     */
    public static function get_user_sql_filter(array $config): array {
        unset($config);
        return ['', []];
    }
}
