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

/**
 * Language strings (en) - Australian English usage throughout.
 *
 * @package    tool_automate
 * @copyright  2026 verzog <verzog@gmail.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['act_add_to_cohort'] = 'Add the user to a cohort';
$string['act_add_to_cohort_desc'] = 'Add to cohort "{$a}"';
$string['act_add_to_group'] = 'Add the user to a course group';
$string['act_add_to_group_desc'] = 'Add to group "{$a}"';
$string['act_assign_role'] = 'Assign a system role';
$string['act_assign_role_desc'] = 'Assign role "{$a}" at system level';
$string['act_enrol_in_course'] = 'Enrol the user in a course';
$string['act_enrol_in_course_desc'] = 'Enrol in course "{$a}"';
$string['act_remove_from_cohort'] = 'Remove the user from a cohort';
$string['act_remove_from_cohort_desc'] = 'Remove from cohort "{$a}"';
$string['act_revoke_role'] = 'Revoke a system role';
$string['act_revoke_role_desc'] = 'Revoke role "{$a}" at system level';
$string['act_send_email'] = 'Send the user an email';
$string['act_send_email_desc'] = 'Send email "{$a}"';
$string['act_set_profile_field'] = 'Set a profile field';
$string['act_set_profile_field_desc'] = 'Set {$a->field} to "{$a->value}"';
$string['act_suspend_user'] = 'Suspend the user account';
$string['act_unsuspend_user'] = 'Unsuspend the user account';
$string['actionheading'] = 'Actions - what should happen?';
$string['actions'] = 'Actions';
$string['actiontype'] = 'Action type';
$string['add'] = 'Add';
$string['addaction'] = 'Add an action...';
$string['addcondition'] = 'Add a condition...';
$string['allrules'] = 'All rules';
$string['alreadysuspended'] = 'User is already suspended';
$string['authmethod'] = 'Authentication method';
$string['automate:manage'] = 'Manage automation rules';
$string['back'] = 'Back to rules';
$string['changes'] = 'Changes';
$string['chooseatype'] = 'Choose a type to add.';
$string['cohort'] = 'Cohort';
$string['cohortadded'] = 'Added to cohort "{$a}"';
$string['cohortalready'] = 'Already in cohort "{$a}"';
$string['cohortgone'] = 'Cohort no longer exists';
$string['cohortmode_in'] = 'Is a member of';
$string['cohortmode_notin'] = 'Is not a member of';
$string['cohortnotmember'] = 'Not a member of cohort "{$a}"';
$string['cohortremoved'] = 'Removed from cohort "{$a}"';
$string['cohortwouldadd'] = 'Would add to cohort "{$a}"';
$string['cohortwouldremove'] = 'Would remove from cohort "{$a}"';
$string['comparison'] = 'Comparison';
$string['cond_account_age'] = 'Account age';
$string['cond_account_age_desc'] = 'Account is {$a->op} {$a->days} days old';
$string['cond_auth_method'] = 'Authentication method equals';
$string['cond_auth_method_desc'] = 'Auth method is "{$a}"';
$string['cond_cohort_membership'] = 'Cohort membership';
$string['cond_cohort_membership_desc_in'] = 'Is a member of cohort "{$a}"';
$string['cond_cohort_membership_desc_notin'] = 'Is not a member of cohort "{$a}"';
$string['cond_custom_profile_field'] = 'Custom profile field';
$string['cond_custom_profile_field_desc'] = '{$a->field} {$a->op} "{$a->value}"';
$string['cond_email_matches'] = 'Email address matches a pattern';
$string['cond_email_matches_desc'] = 'Email matches "{$a}"';
$string['cond_inactive_for_days'] = 'Not logged in for N days';
$string['cond_inactive_for_days_desc'] = 'Has not logged in for at least {$a} days';
$string['cond_profile_field'] = 'Profile field';
$string['cond_profile_field_desc'] = '{$a->field} {$a->op} "{$a->value}"';
$string['conditionheading'] = 'Conditions - who does this apply to?';
$string['conditiontype'] = 'Condition type';
$string['confirmdelete'] = 'Are you sure you want to delete the rule "{$a}"?';
$string['course'] = 'Course';
$string['coursegone'] = 'Course no longer exists';
$string['days'] = 'Days';
$string['defaultrole'] = 'Default enrolment role';
$string['delete'] = 'Delete';
$string['description'] = 'Description';
$string['dryrunnotice'] = 'This was a preview. No changes were made.';
$string['edit'] = 'Edit';
$string['emailbody'] = 'Body';
$string['emailbody_help'] = 'Use {firstname}, {lastname}, {fullname}, {email}, {username} as placeholders.';
$string['emailempty'] = 'Email subject or body is empty';
$string['emailfailed'] = 'Failed to send email to {$a}';
$string['emailnoaddress'] = 'User has no email address';
$string['emailpattern'] = 'Email pattern';
$string['emailpattern_help'] = 'Plain text matches anywhere (e.g. @example.com). Use * for wildcard (*@example.com).';
$string['emailsent'] = 'Sent email to {$a}';
$string['emailsubject'] = 'Subject';
$string['emailwouldsend'] = 'Would send email to {$a}';
$string['enabled'] = 'Enabled';
$string['enrolalready'] = 'Already enrolled in "{$a}"';
$string['enrolled'] = 'Enrolled in "{$a}"';
$string['enrolwould'] = 'Would enrol in "{$a}"';
$string['event_course_completed'] = 'A user completes a course';
$string['event_role_assigned'] = 'A role is assigned to a user';
$string['event_user_created'] = 'A new user is created';
$string['event_user_loggedin'] = 'A user logs in';
$string['event_user_updated'] = 'A user is updated';
$string['eventname'] = 'Triggering event';
$string['expression'] = 'Boolean expression';
$string['expression_help'] = 'Reference conditions by label (c1, c2, ...) with AND/OR/NOT. Example: c1 AND (c2 OR c3).';
$string['field'] = 'Field';
$string['fieldalready'] = 'Field "{$a->field}" already set to "{$a->value}"';
$string['fieldset'] = 'Set {$a->field} from "{$a->from}" to "{$a->to}"';
$string['fieldwouldset'] = 'Would set {$a->field} from "{$a->from}" to "{$a->to}"';
$string['filter'] = 'Filter';
$string['group'] = 'Group';
$string['groupadded'] = 'Added to group "{$a}"';
$string['groupalready'] = 'Already in group "{$a}"';
$string['groupgone'] = 'Group no longer exists';
$string['groupnotenrolled'] = 'User is not enrolled in the course for group "{$a}"';
$string['groupwouldadd'] = 'Would add to group "{$a}"';
$string['inactivedays'] = 'Days inactive';
$string['invalidfield'] = 'Invalid field';
$string['label'] = 'Label';
$string['live'] = 'Live';
$string['logic'] = 'Combine conditions with';
$string['logic_all'] = 'All conditions match (AND)';
$string['logic_any'] = 'Any condition matches (OR)';
$string['logic_expression'] = 'A custom expression';
$string['logicheading'] = 'How to combine these conditions';
$string['manualenrolmissing'] = 'Manual enrolment is not available in "{$a}"';
$string['matchedusers'] = 'Matched users: {$a}';
$string['membership'] = 'Membership';
$string['message'] = 'Detail';
$string['mode'] = 'Mode';
$string['name'] = 'Name';
$string['newrule'] = 'New rule';
$string['noactions'] = 'No actions yet. Add one below.';
$string['noconditions'] = 'No conditions yet. The rule will match every user. Add one below.';
$string['nocustomfields'] = 'No custom profile fields are defined on this site.';
$string['nogroups'] = 'No course groups exist yet.';
$string['norules'] = 'No rules yet. Create one to get started.';
$string['notsuspended'] = 'User is not suspended';
$string['op_atleast'] = 'at least';
$string['op_atmost'] = 'at most';
$string['op_contains'] = 'contains';
$string['op_equals'] = 'equals';
$string['operator'] = 'Operator';
$string['outcome'] = 'Outcome';
$string['plannedchanges'] = 'Planned changes';
$string['pluginname'] = 'Automate';
$string['preview'] = 'Preview (dry run)';
$string['privacy:metadata:log'] = 'A log of automation rules that have run against users.';
$string['privacy:metadata:log:outcome'] = 'What the rule did.';
$string['privacy:metadata:log:ruleid'] = 'The rule that ran.';
$string['privacy:metadata:log:timecreated'] = 'When the rule ran.';
$string['privacy:metadata:log:userid'] = 'The user the rule was applied to.';
$string['results'] = 'Results';
$string['resultsfor'] = 'Results for "{$a}"';
$string['role'] = 'Role';
$string['rolealready'] = 'Already has role "{$a}"';
$string['roleassigned'] = 'Assigned role "{$a}"';
$string['rolegone'] = 'Role no longer exists';
$string['rolenotassigned'] = 'Role "{$a}" was not previously assigned by this plugin';
$string['rolerevoked'] = 'Revoked role "{$a}"';
$string['rolewouldassign'] = 'Would assign role "{$a}"';
$string['rolewouldrevoke'] = 'Would revoke role "{$a}"';
$string['rule'] = 'Rule';
$string['rulename'] = 'Rule name';
$string['rules'] = 'Automation rules';
$string['runhistory'] = 'Run history';
$string['runnow'] = 'Run now';
$string['savechanges'] = 'Save rule';
$string['savelogic'] = 'Save logic';
$string['summary'] = 'Summary';
$string['suspended'] = 'User suspended';
$string['taskrunrules'] = 'Run scheduled automation rules';
$string['trigger'] = 'Trigger';
$string['trigger_cron'] = 'On a schedule (hourly)';
$string['trigger_event'] = 'When something happens';
$string['trigger_manual'] = 'Only when I run it manually';
$string['triggertype'] = 'When should this run?';
$string['unsuspended'] = 'User unsuspended';
$string['user'] = 'User';
$string['userfield_city'] = 'City';
$string['userfield_country'] = 'Country';
$string['userfield_department'] = 'Department';
$string['userfield_firstname'] = 'First name';
$string['userfield_institution'] = 'Institution';
$string['userfield_lang'] = 'Language';
$string['userfield_lastname'] = 'Last name';
$string['userfield_username'] = 'Username';
$string['value'] = 'Value';
$string['when'] = 'When';
$string['wouldsuspend'] = 'Would suspend user';
$string['wouldunsuspend'] = 'Would unsuspend user';
