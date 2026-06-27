# Automate (tool_automate)

A simple, rules-based automation tool for Moodle. Each rule is a **trigger**
(schedule, event, or manual) plus an optional **condition** (who it applies to)
and one or more **actions** (what to do). No graph editor, no expression
language - every rule is a single form.

## Status

Beta maturity (see `version.php` for the exact build number). Rules can
target either **users** or **courses**.
The editor walks you through five steps in order:

1. **Name** the rule.
2. **Description** (optional).
3. **Subject** — *Find users who…* or *Choose courses that…* — and the
   conditions that build the matching set. Each condition can be a
   *match* or a *does not match*.
4. **Actions** to apply to each matched user or course.
5. **When should this run?** — on a schedule, when a Moodle event fires,
   or only when triggered manually.

## Requirements

* Moodle 5.0+ (PHP 8.2 or later; Moodle 5.2 requires PHP 8.3 or later)

## Installing via uploaded ZIP file

1. Log in to your Moodle site as an admin and go to *Site administration >
   Plugins > Install plugins*.
2. Upload the ZIP file with the plugin code. You should only be prompted to
   add extra details if your plugin type is not automatically detected.
3. Check the plugin validation report and finish the installation.

## Installing manually

The plugin can be also installed by putting the contents of this directory to

    {your/moodle/dirroot}/admin/tool/automate

Note that on Moodle 5.1 and later the web root is the `public/` directory, so
the plugin directory is `{your/moodle/projectroot}/public/admin/tool/automate`.
The plugin supports both layouts - its pages resolve `config.php` relative to
the web root, exactly as core admin tools do.

Afterwards, log in to your Moodle site as an admin and go to *Site
administration > Notifications* to complete the installation.

Alternatively, you can run

    $ php admin/cli/upgrade.php

to complete the installation from the command line.

Manage rules at *Site administration > Plugins > Admin tools > Automate >
Automation rules*, and configure the plugin under *Automate > Settings*.

## Bulk restore from repository

Separate from the rules engine, the plugin can bulk-restore Moodle course
backups (`.mbz`) from a server directory into new courses. Point the
*Bulk restore source directory* setting at a folder of backups, then use
*Plugins > Admin tools > Automate > Bulk restore from repository* to select
files and a target category. Each selected backup is restored, in the
background, into a **brand-new course** - existing courses are never
overwritten. Preview the selection before queueing, and watch progress in
*Server > Tasks > Task logs*.

The same job can be driven from the command line:

    $ php admin/tool/automate/cli/restore_repository.php --category=2 --execute

Run with `--help` for all options, or `--list` to inspect the directory. The
whole feature sits behind an off-by-default *Allow bulk restore from
repository* kill-switch, and the background restores are throttled by a
*Restore concurrency* setting so a directory of large backups can't starve the
cron worker pool.

## Safety

All actions are **bounded and named** - there is no raw-SQL action. Every rule
has a **Preview (dry run)** option that shows exactly which users or courses
would be affected, and what would happen, without making any change. Every run
is logged to `tool_automate_log`, and each scheduled / background task narrates
what it did to *Site administration > Server > Tasks > Task logs*.

## Extending

* Add a condition: create a class in `classes/condition/` extending
  `condition_base`, then register it in `manager::get_condition_types()`.
* Add an action: create a class in `classes/action/` extending `action_base`,
  then register it in `manager::get_action_types()`.

## Publishing to the Moodle Plugins directory

This plugin is not yet listed in the [Moodle Plugins directory](https://moodle.org/plugins/).
The path from the current beta to a published, installable-from-Moodle plugin:

### 1. Get the code release-ready

* **CI is green.** The GitHub Actions workflow already runs the full
  `moodle-plugin-ci` suite (PHP lint, `phpcs` with the Moodle standard, PHPDoc
  checker, Mustache lint, Grunt, PHPUnit, and Behat) across every Moodle branch
  in `$plugin->supported` (Moodle 5.0–5.2) on the PHP versions each branch
  supports (8.2–8.4), against both PostgreSQL and MariaDB. Keep the workflow
  matrix in step with `$plugin->supported` so you never submit a package for a
  Moodle version the reviewer-equivalent checks never ran against. Every check
  must pass — the directory's automated reviewer runs the same tooling.
* **Run the checks locally** before submitting. The same `moodle-plugin-ci`
  commands the workflow runs mirror the *Plugin validation* that the directory
  applies automatically on upload, so a clean local run means no surprises at
  submission. Fix every error and warning.
* **No bundled third-party libraries** (there are none today). If any are added
  later, declare them in `thirdpartylibs.xml`.
* **Privacy API** provider is implemented (`classes/privacy/provider.php`) — the
  directory expects this for any plugin that stores personal data.
* **Licence file present.** The repository ships the full GPL v3 text as a
  top-level [`LICENSE`](LICENSE) file, matching the GPL v3 headers in every
  source file.

### 2. Decide maturity and version

* The directory accepts beta plugins, but for a first *stable* listing, raise
  `$plugin->maturity` in `version.php` from `MATURITY_BETA` to
  `MATURITY_STABLE` once you are confident, and set a matching `$plugin->release`
  (e.g. `1.0.0`). Keep the `YYYYMMDDXX` `$plugin->version` monotonically
  increasing.
* Confirm `$plugin->requires` and `$plugin->supported` reflect the Moodle
  versions you actually test against (currently Moodle 5.0–5.2).

### 3. Tag and package a release

* Tag the release in Git so it is reproducible, matching the `$plugin->release`
  in `version.php`, e.g. `git tag -a v0.9.17 -m 'tool_automate 0.9.17-beta' &&
  git push origin v0.9.17`.
* Build the ZIP so that **its top-level folder is named `automate`** (the
  plugin's install folder under `admin/tool/`), *not* `moodle-tool_automate`.
  For example, from the parent of a checkout named `automate`:
  `zip -r automate.zip automate -x '*.git*'`.

### 4. Submit to the directory

* Sign in at <https://moodle.org> and go to
  <https://moodle.org/plugins/> → **Register a plugin**.
* Provide the frankenstyle name `tool_automate` (check the name is not already
  taken), the public source-control URL
  (<https://github.com/verzog/moodle-tool_automate>), the bug-tracker URL, the
  supported Moodle versions, a description, and at least one screenshot.
* Upload the release ZIP from step 3.

### 5. Approval and maintenance

* A Plugins-directory reviewer runs the automated checks again and does a manual
  review. Respond to their feedback and push fixes; re-submit the updated ZIP if
  asked.
* Once approved, the plugin is listed and installable from within Moodle.
* For each subsequent release, add a new version through the directory (one
  package per release, with the Moodle versions it supports), keep this
  `CHANGELOG.md` up to date, and keep CI green.

## Credits and acknowledgements

The trigger/condition/action design and several action concepts are inspired by
the open-source work of [Catalyst IT Australia](https://www.catalyst-au.net/),
in particular their `tool_dataflows` plugin (GPL v3), which has since been
withdrawn from public distribution. Sincere thanks to Catalyst IT and the
contributors to that project, whose work this plugin builds on conceptually.
This is an independent plugin - it shares no code with `tool_dataflows` and is
not affiliated with or endorsed by Catalyst IT.

Thanks also to the wider Moodle community, whose core APIs (events, scheduled
tasks, forms, and the privacy API) make plugins like this possible.

## Contributors

See the [contributors page](https://github.com/verzog/moodle-tool_automate/graphs/contributors)
for everyone who has contributed to this plugin. Contributions are welcome -
please open an issue or pull request.

## Licence

2026 verzog <verzog@gmail.com>

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program. If not, see <https://www.gnu.org/licenses/>.
