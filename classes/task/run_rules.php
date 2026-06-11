<?php
namespace tool_automate\task;

/**
 * Scheduled task that runs all enabled "schedule" rules.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_rules extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('taskrunrules', 'tool_automate');
    }

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
