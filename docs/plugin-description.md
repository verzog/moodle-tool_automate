# Moodle Plugins directory listing

Copy for the [Moodle Plugins directory](https://moodle.org/plugins/) submission.
Keep this in step with the feature set as the plugin evolves.

## Short description (one line)

Rules-based, no-code automation for Moodle — a trigger plus conditions and
bounded actions against users or courses, with dry-run preview and audit
logging — plus bulk course restore from a backup directory with a searchable
file picker.

## Full description

Automate is a no-code rules engine for routine Moodle site administration. Each
rule is a **trigger** (manual, scheduled, or a Moodle event) plus optional
**conditions** (who or what it applies to) and one or more **bounded, named
actions** (what to do). There is no graph editor, no scripting, and no raw-SQL
or arbitrary-code action anywhere in the plugin — every rule is a single guided
form.

Conditions are combined with **all** (AND), **any** (OR), or a **custom boolean
expression** using the condition labels shown in the form, such as
`(c1 AND c2) OR NOT c3`, and each condition can be a *match* or a
*does not match*.

**A rule targets users or courses.**

*User conditions:* account age, authentication method, cohort membership,
standard or custom profile field, email matches (wildcard), enrolled in a
course, inactive for N days, name contains/matches, username contains/matches.

*User actions:* add to / remove from cohort, add to group, assign / revoke role,
enrol in a course, suspend / unsuspend, set a profile field, send an email,
generate a CSV report.

*Course conditions:* completion rate, ID number matches, in a category, name
contains/matches, no activity for N days (based on the course record's
last-modified time, not learner/teacher activity), start date between,
visibility.

*Course actions:* copy, delete (guarded), move to a category, set visibility,
email teachers, generate a CSV report.

**Built for safe operation.** Every rule has a **Preview (dry run)** that shows
exactly which users or courses would be affected — and what would happen —
before anything changes. Every run is logged for audit, and background tasks
narrate their work to the task logs. Destructive features (course delete, bulk
restore) are off by default behind site-level kill-switches, re-checked at both
queue and run time; course delete additionally requires a typed confirmation
phrase. Background jobs are concurrency-capped so they cannot monopolise the
cron worker pool. The high-risk actions — delete course and assign role — sit
behind a dedicated `tool/automate:managehighrisk` capability that no role
holds by default, so delegating rule management does not by itself hand out
course deletion or role assignment.

**Bulk restore from repository.** Separate from the rules engine, Automate can
restore Moodle course backups (`.mbz`) from a server directory into
**brand-new** courses (existing courses are never overwritten), from an admin
page or the CLI. The admin page offers a **server-side searchable,
type-to-filter file picker** that scales to directories holding hundreds of
backups, and previews the selection before queueing.

Implements the Moodle Privacy API. Licensed under the GPL v3.

## Submission fields

- **Frankenstyle name:** `tool_automate`
- **Plugin type:** Admin tool (`admin/tool`)
- **Source control URL:** <https://github.com/verzog/moodle-tool_automate>
- **Source control branch:** `main`
- **Bug tracker URL:** <https://github.com/verzog/moodle-tool_automate/issues>
- **Supported Moodle versions:** 5.0 – 5.2
- **Licence:** GNU GPL v3 or later
- **Maturity:** Stable
- **Current release:** 1.0.2

## Screenshots to provide (at least one required)

1. The **Automation rules** list page.
2. The rule **editor** (the five-step form) showing conditions and actions.
3. A **Preview (dry run)** result listing the users/courses that would be
   affected.
4. The **Bulk restore from repository** page with the searchable file picker.

## Packaging checklist

- Tag the release in Git to match `$plugin->release` (e.g.
  `git tag -a v1.0.2 -m 'tool_automate 1.0.2' && git push origin v1.0.2`).
- Build the ZIP so its **top-level folder is named `automate`** (the install
  folder under `admin/tool/`), not `moodle-tool_automate`:
  from the parent of a checkout named `automate`,
  `zip -r automate.zip automate -x '*.git*'`.
- Confirm CI is green for the tagged commit — the directory runs the same
  `moodle-plugin-ci` checks on upload.
