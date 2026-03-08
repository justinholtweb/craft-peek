# Peek — Development Log

## Project Summary

Peek is a content staging and visual diff plugin for Craft CMS 5. Single paid edition. Three core features — Field Diff, Releases, and Dashboard — give editorial teams coordinated multi-entry publishing with side-by-side comparison.

**Namespace:** `justinholtweb\peek`
**Craft CMS:** ^5.0.0 | **PHP:** ^8.2
**Dependencies:** `jfcherng/php-diff` ^6.0
**Pattern source:** `/Users/jholt/Sites/craft-garrison/` (Plugin.php, Migration, Enum, Record patterns)

---

## Phase 1: Plugin Skeleton & Database — COMPLETE

### What was built

**Plugin shell & configuration**
- `Plugin.php` — registers 3 service components (`releases`, `diff`, `drafts`), CP routes, permissions, event listeners. Has CP section with subnav (Dashboard, Releases, Settings).
- `composer.json` — full Plugin Store metadata including support section, changelogUrl, developerEmail.
- `config.php` — default multi-env config with all settings documented.
- `icon.svg` — magnifying glass with diff lines. Uses `currentColor` for light/dark theme support.

**1 PHP 8.2 enum**
- `ReleaseStatus` (draft/ready/scheduled/publishing/published/failed) — with `label()`, `color()`, `canTransitionTo()` methods

**4 models**
- `Settings` — staleDraftDays, defaultSiteId, enableVisualPreview, maxEntriesPerRelease with Yii2 validation
- `Release` — id, siteId, name, description, status, scheduledDate, publishedDate, publishedBy, createdBy, private `_entries` array with getEntries()/setEntries()/getEntryCount()
- `ReleaseEntry` — id, releaseId, canonicalId, draftId (nullable), sortOrder, status (pending/published/failed), errorMessage
- `FieldDiff` — handle, label, type, oldValue, newValue, diffHtml, hasChanges

**2 ActiveRecord classes**
- `ReleaseRecord` — `{{%peek_releases}}`
- `ReleaseEntryRecord` — `{{%peek_release_entries}}`

**Install migration**
- Creates 2 tables with indexes and foreign keys
- `draftId` FK uses **SET NULL** (not CASCADE) — critical because `applyDraft()` deletes the draft element, and release_entry rows must survive
- Unique index on `[releaseId, draftId]` prevents duplicate entries

**CSS/JS assets**
- `CpAsset.php` + `peek.css` — dashboard cards, summary stats, release actions, status badges, toolbar layout
- `peek.js` — tab switching, confirm dialogs
- `DiffAsset.php` + `diff.css` — field diff blocks (expand/collapse), SideBySide table styling, simple diff, boolean diff, visual preview iframes
- `diff.js` — tab switching between Field Diff and Visual Preview, synchronized iframe scrolling

**Translations**
- `translations/en/peek.php` — 70+ strings covering all UI text

---

## Phase 2: Draft Service & Dashboard — COMPLETE

### What was built

**DraftService** — queries pending drafts site-wide:
- `getAllPendingDrafts(?siteId)` — `Entry::find()->drafts(true)->provisionalDrafts(false)->draftOf('*')`
- `getDraftCountsBySection(?siteId)` — groups drafts by section name, sorted descending
- `getStaleDrafts(days, ?siteId)` — drafts not updated in N days

**DashboardController** — renders dashboard with:
- Summary cards: pending draft count, stale draft count, active release count
- Drafts by section table
- Pending drafts table: title (linked to CP edit), section, author (via `draft.getCreator().friendlyName`), last updated date, Diff button

---

## Phase 3: Diff Engine (Field + Preview) — COMPLETE

### What was built

**DiffService** — field-by-field comparison using `jfcherng/php-diff`:
- `diffEntry(draft, canonical)` → array of FieldDiff objects
- Compares native attributes: title, slug, enabled (boolean diff)
- Custom field handling by type:
  - PlainText — direct string diff
  - CKEditor/rich text — `strip_tags()` then diff
  - Lightswitch — boolean "On/Off" diff rendering
  - Date — `Y-m-d H:i:s` format diff
  - Color, Email, Link — string diff
  - Entries/Categories/Users relations — `"Title (#id)"` per line
  - Assets — `"filename.ext (#id)"` per line
  - Matrix/arrays — JSON pretty-print diff
- `getPreviewUrls(draft, canonical)` — returns URLs for iframe preview
- `_renderTextDiff()` — `DiffHelper::calculate()` with SideBySide renderer, word-level detail
- `_renderBooleanDiff()` — styled On/Off with red→green transition

**DiffController** — `actionView(draftId)` and `actionPreview(draftId)`:
- Loads draft via `Entry::find()->id()->drafts(true)->status(null)->one()`
- Gets canonical via `$draft->getCanonical()`
- Renders field diffs and preview URLs

**Templates:**
- `diff/_view.twig` — header (title, draft ID, changed count, edit link), tab toggle (Field Diff / Visual Preview), field diff list, dual iframe preview
- `diff/_field-diff.twig` — individual field block (collapsible, changed/unchanged badge, diff HTML or simple diff fallback)
- `diff/_preview.twig` — standalone preview page with two iframes

---

## Phase 4: Release Management — COMPLETE

### What was built

**Releases service** — full CRUD + atomic publish:
- `getReleaseById()`, `getAllReleases(?siteId)`, `saveRelease()`, `deleteRelease()`
- `addEntryToRelease(releaseId, canonicalId, draftId)` — with duplicate check and maxEntriesPerRelease limit
- `removeEntryFromRelease(releaseId, draftId)`
- `validateRelease(release)` — validates all drafts/canonicals still exist
- `publishRelease(release)` — DB transaction wrapping `Craft::$app->getDrafts()->applyDraft()` for each entry. All succeed or none do. Marks entries as published, sets release status to Published with timestamp.
- `getReleaseEntryRecordsByDraftId()`, `markEntryPublishedByDraftId()`, `removeEntryByDraftId()`, `getReleasesForDraft()` — helper methods for event listener integration

**ReleasesController** — full HTTP CRUD:
- `actionIndex()` — lists all releases
- `actionEdit(?releaseId)` — create/edit form with resolved entry details (titles, sections, status badges)
- `actionSave()` — creates or updates release from POST
- `actionPublish()` — triggers atomic publish (requires `peek:publishReleases` permission)
- `actionDelete()` — deletes release (requires `peek:deleteReleases`)
- `actionAddEntry()`, `actionRemoveEntry()` — manages release entries via POST

**Templates:**
- `releases/_index.twig` — table with name, status (color badge), entry count, scheduled date, created date. "New Release" button, empty state.
- `releases/_edit.twig` — form with name, description, status dropdown, scheduled date. Entries table showing resolved titles with CP edit links, section names, status badges (green=published, red=failed, grey=pending), Diff and Remove buttons. Publish Now / Delete actions. "Published on {date}" info for completed releases.

---

## Phase 5: Scheduling & Queue — COMPLETE

### What was built

**PublishReleaseJob** — extends `BaseJob`:
- Takes `releaseId`, calls `publishRelease()`, throws `RuntimeException` on failure for queue retry
- `defaultDescription()` for queue UI

**SchedulerController** — console command `peek/scheduler/check`:
- Queries `ReleaseRecord` for `status=scheduled` with `scheduledDate <= now`
- Pushes `PublishReleaseJob` to Craft queue for each
- Marks release as "publishing" to prevent re-pickup
- Designed for cron: `* * * * * /path/to/craft peek/scheduler/check`

---

## Phase 6: Entry Editor Integration & Events — COMPLETE

### What was built

**3 event listeners in Plugin.php:**

1. `Drafts::EVENT_BEFORE_APPLY_DRAFT` — marks release entries as published by draftId and tracks the ID in `_applyingDraftIds[]`. Must happen BEFORE apply because the FK SET NULL clears draftId during the draft element deletion.

2. `Drafts::EVENT_AFTER_APPLY_DRAFT` — cleans up `_applyingDraftIds[]` tracking array.

3. `Elements::EVENT_BEFORE_DELETE_ELEMENT` — when a draft Entry is truly deleted (not applied), removes it from all releases. Skips if the draft ID is in `_applyingDraftIds[]` (being applied, not deleted).

**Sidebar HTML injection:**
- `Entry::EVENT_DEFINE_SIDEBAR_HTML` — on non-provisional draft entries in CP
- `_renderPeekSidebar(Entry $draft)` — shows:
  - Fields Changed count (via `diffEntry()`)
  - "View Diff" link to `peek/diff/{draftId}`
  - Release membership with links and status dots

### Critical design decision

**FK SET NULL pattern:** When `applyDraft()` is called, Craft internally deletes the draft element. If draftId FK used CASCADE, the `peek_release_entries` row would be deleted at the DB level before any PHP event could mark it as "published." Solution: FK uses SET NULL, draftId is nullable, and the BEFORE_APPLY event marks the entry before deletion occurs.

---

## Phase 7: Polish & Store Prep — COMPLETE

### What was done

- **CSS polish** — auto-collapsed unchanged fields, diff block borders, status badges, release entry layout
- **Error handling** — maxEntriesPerRelease validation, enriched release edit with resolved entry titles/sections
- **Translations** — all 70+ strings in `en/peek.php`
- **Package files** — composer.json with full Plugin Store metadata, README.md, CHANGELOG.md, LICENSE.md (proprietary)
- **Smoke tests** — all 6 CP pages verified (Dashboard, Releases index, New Release, Settings, Diff view, Release edit)

---

## CP Routes

```
peek                              → dashboard/index
peek/releases                     → releases/index
peek/releases/new                 → releases/edit
peek/releases/<releaseId:\d+>     → releases/edit
peek/diff/<draftId:\d+>           → diff/view
peek/diff/<draftId:\d+>/preview   → diff/preview
peek/settings                     → settings/index
```

## Permissions

```
peek:accessPlugin — top-level access
  ├── peek:viewDiffs
  └── peek:manageReleases
        ├── peek:publishReleases
        ├── peek:scheduleReleases
        └── peek:deleteReleases
peek:manageSettings
```

## Database Tables

### `{{%peek_releases}}`
id, siteId (FK→sites CASCADE), name, description, status, scheduledDate, publishedDate, publishedBy (FK→users SET NULL), createdBy (FK→users SET NULL), dateCreated, dateUpdated, uid

### `{{%peek_release_entries}}`
id, releaseId (FK→releases CASCADE), canonicalId (FK→elements CASCADE), draftId (FK→elements **SET NULL**), sortOrder, status, errorMessage, dateCreated, dateUpdated, uid

Indexes: status + scheduledDate + siteId on releases; releaseId + unique [releaseId, draftId] on release_entries

---

## File Inventory (40 source files)

```
src/
├── Plugin.php
├── config.php
├── icon.svg
├── enums/
│   └── ReleaseStatus.php
├── models/
│   ├── Settings.php
│   ├── Release.php
│   ├── ReleaseEntry.php
│   └── FieldDiff.php
├── records/
│   ├── ReleaseRecord.php
│   └── ReleaseEntryRecord.php
├── services/
│   ├── Releases.php
│   ├── DiffService.php
│   └── DraftService.php
├── controllers/
│   ├── DashboardController.php
│   ├── ReleasesController.php
│   ├── DiffController.php
│   └── SettingsController.php
├── console/controllers/
│   └── SchedulerController.php
├── queue/jobs/
│   └── PublishReleaseJob.php
├── migrations/
│   └── Install.php
├── templates/
│   ├── _layouts/plugin.twig
│   ├── dashboard/_index.twig
│   ├── releases/_index.twig
│   ├── releases/_edit.twig
│   ├── diff/_view.twig
│   ├── diff/_field-diff.twig
│   ├── diff/_preview.twig
│   └── settings/_index.twig
├── translations/en/
│   └── peek.php
└── web/assets/
    ├── cp/
    │   ├── CpAsset.php
    │   └── dist/
    │       ├── css/peek.css
    │       └── js/peek.js
    └── diff/
        ├── DiffAsset.php
        └── dist/
            ├── css/diff.css
            └── js/diff.js
```

---

## Key Technical Decisions

1. **Atomic publish via DB transaction** — all drafts in a release are applied within a single `beginTransaction()`/`commit()`. If any draft fails, the entire transaction rolls back and the release is marked "failed."

2. **FK SET NULL on draftId** — `applyDraft()` deletes the draft element at the DB level. CASCADE would destroy the release_entry row. SET NULL preserves it, and the BEFORE_APPLY event marks it "published" before deletion.

3. **`_applyingDraftIds` guard** — prevents the `BEFORE_DELETE_ELEMENT` handler from removing release entries for drafts being applied (as opposed to drafts genuinely deleted by the user).

4. **`jfcherng/php-diff` SideBySide renderer** — word-level diff with 3 lines of context. CKEditor content is stripped of HTML before diffing. Relations are serialized to `"Title (#id)"` per line.

5. **Cron-based scheduling** — `peek/scheduler/check` runs every minute, queries overdue scheduled releases, pushes `PublishReleaseJob` to Craft's queue. The job is processed by `craft queue/run` or Craft's queue listener.
