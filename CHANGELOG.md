# Changelog

All notable changes to this plugin are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows Moodle's `YYYYMMDDXX` version numbering in `version.php`.

## [0.9.10] - 2026-06-22

### Added
- Developer documentation: this changelog, plus unit tests for the rule
  scheduler (`run_rules::is_due`), the `course_delete` action's safety gating,
  and the privacy provider.

### Changed
- README no longer hard-codes a release number in its status line
  (`version.php` is the single source of truth).

## [0.9.9] - 2026-06-22

### Added
- The `run_rules` scheduled task and the `delete_course` adhoc task now narrate
  their progress to *Server > Tasks > Task logs* (which rule ran, which course
  was deleted), so a run can be confirmed from the task log.

## [0.9.8] - 2026-06-22

### Fixed
- Saving a rule's trigger now reliably returns to the rules overview. The
  trigger form submits as a full-page POST instead of being caught by the
  inline fetch interceptor, which could not follow Moodle's off-page redirect
  when developer debugging rendered an HTML redirect page instead of a 302.

## [0.9.7] - 2026-06-22

### Fixed
- Fatal error on load (`Cannot override final method
  core\task\adhoc_task::get_concurrency_limit()`). The per-task concurrency cap
  now overrides the protected `get_default_concurrency_limit()` instead of the
  method core made `final` in Moodle 5.x.

## [0.9.0] - 0.9.6 - 2026-06

### Added
- Rules-based automation: each rule is a trigger (schedule, event, or manual)
  plus optional conditions and one or more bounded, named actions.
- Rules can target **users** or **courses**, with match / does-not-match
  conditions and `all` / `any` / expression logic.
- Course actions including move-to-category, set visibility, email teachers,
  generate report, and a guarded **delete course** action (site kill-switch,
  typed confirmation phrase, optional hide-on-queue, optional admin
  notification, and scheduled background deletion throttled via an adhoc-task
  concurrency cap).
- Preview (dry run) for every rule, run history (`tool_automate_log`), saved
  report viewer, and a Privacy API provider.

[0.9.10]: https://github.com/verzog/moodle-tool_automate/releases
[0.9.9]: https://github.com/verzog/moodle-tool_automate/releases
[0.9.8]: https://github.com/verzog/moodle-tool_automate/releases
[0.9.7]: https://github.com/verzog/moodle-tool_automate/releases
