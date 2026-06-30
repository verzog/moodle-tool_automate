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

    /** @var \stdClass|null The rule this action belongs to, set by the engine before execute(). */
    protected ?\stdClass $rule = null;

    /**
     * Constructor.
     *
     * @param array $config Decoded configuration (from the rule's stored JSON).
     */
    public function __construct(array $config = []) {
        $this->config = $config;
    }

    /**
     * Give the action the rule it belongs to. The engine calls this after
     * constructing the action from stored config and before execute(), so
     * an action that needs rule-level context - for example the authoring
     * user, to re-check a privilege at run time - can reach it. Most
     * actions ignore it.
     *
     * @param \stdClass $rule
     */
    public function set_rule(\stdClass $rule): void {
        $this->rule = $rule;
    }

    /**
     * Whether this action is "high risk": it either causes irreversible
     * data loss or grants privilege. High-risk actions are gated behind
     * the dedicated tool/automate:managehighrisk capability - which is not
     * held by the Manager archetype out of the box - on top of the manage
     * capability, so a delegated manager cannot wire up a destructive or
     * escalating action even once a site admin has enabled it. Defaults to
     * false; the destructive / escalating actions override it.
     *
     * @return bool
     */
    public static function is_high_risk(): bool {
        return false;
    }

    /**
     * Human-readable name shown in the rule form.
     *
     * @return string
     */
    abstract public static function get_name(): string;

    /**
     * What kind of record this action operates on. Default is 'user' so
     * existing actions don't have to opt in. Course-shaped actions
     * override to return 'course'.
     *
     * @return string 'user' or 'course'.
     */
    public static function get_subject(): string {
        return 'user';
    }

    /**
     * Do the thing - or, in dry-run mode, report what would be done
     * without changing anything.
     *
     * @param \stdClass $subject A full user record, or a course record for
     *                            course-subject actions.
     * @param bool $dryrun If true, make no changes.
     * @return string A short message describing the outcome.
     */
    abstract public function execute(\stdClass $subject, bool $dryrun): string;

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
     * Optional link to a viewable result produced by this action, shown
     * next to the finalise message on the results page. Aggregating
     * actions that save an artefact (like the report actions) return a
     * URL to view it on screen; everything else returns null.
     *
     * @return string|null
     */
    public function get_result_url(): ?string {
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

    /**
     * Neutralise CSV formula injection in a row of values.
     *
     * A spreadsheet treats a cell beginning with =, +, -, @, tab or a
     * carriage return as a formula, so an attacker-influenced value such as
     * a profile field or course name could execute when the generated file
     * is opened in Excel or LibreOffice. Prefixing such cells with a single
     * quote forces them to be read as text. Numeric and other safe cells are
     * left untouched.
     *
     * @param array $row Row values destined for fputcsv().
     * @return array The same row with risky leading characters neutralised.
     */
    protected static function csv_safe_row(array $row): array {
        foreach ($row as $i => $value) {
            $value = (string) $value;
            if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
                $row[$i] = "'" . $value;
            }
        }
        return $row;
    }
}
