# Changelog

All notable changes to this plugin are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
follows Moodle's `YYYYMMDDXX` version numbering in `version.php`.

## [1.0.2] - 2026-06-30

### Security
- **Privilege escalation through the *assign role* action is closed.** The
  action assigns a role at the system context, and the editor previously
  listed *every* site role while only casting the submitted id to an integer,
  so a holder of `tool/automate:manage` (the Manager archetype by default, not
  necessarily a full site admin) could author a rule that granted Manager — or
  any role carrying `moodle/site:config` — to themselves or every matched user.
  The picker now offers only the roles the configuring user may actually assign
  at the system context (`get_assignable_roles()`), `extract_config()`
  re-validates the submitted role server-side so a crafted POST cannot smuggle
  one in, and `execute()` re-checks the stored role against the rule author's
  assignable set at run time (failing closed) so a rule saved through the old
  picker or by direct database tampering can no longer grant a non-assignable
  role on a later scheduled or manual run.

### Added
- **New `tool/automate:managehighrisk` capability gating the high-risk
  actions.** Configuring **delete course** (irreversible data loss) and
  **assign role** (privilege grant) now requires this capability on top of
  `tool/automate:manage`. It is granted to **no archetype by default** — not
  even Manager — so a site that delegates rule management to a non-admin role
  does not thereby hand out course deletion or role assignment (full site
  admins bypass capability checks and keep access). When these actions are
  hidden from the picker for this reason, the editor says so rather than
  letting them silently vanish. Adds unit coverage for the assignable-role
  gate and the run-time re-validation.

## [1.0.1] - 2026-06-29

### Fixed
- **The course-delete kill-switch now applies to already-queued deletions.**
  The background `delete_course` task re-reads the *Allow course delete* setting
  before deleting, so turning the setting off after queueing the wrong courses
  cancels the pending deletions (matching the restore task's behaviour, and the
  documented kill-switch guarantee).

### Documentation
- Corrected the custom-expression examples to use the `c1`/`c2` condition
  labels the form actually accepts (numeric labels were rejected by the parser).
- Clarified that the course "no activity for N days" condition is based on the
  course record's last-modified time, not learner or teacher activity.
- Corrected the privacy summary: `usermodified` records the most recent editor
  (not the original author), and course-subject runs are logged without an
  individual user id.

## [1.0.0] - 2026-06-29

### Changed
- **First stable release.** Maturity promoted from beta to `MATURITY_STABLE`
  following a full pre-publication security audit (no Critical or High
  findings) and a green test matrix across PHP 8.2/8.3/8.4 and Moodle
  5.0/5.1/5.2 on PostgreSQL and MariaDB. No functional changes since
  0.9.22-beta.

## [0.9.22] - 2026-06-28

### Security
- **CSV formula injection neutralised in generated reports.** Cells in the
  downloaded/emailed user and course report CSVs that begin with `=`, `+`, `-`,
  `@`, a tab or a carriage return are now prefixed with a single quote, so an
  attacker-influenced value (such as a profile field or course name) can no
  longer execute as a formula when the file is opened in a spreadsheet. The
  on-screen report view was already safe.

### Fixed
- **Wildcard `*` match conditions can no longer be made to backtrack
  catastrophically.** The `email/username/name/course name/course idnumber`
  *matches* conditions now use a non-backtracking two-pointer glob matcher
  instead of a compiled regex, so a pathological pattern (for example
  `*a*a*a*…`) cannot stall a cron worker on a large site. Match behaviour is
  unchanged (case-insensitive; no `*` means a substring match).
- **Same-type conditions no longer collide on shared SQL placeholders.** The
  account-age, course-visibility, inactivity and course-no-activity conditions
  used fixed named bind parameters, so two conditions of the same type in one
  rule could produce a wrong filter or a DML error. Each pre-filter now uses a
  per-call unique placeholder.

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

### Changed
- **Selections carried across a search are now shown, not hidden.** Files that a
  new search pushes off-screen render as real, removable checkboxes in a
  *"Selected backups not shown in the current list"* panel, so a Queue can never
  restore a backup the admin could not see — and a crafted `?sel[]` URL can no
  longer smuggle an off-screen selection through a hidden input.
- **Preview keeps the active query and selection.** Previewing now re-renders the
  same filtered view instead of snapping back to the unfiltered first page, so
  the next Queue does not lose the selected backups.
- **The target category survives a search and is explicit.** The chosen category
  is carried across a search reload, and the dropdown gains a *"Choose…"*
  placeholder so a restore can no longer be queued into the first category by
  accident.
- **Source-directory readability badge moved inline.** On the settings page the
  green ✓ *"…readable by Moodle"* (or red ✗) status now sits inline right after
  the field's *Default: Empty* line, emphasised as a tinted pill, rather than
  below the help text.

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
