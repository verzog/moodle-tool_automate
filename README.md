# Automate (tool_automate)

A simple, rules-based automation tool for Moodle. Each rule is a **trigger**
(schedule, event, or manual) plus an optional **condition** (who it applies to)
and one or more **actions** (what to do). No graph editor, no expression
language - every rule is a single form.

## Status

Version 0.6.0 (alpha). Rules can target either **users** or **courses**.
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

Manage rules at *Site administration > Plugins > Admin tools > Automate*.

## Safety

All actions are **bounded and named** - there is no raw-SQL action. Every rule
has a **Preview (dry run)** option that shows exactly which users would be
affected, and what would happen, without making any change. Every run is logged
to `tool_automate_log`.

## Extending

* Add a condition: create a class in `classes/condition/` extending
  `condition_base`, then register it in `manager::get_condition_types()`.
* Add an action: create a class in `classes/action/` extending `action_base`,
  then register it in `manager::get_action_types()`.

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
