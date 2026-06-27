# Changelog

All notable changes to this plugin are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows Moodle's `YYYYMMDDXX` version numbering in `version.php`.

## [0.9.17] - 2026-06-27

### Fixed
- **Bulk restore now cleans up its extracted backup on any failure.** The .mbz
  was extracted to a temp directory *before* the try/finally that cleans it up,
  so a failure during extraction itself - most commonly a disk-full "error
  writing to disk" - left the partial extraction behind. Because the adhoc
  queue retries a failed task, every retry orphaned another part-extracted
  backup and made the disk-space problem progressively worse. Extraction and
  shell-course creation now run inside the cleanup block, so a failed attempt
  always reclaims its temp directory (and rolls back the empty shell course).

## [0.9.16] - 2026-06-27

### Changed
- **Bulk restore from repository** is now its own item in the admin menu
  (*Plugins > Admin tools > Automate > Bulk restore from repository*), next to
  Automation rules and Settings, so it is discoverable without first opening the
  rules overview. The button on the overview page remains as a shortcut.

## [0.9.15] - 2026-06-27

### Changed
- The management page and the Settings page are now grouped under a single
  **Automate** category in the admin tree (*Plugins > Admin tools > Automate >
  Automation rules / Settings*) instead of sitting as two unrelated siblings.

### Added
- The **Bulk restore source directory** setting now shows a live readability
  status - a green tick when the configured path is a directory Moodle can
  read, or a red cross when it is missing or unreadable by the web server user.

## [0.9.14] - 2026-06-27

### Added
- **Bulk restore from repository.** A new admin page (*Plugins > Admin tools >
  Automate > Bulk restore from repository*) and CLI
  (`cli/restore_repository.php`) list the Moodle course backup (`.mbz`) files in
  a nominated server directory and restore the selected ones into brand-new
  courses in a chosen category. Existing courses are never touched (restore
  target is always a new course). The restores run in the background via a new
  `restore_course` adhoc task, throttled by a `restore_concurrency` site
  setting so a directory of large backups can't monopolise the cron worker
  pool. The page previews the selection before queueing anything, and the whole
  feature sits behind an off-by-default `allow_bulk_restore` kill-switch.
  Path-safety is centralised in `tool_automate\restore_repository`, which only
  resolves bare `.mbz` basenames that live directly inside the configured
  directory.

## [0.9.13] - 2026-06-24

### Fixed
- **Saving a trigger now returns to the rules overview.** The trigger and logic
  forms were built with a `null` action, so Moodle defaulted their POST target
  to `strip_querystring(qualified_me())` — which drops the `?id=`. The submit
  landed on `edit.php` with `$id = 0`, leaving `$triggerform`/`$logicform` null,
  so the save-and-redirect block silently never ran and the editor just
  re-rendered. Both forms are now anchored to a URL that carries the rule id.
  This is a server-side bug independent of JavaScript, which is why earlier
  fixes that only touched the inline editor JS never resolved it.

### Added
- Behat coverage for the "Save trigger returns to the rules overview" flow,
  both as a plain server-side redirect and as a `@javascript` scenario that
  exercises the AMD editor's submit interceptor in a real browser, so this
  regression fails CI instead of reaching users.

## [0.9.12] - 2026-06-22

### Changed
- The rule editor's inline JavaScript (conditional Step-5 fields, the
  Match/expression toggle, and the AJAX fragment swaps) is now a proper AMD
  module (`amd/src/editor.js`), loaded via `js_call_amd` instead of an inline
  `js_init_code` blob. Behaviour is unchanged; this removes the last large
  block of inline JS ahead of a plugins-directory submission.

## [0.9.11] - 2026-06-22

### Changed
- Maturity raised from alpha to **beta** - the core feature set is in place,
  the recent fatal-on-load and trigger-redirect bugs are fixed, the
  destructive course-delete path has unit tests, and a manual security review
  of the entry points and SQL building came back clean. Stable is held back
  pending the inline-JS to AMD-module refactor and wider testing.

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
