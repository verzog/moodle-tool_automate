# Moodle Plugins directory listing

Copy for the [Moodle Plugins directory](https://moodle.org/plugins/) submission.
Keep this in step with the feature set as the plugin evolves.

## Short description (one line)

<!-- Keep within 255 characters when the wrapped lines are joined (directory
limit). Current length: 252. -->

No-code Moodle admin automation: rules made of a trigger, conditions and
bounded actions for user and course management, with dry-run preview and audit
logging. Plus bulk course restore, Canvas import, and calendar and slide
import from server folders.

## Full description

Automate (`tool_automate`) is a no-code automation plugin for Moodle site
administration — a rules engine that handles routine user and course management
so admins don't have to do it by hand. Each rule is a **trigger** (manual,
scheduled, or a Moodle event) plus optional
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
email teachers, generate a CSV report, create a group calendar event (needs the
Calendar Group Event Importer plugin).

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

**Works with other plugins, when they are installed.** These are soft
integrations: each feature only appears when its companion plugin is installed,
each sits behind its own off-by-default kill-switch, and Automate works on its
own without any of them.

- **Bulk Canvas import** (with CourseUplift, `tool_courseuplift`): import many
  Canvas Common Cartridge (`.imscc`) exports from a list of download URLs or a
  server directory, either building the courses straight away or staging them
  for review first.
- **Group calendar events** (with the Calendar Group Event Importer,
  `local_grpcalendarimport`): a course action that adds an event to matching
  groups, and a scheduled import of CSV/TSV event files from a server folder.
- **Slide deck import** (with PowerPoint import for Book,
  `booktool_importpptx`, or for Lesson, `local_lessonimportpptx`): create a
  *Find files that…* rule that watches a server folder and imports each
  PowerPoint dropped there as a Book or Lesson, into a course you choose or
  into a hidden temporary course. No file renaming and no manifest. Decks are
  imported one at a time in the background, and each is filed as processed or
  failed with a results file.

Implements the Moodle Privacy API. Licensed under the GPL v3.

## Submission fields

- **Frankenstyle name:** `tool_automate`
- **Plugin type:** Admin tool (`admin/tool`)
- **Source control URL:** <https://github.com/verzog/moodle-tool_automate>
- **Source control branch:** `main`
- **Bug tracker URL:** <https://github.com/verzog/moodle-tool_automate/issues>
- **Supported Moodle versions:** 5.1 – 5.3 LTS
- **Licence:** GNU GPL v3 or later
- **Maturity:** Stable
- **Current release:** 1.7.0
- **Tags:** `automation`, `admin tool`, `rules`, `workflow`, `user management`,
  `course management`, `bulk restore`, `canvas import`, `powerpoint import`,
  `calendar`, `scheduled tasks`, `site administration`, `no-code`
  (These are the directory's own search/browse keywords — set them on the
  listing so admins searching for "automation", "bulk restore", etc. find the
  plugin. Match the terms real queries use, not internal jargon.)

## Screenshots to provide (at least one required)

1. The **Automation rules** list page.
2. The rule **editor** (the five-step form) showing conditions and actions.
3. A **Preview (dry run)** result listing the users/courses that would be
   affected.
4. The **Bulk restore from repository** page with the searchable file picker.
5. A **Find files that…** rule importing slide decks from a watch folder (optional).

## Packaging checklist

- Bump `$plugin->version` and `$plugin->release` in `version.php`, merge to
  `main`, and confirm CI is green on that commit — the directory runs the same
  `moodle-plugin-ci` checks on upload.
- Publish a GitHub release whose tag is `v` plus `$plugin->release` (for
  example `v1.7.0`). The **Release zip** workflow checks the tag against
  `version.php` and attaches `tool_automate-v1.7.0.zip`, whose top-level folder
  is `automate`, as the directory and Moodle's *Install plugins* page expect.
- Upload that attached zip to the directory. Do not build a zip by hand or use
  GitHub's "Source code (zip)", whose top-level folder is wrong.
- Check this description still matches the features, requirements and
  supported versions in the README.
