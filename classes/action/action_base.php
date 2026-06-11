<?php
namespace tool_automate\action;

/**
 * Base class for all actions. An action does one thing to one user.
 * Add a new action by extending this class and registering it in
 * manager::get_action_types(). Actions are deliberately bounded and
 * named - there is no raw-SQL action in this plugin.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class action_base {

    /** @var array Decoded configuration for this action. */
    protected $config;

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
}
