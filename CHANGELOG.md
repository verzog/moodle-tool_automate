# Changelog

All notable changes to this plugin are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows Moodle's `YYYYMMDDXX` version numbering in `version.php`.

## [0.9.21] - 2026-06-28

### Added
- **Bulk restore now searches and caps the backup list server-side.** The file
  picker previously rendered *every* `.mbz` in the source directory into one
  table, so a directory of thousands bloated the page and the instant filter
  could only reach rows already on screen. Typing still filters the rendered
  rows instantly, but pressing Enter (or the new **Search** button) now runs the
  query server-side across the whole directory and renders at most the first 200
  matches, with a *"Showing the first 200 of N…"* notice when the result is
  capped. A selection survives a search: files ticked under one query are
  carried along and still queue after searching again. A **Clear** link resets
  the query.

## [0.9.20] - 2026-06-28

### Changed
- **Polish on the bulk-restore page.** The *Source directory* now reads as a
  framed, rounded panel with a tinted label strip and the resolved path on its
  own line, matching the look of the listing tables instead of a plain inline
  sentence. The *Target category* dropdown is wider and full-width within its
  field, so it lines up with the table above rather than sitting in the cramped
  default picker width. The backup list scrolls inside a fixed-height pane with
  its header pinned, so a directory of many `.mbz` files stays a compact,
  scannable list. Purely cosmetic and scoped to the plugin's admin pages.

## [0.9.18] - 2026-06-27

### Added
- **The bulk-restore file picker is now searchable.** The backup list is a
  core `autocomplete` element, so an admin can type to filter a directory of
  dozens or hundreds of `.mbz` files instead of scrolling a long listbox.
  Selected files show as removable tags and submit exactly as before.

### Changed
- **Light visual polish on the plugin's admin pages.** A scoped `styles.css`
  (applied via a `tool_automate-page` body class) gives the listing tables a
  framed, rounded look with a tinted header and row hover that scrolls on small
  screens, softer rounded borders and a focus ring on dropdowns and inputs, and
  tidier readability-badge and queued-file chips on the restore page. Purely
  cosmetic and scoped so nothing leaks into the rest of Moodle.

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
