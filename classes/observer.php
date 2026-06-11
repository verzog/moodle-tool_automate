<?php
namespace tool_automate;

/**
 * Event observers. When a watched event fires, run any enabled "event"
 * rules for the user involved.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Handle a new user being created.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event): void {
        global $DB;
        $rules = $DB->get_records('tool_automate_rule', [
            'enabled'     => 1,
            'triggertype' => 'event',
            'eventname'   => '\core\event\user_created',
        ]);
        foreach ($rules as $rule) {
            manager::run_rule((int) $rule->id, false, (int) $event->objectid);
        }
    }
}
