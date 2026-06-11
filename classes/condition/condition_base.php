<?php
namespace tool_automate\condition;

/**
 * Base class for all conditions. A condition answers one question:
 * does this rule apply to this user? Add a new condition by extending
 * this class and registering it in manager::get_condition_types().
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class condition_base {

    /** @var array Decoded configuration for this condition. */
    protected $config;

    /**
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
}
