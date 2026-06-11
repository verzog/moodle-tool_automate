<?php
namespace tool_automate\action;

/**
 * Action: add the user to a cohort (cohort sync then handles enrolment).
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_to_cohort extends action_base {

    public static function get_name(): string {
        return get_string('act_add_to_cohort', 'tool_automate');
    }

    public function execute(\stdClass $user, bool $dryrun): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $cohortid = (int) ($this->config['cohortid'] ?? 0);
        if (!$cohortid || !$DB->record_exists('cohort', ['id' => $cohortid])) {
            return 'Cohort no longer exists';
        }
        $cohortname = $DB->get_field('cohort', 'name', ['id' => $cohortid]);

        if (cohort_is_member($cohortid, $user->id)) {
            return 'Already in cohort "' . $cohortname . '"';
        }
        if ($dryrun) {
            return 'Would add to cohort "' . $cohortname . '"';
        }
        cohort_add_member($cohortid, $user->id);
        return 'Added to cohort "' . $cohortname . '"';
    }
}
