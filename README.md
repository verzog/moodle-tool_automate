# Automate (tool_automate)

A no-code, rules-based automation tool for Moodle site administration. Each rule
is a **trigger** (manual, scheduled, or a Moodle event) plus optional
**conditions** (who or what it applies to) and one or more **bounded, named
actions** (what to do). No graph editor, no scripting — every rule is a single
guided form, and there is no raw-SQL or arbitrary-code action anywhere in the
plugin.

## Status

Stable (`1.0.2`). See `version.php` for the exact build number.

## What it does

A rule targets either **users** or **courses**. The editor walks through five
steps:

1. **Name** the rule.
2. **Description** (optional).
3. **Subject and conditions** — *Find users who…* or *Choose courses that…*.
   Each condition can be a *match* or a *does not match*, and the conditions are
   combined with **all** (AND), **any** (OR), or a **custom boolean expression**
   (using the condition labels shown in the form, e.g.
   `(c1 AND c2) OR NOT c3`).
4. **Actions** to apply to each matched user or course.
5. **When it runs** — on a schedule, when a Moodle event fires, or only when
   triggered manually.

### Conditions

**User conditions:** account age, authentication method, cohort membership,
standard profile field, custom profile field, email matches (wildcard), enrolled
in a course, inactive for N days, name contains / matches, username contains /
matches.

**Course conditions:** completion rate, ID number matches, in a category, name
contains / matches, no activity for N days (based on the course record's
last-modified time, not learner or teacher activity), start date between,
visibility.

### Actions

**User actions:** add to / remove from cohort, add to group, assign / revoke
role, enrol in a course, suspend / unsuspend, set a profile field, send an
email, generate a CSV report.

**Course actions:** copy, delete (guarded), move to a category, set visibility,
email teachers, generate a CSV report.

## Requirements

* Moodle 5.0–5.2 (PHP 8.2 or later; Moodle 5.2 requires PHP 8.3 or later).

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to *Site administration >
   Plugins > Install plugins*.
2. Upload the ZIP file with the plugin code. You should only be prompted to add
   extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually

Put the contents of this directory into

    {your/moodle/dirroot}/admin/tool/automate

Note that on Moodle 5.1 and later the web root is the `public/` directory, so the
plugin directory is `{your/moodle/projectroot}/public/admin/tool/automate`. The
plugin supports both layouts — its pages resolve `config.php` relative to the
web root, exactly as core admin tools do.

Afterwards, log in as an admin and go to *Site administration > Notifications*
to complete the installation, or run

    $ php admin/cli/upgrade.php

Manage rules at *Site administration > Plugins > Admin tools > Automate >
Automation rules*, and configure the plugin under *Automate > Settings*.

## Bulk restore from repository

Separate from the rules engine, the plugin can bulk-restore Moodle course
backups (`.mbz`) from a server directory into new courses. Point the *Bulk
restore source directory* setting at a folder of backups, then use *Plugins >
Admin tools > Automate > Bulk restore from repository* to select files and a
target category. Each selected backup is restored, in the background, into a
**brand-new course** — existing courses are never overwritten. The file picker
**searches server-side and type-to-filters**, scaling comfortably to directories
holding hundreds of backups, and you can preview the selection before queueing.
Watch progress in *Server > Tasks > Task logs*.

The same job can be driven from the command line:

    $ php admin/tool/automate/cli/restore_repository.php --category=2 --execute

Run with `--help` for all options, or `--list` to inspect the directory. The
whole feature sits behind an off-by-default *Allow bulk restore from repository*
kill-switch, and the background restores are throttled by a *Restore
concurrency* setting so a directory of large backups can't starve the cron
worker pool.

## Safety

* **Bounded, named actions only** — there is no raw-SQL or arbitrary-code action.
* **Preview (dry run)** on every rule shows exactly which users or courses would
  be affected, and what would happen, without making any change.
* **Audit logging** — every run is logged to `tool_automate_log`, and each
  scheduled / background task narrates what it did to *Site administration >
  Server > Tasks > Task logs*.
* **Kill-switches** — destructive features (course delete, bulk restore) are
  off by default and re-checked at both queue and run time. Course delete also
  requires a typed confirmation phrase.
* **Concurrency caps** — background restore and delete tasks are throttled so a
  large job cannot monopolise the cron worker pool.
* **Capability-gated high-risk actions** — managing rules needs
  `tool/automate:manage` (granted to the Manager archetype by default).
  Configuring the high-risk actions — **delete course** (irreversible data
  loss) and **assign role** (privilege grant) — additionally needs
  `tool/automate:managehighrisk`, which is granted to **no role by default**
  (full site admins bypass capability checks). So delegating rule management
  to a non-admin role does not by itself hand out course deletion or role
  assignment. `assign role` also only ever offers, and re-validates at run
  time against the rule author, the roles that author may actually assign at
  the system context.

## Privacy

The plugin implements the Moodle Privacy API (`classes/privacy/provider.php`).
It stores the administrator who last edited each rule (`usermodified`), and a
per-run log (rule, outcome, message, time, plus the affected user id for
user-subject runs; course-subject runs are not attributed to an individual).
Generated report files are removed when the relevant context is purged.

## Extending

* **Add a condition:** create a class in `classes/condition/` extending
  `condition_base`, then register it in `manager::get_condition_types()`.
* **Add an action:** create a class in `classes/action/` extending
  `action_base`, then register it in `manager::get_action_types()`.

The shared base classes provide helpers used across the plugin, including a
non-backtracking wildcard matcher (`condition_base::wildcard_match()`) and CSV
formula-injection neutralisation (`action_base::csv_safe_row()`).

## Releasing (maintainers)

The GitHub Actions workflow runs the full `moodle-plugin-ci` suite (PHP lint,
`phpcs` Moodle standard, PHPDoc, Mustache lint, Grunt, PHPUnit, Behat) across
every Moodle branch in `$plugin->supported` (5.0–5.2) on the PHP versions each
supports (8.2–8.4), against PostgreSQL and MariaDB — the same tooling the Moodle
Plugins directory runs on upload. To cut a release: keep `$plugin->version`
monotonically increasing, set `$plugin->release` / `$plugin->maturity`, update
`CHANGELOG.md`, tag it (`git tag -a v1.0.2 -m 'tool_automate 1.0.2'`), and
package the ZIP with a top-level folder named `automate` (not
`moodle-tool_automate`). Directory submission copy lives in
[`docs/plugin-description.md`](docs/plugin-description.md).

## Credits and acknowledgements

The trigger/condition/action design and several action concepts are inspired by
the open-source work of [Catalyst IT Australia](https://www.catalyst-au.net/),
in particular their `tool_dataflows` plugin (GPL v3), which has since been
withdrawn from public distribution. Sincere thanks to Catalyst IT and the
contributors to that project, whose work this plugin builds on conceptually.
This is an independent plugin — it shares no code with `tool_dataflows` and is
not affiliated with or endorsed by Catalyst IT.

Thanks also to the wider Moodle community, whose core APIs (events, scheduled
tasks, forms, and the privacy API) make plugins like this possible.

## Contributors

See the [contributors page](https://github.com/verzog/moodle-tool_automate/graphs/contributors)
for everyone who has contributed to this plugin. Contributions are welcome —
please open an issue or pull request.

## Licence

2026 verzog <verzog@gmail.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.
