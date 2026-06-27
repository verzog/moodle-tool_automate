# Moodle Plugins directory listing

Copy for the [Moodle Plugins directory](https://moodle.org/plugins/) submission.
Keep this in step with the feature set as the plugin evolves.

## Short description (one line)

Rules-based automation for Moodle — trigger + conditions + bounded actions
against users or courses, with dry-run preview and run logging — plus bulk
course restore from a backup directory with a searchable file picker.

## Full description

Automate is a no-code rules engine for routine site administration. Each rule is
a **trigger** (manual, scheduled, or a Moodle event) plus optional
**conditions** (who/what it applies to, with match / does-not-match and all /
any / custom-expression logic) and one or more **bounded, named actions** —
there is no raw-SQL action.

Rules can target **users** (add/remove cohort, suspend, assign role, enrol,
email, set profile field, generate report, …) or **courses** (show/hide, move
category, email teachers, copy, guarded delete, generate report, …).

Every rule has a **Preview (dry run)** that shows exactly which users or courses
would be affected before anything changes, and every run is logged for audit.
Destructive operations sit behind site-level kill-switches and background tasks
are concurrency-capped.

It also includes **Bulk restore from repository**: point it at a server
directory of Moodle course backups (`.mbz`) and restore the selected ones into
brand-new courses, in the background, from an admin page or CLI. The admin page
offers a **searchable, type-to-filter file picker** that scales comfortably to
directories holding dozens or hundreds of backups, and previews the selection
before queueing.

Implements the Privacy API. GPL v3.

## Submission fields

- **Frankenstyle name:** `tool_automate`
- **Source control URL:** <https://github.com/verzog/moodle-tool_automate>
- **Bug tracker URL:** <https://github.com/verzog/moodle-tool_automate/issues>
- **Supported Moodle versions:** 5.0 – 5.2
- **Licence:** GPL v3
