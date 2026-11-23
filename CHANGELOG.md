# Changelog

## 6.0.0-beta.1 - 2026-11-23

Peek for Craft CMS 6, rebuilt as a native Craft 6 plugin. It runs on Laravel and has no dependency on
the Yii 2 adapter. Craft 6 is in beta, and so is this release. Update to Peek 5.1.2 before upgrading
Craft, and see [the upgrade guide](https://github.com/justinholtweb/craft-peek/blob/6.0.0/UPGRADING.md).

### Added

- On Craft 6 Pro and Enterprise, which have releases of their own, Peek works with Craft's releases:
  the draft sidebar adds drafts to them, the dashboard counts them, and the `peek:releases:*`
  commands list, check and publish them (`--source=peek|craft`, `list --all`). Solo and Team keep
  Peek's own releases. One release system per site, so two never apply the same drafts.
- `peek:releases:move-to-craft` moves Peek's unpublished releases into Craft's on Pro and Enterprise,
  with their drafts and schedules. Until then they're under **Peek → Earlier releases**.
- *Compare in Craft* on the diff screen opens Craft 6's own Compare screen for the same draft.
- Content Block fields are compared by what's in them, like Matrix.
- The scheduler check is registered on Laravel's scheduler, every minute, so a site running
  `php artisan schedule:run` needs no cron line of Peek's own.
- `JustinHoltWeb\Peek\Events\PeekReleasePublished`, after one of Peek's releases publishes.

### Changed

- Requires Craft CMS 6 and PHP 8.5.
- Console commands are Artisan commands: `peek:releases:list`, `peek:releases:status`,
  `peek:releases:publish` and `peek:scheduler:check`. The Craft 5 names still work through `php craft`.
- **`-n` is `--no-interaction`, Laravel's meaning, instead of `--dry-run`.** Under the Craft 5 name
  (`php craft peek/releases/publish 12 -n`) it's still a dry run, with a warning, so a 5.x script
  that checked a release doesn't publish it. Use `--dry-run`.
- A missing release or an invalid option exits `2` (was `65`).
- The draft sidebar's rows sit with the draft's details in Craft 6's editor, which has no hook for
  free sidebar HTML. *Add to a release…* opens a small screen to choose the release.
- The release screens, settings and tables use Craft 6's control panel components.
- The config file is `config/craft/peek.php` (was `config/peek.php`); the keys are the same.
- `publish()` returns the reasons a release wasn't published (empty when it was), instead of a
  boolean with errors on the model.

### Removed

- Categories fields: Craft 6 has no categories.

## 5.2.0 - 2026-11-23

### Added
- MCP tools for AI agents, on Craft 5.12.1 and later: `peek_drafts_list`, `peek_draft_diff`, `peek_releases_list`, `peek_release_get`, `peek_release_add_draft` and `peek_release_publish`. They act as the agent's user, with the same checks as the control panel, and publishing through MCP always runs as that user. On older Craft 5 releases Peek works as before
- GraphQL queries `peekDrafts`, `peekDraftDiff`, `peekReleases` and `peekRelease`, behind the new schema options under Peek. Read-only, never on the public schema, and only for entries in sections and sites the schema can read; drafts also need Craft's “Query for element drafts” option
- Peek's control panel nav item can be renamed and given an icon from Craft's icon picker or your own SVG (`navLabel`, `navIcon`, `navIconSvg`), under Peek → Settings or in `config/peek.php`

### Security
- On multi-site installs, the dashboard and the release screen listed drafts in sites the viewer had no access to. They now need the same Edit site permission as Craft's entry editor, like the diff screen since 5.1.2
- A refused publish named every draft the publisher couldn't apply, including ones they couldn't view. Those are now named by entry number
- GraphQL's `peekDraftDiff` shows relation fields (Entries, Categories, Users, Assets and other relation fields, including inside Matrix blocks) as a count, such as “2 related elements”, never the related elements' titles or IDs, since a schema's scopes cover the draft and not what it relates to. Whether the field changed is still decided on the full values. The control panel and MCP diffs are unchanged
- In GraphQL diffs, a Link field pointing at an element shows “1 linked element” instead of the element's URL, any other value that holds elements (arrays, collections, third-party objects) shows only how many, rich text is read from its stored HTML with reference tags removed, never rendered (rendering can pull in other elements), and any other object value — a third-party field's — shows “(not shown over GraphQL)”, so nothing a schema can't read reaches it through a field Peek doesn't know. An array is shown only when it holds nothing but plain values and dates, as Table fields do. Option fields (dropdowns, checkboxes and the like) show their values

## 5.1.2 - 2026-11-23

### Security
- On multi-site installs, the diff and preview screens showed a draft in a site the viewer had no access to. They now need the same Edit site permission as Craft's entry editor

### Fixed
- Matrix fields were compared by block ID, so the diff showed only lines like `(#457038)`, and a block the draft had touched counted as changed even when its content matched the entry's ([#3](https://github.com/justinholtweb/craft-peek/issues/3)). A draft gets its own copy of every block it edits, with a new ID. Blocks are now compared by content: each one is listed by number and entry type, with its title and field values, nested Matrix included
- The draft sidebar's Fields Changed count could differ from the diff screen's ([#3](https://github.com/justinholtweb/craft-peek/issues/3)). The sidebar counted the entry editor's copy of the draft, whose field values can be pre-loaded in a form the diff read differently; it now counts the saved draft, as the diff screen does, and its View Diff link opens the diff in the same site

## 5.1.1 - 2026-10-20

### Fixed
- Visual Preview’s Draft pane showed the live page, so both panes always matched ([#2](https://github.com/justinholtweb/craft-peek/issues/2)). A draft has its entry’s URI, and the pane loaded that URL with no preview token; it now loads the draft through a Craft preview token, the way Craft’s own Preview button does, so `craft.app.request.isPreview` is true there too
- Turning off the Enable Visual Preview setting did nothing. It now hides the Visual Preview tab and the preview screen

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
