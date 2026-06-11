<?php
/**
 * Language strings (en) - Australian English usage throughout.
 *
 * @package    tool_automate
 * @copyright  2026 Your Name <you@example.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Automate';
$string['automate:manage'] = 'Manage automation rules';

// Rule list.
$string['rules'] = 'Automation rules';
$string['newrule'] = 'New rule';
$string['norules'] = 'No rules yet. Create one to get started.';
$string['name'] = 'Name';
$string['enabled'] = 'Enabled';
$string['trigger'] = 'Trigger';
$string['actions'] = 'Actions';
$string['edit'] = 'Edit';
$string['delete'] = 'Delete';
$string['runnow'] = 'Run now';
$string['preview'] = 'Preview (dry run)';
$string['confirmdelete'] = 'Are you sure you want to delete the rule "{$a}"?';

// Rule form.
$string['rulename'] = 'Rule name';
$string['description'] = 'Description';
$string['triggertype'] = 'When should this run?';
$string['trigger_cron'] = 'On a schedule (hourly)';
$string['trigger_event'] = 'When something happens';
$string['trigger_manual'] = 'Only when I run it manually';
$string['eventname'] = 'Triggering event';
$string['event_user_created'] = 'A new user is created';
$string['conditionheading'] = 'Condition - who does this apply to?';
$string['conditiontype'] = 'Condition type';
$string['actionheading'] = 'Action - what should happen?';
$string['actiontype'] = 'Action type';
$string['savechanges'] = 'Save rule';

// Conditions.
$string['cond_email_matches'] = 'Email address matches a pattern';
$string['emailpattern'] = 'Email pattern';
$string['emailpattern_help'] = 'Use * as a wildcard. For example, *@sccaorg.au matches every address ending in @sccaorg.au.';

// Actions.
$string['act_add_to_cohort'] = 'Add the user to a cohort';
$string['cohort'] = 'Cohort';

// Running / results.
$string['results'] = 'Results';
$string['resultsfor'] = 'Results for "{$a}"';
$string['dryrunnotice'] = 'This was a preview. No changes were made.';
$string['matchedusers'] = 'Matched users: {$a}';
$string['user'] = 'User';
$string['outcome'] = 'Outcome';
$string['message'] = 'Detail';
$string['back'] = 'Back to rules';

// Tasks / privacy.
$string['taskrunrules'] = 'Run scheduled automation rules';
$string['privacy:metadata:log'] = 'A log of automation rules that have run against users.';
$string['privacy:metadata:log:userid'] = 'The user the rule was applied to.';
$string['privacy:metadata:log:ruleid'] = 'The rule that ran.';
$string['privacy:metadata:log:outcome'] = 'What the rule did.';
$string['privacy:metadata:log:timecreated'] = 'When the rule ran.';
