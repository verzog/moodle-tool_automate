# Automate (tool_automate)

A simple, rules-based automation tool for Moodle. Each rule is a **trigger**
(schedule, event, or manual) plus an optional **condition** (who it applies to)
and one or more **actions** (what to do). No graph editor, no expression
language - every rule is a single form.

## Status

Version 0.1.0 (alpha). The first working slice is **"add users to a cohort when
their email matches a pattern"**, which can be triggered on a schedule, when a
user is created, or manually.

## Requirements

* Moodle 4.5+ (PHP 8.1-8.3)

## Install

Copy this folder to `admin/tool/automate` in your Moodle, then visit
*Site administration > Notifications* to complete the install. Manage rules at
*Site administration > Plugins > Admin tools > Automate*.

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

## Credit

The trigger/condition/action design and several action concepts are inspired by
Catalyst IT Australia's `tool_dataflows` (GPL v3). This is an independent plugin
and is not affiliated with or endorsed by Catalyst IT.

## Licence

GNU GPL v3 or later.
