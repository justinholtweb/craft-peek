# Changelog

## 5.1.0 - 2026-10-09
### Added
- Console commands for deploy pipelines: `peek/releases/list`, `peek/releases/status <id>` and `peek/releases/publish <id>`, each with `--json`. `status` exits non-zero for a release that failed or can't be published as it stands; `publish` takes `--dry-run`, and `--as=<user>` to publish with one user's permissions. Without `--as`, a console publish runs as the system and doesn't check permissions — see the README
- `tests/harness/console.php` (15 checks) runs the commands for real in the harness

## 5.0.5 - 2026-10-08

### Fixed
- The diff screen threw an error on entries with a field whose value is an object with no string form, such as SEOmatic’s SEO Settings field (`MetaBundle could not be converted to string`) ([#1](https://github.com/justinholtweb/craft-peek/issues/1)). Such values are now compared as JSON, and a field that still can’t be read is shown as “This field can’t be compared” instead of taking down the screen

## 5.0.4 - 2026-10-02

### Security
- Publishing a release applied every draft in it with no check on the publisher. Anyone with Peek's release permissions could publish any draft on the site — another author's, in a section they couldn't edit — and the entry recorded came from the request, not the draft. Releases now check each draft the way Craft's own "Apply draft" does (save the draft, and save its entry) when a draft is added and again when it is published; if any one can't be applied, nothing is
- Scheduling was a way round that, and round the publish permission: the queue applied a scheduled release with nobody's permissions, and the Schedule releases permission was never checked. Scheduling now needs that permission, and a scheduled release publishes as the user who scheduled it, with their permissions when it runs
- The diff and preview screens showed any draft by ID to anyone with the View diffs permission. They now need the right to view the draft and its entry, and never show a provisional draft (someone's unsaved edits)
- The dashboard listed every draft on the site; it now lists the ones the viewer can see
- A status only the publish sets (publishing, published, failed) can no longer be posted, and a publishing or published release can no longer be edited

### Fixed
- **Saving a release deleted it.** The release screen is one full-page form, and its Remove, Publish and Delete buttons were forms nested inside it. Browsers drop a nested form, so every one of their `action` fields was sent with Save — and the last, `delete`, won. They are now `formsubmit` buttons on the one form
- There was no way to add a draft to a release from the control panel. The release screen now has a picker of the drafts you could publish, and the Peek panel in a draft's sidebar can add it to an open release
- Scheduling a release from the control panel threw an error: Craft's date field posts a date, time and time zone, which `new DateTime()` can't read
- Release dates are stored in UTC, like the rest of Craft, and the scheduler compares in UTC. A migration converts existing dates, so a release already scheduled still fires when intended
- A release the scheduler had marked Publishing stayed there for good if its publish was refused; it is now marked Failed
- One draft whose section no longer exists took down the dashboard
- The sidebar panel built its links from `cpTrigger` by hand, and showed the diff link and releases to people without those permissions
- Settings can no longer be saved where admin changes are off, since they are project config
- Craft confirmed `.formsubmit` buttons and Peek's own script confirmed them again

### Added
- `Releases::unauthorizedEntries()`, `isEditable()`, and a `$user` argument to `publishRelease()`; the draft service methods take an optional viewer
- `tests/integration/AuthorizationTest.php` (14 tests) and `tests/harness/security.php` (17 checks over HTTP as a restricted editor), all mutation-verified

## 5.0.3 - 2026-09-24

### Fixed
- The Peek panel in the entry editor sidebar had no padding, so its rows sat flush against the panel edges. It now uses Craft’s native sidebar markup (`<fieldset>` with an `h6` legend and `Cp::metadataHtml()`) and matches the other sidebar sections

## 5.0.2 - 2026-07-22

### Fixed
- Publishing a release that had already been published could apply unrelated drafts to random entries. Applying a draft deletes it and the `draftId` foreign key is `SET NULL`, so published release entries carry a null draft ID — which `Entry::find()->id()` treated as "no filter" rather than "no match"
- Releases can no longer be published twice; `Published` is now enforced as a terminal status via `ReleaseStatus::isPublishable()`
- `publishRelease()` and `validateRelease()` threw a `TypeError` instead of failing gracefully when passed an unsaved release
- Release entries whose draft has gone missing are now reported by `validateRelease()` and refused by `publishRelease()`, instead of being silently skipped

### Added
- Craft-backed integration test suite running against a real Craft install, covering the releases service, draft discovery, field diffing, plugin event listeners, install migration schema, the scheduler command, and the publish queue job
- `composer test-unit` and `composer test-integration` scripts; `composer test` now runs both suites

## 5.0.1 - 2026-07-19

### Changed
- Expanded automated unit test coverage for the release status state machine, `Release`/`ReleaseEntry`/`Settings` validation, and `DiffService` field-value serialization
- Added a unit-suite bootstrap so model validation rules can be tested without booting a full Craft application

## 5.0.0 - 2026-06-12

### Added
- Field-by-field diff comparison for drafts vs. live content
- Side-by-side visual preview with synchronized scrolling
- Release management for coordinated multi-entry publishing
- Atomic publish — all entries in a release publish together or none do
- Scheduled releases with cron-based automation
- Dashboard with draft overview and release status
- Permissions for diff viewing, release management, and settings
- Entry editor sidebar integration showing diff summary and release membership
