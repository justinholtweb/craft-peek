# Peek — Craft CMS 6 (branch `6.0.0`)

Peek is a content staging and visual diff plugin: field diff, visual preview, a drafts dashboard and
coordinated releases. Single paid edition.

This branch is the native Craft 6 port. **Laravel only: no `craftcms/yii2-adapter`, no `yiisoft/yii2`,
no `craft\`/`yii\` namespaces, no `Craft::`.** `bin/port-check craft-peek` in
`~/Sites/plugin-testing-craft6` enforces it. Craft 5 is `main` (5.1.x), in `~/Sites/craft-peek`; this
branch is the worktree `~/Sites/craft6-ports/craft-peek`. Fixes that apply to both go on `main` first.

- **Package:** `justinholtweb/craft-peek` 6.0.0-beta.1 · **Namespace:** `JustinHoltWeb\Peek` ·
  **Handle:** `peek` · **Class:** `JustinHoltWeb\Peek\Plugin`
- **Craft** `^6.0.0-beta.1` · **PHP** `^8.5` · **Dependency:** `jfcherng/php-diff` ^6.0
- **Schema** 1.0.1 (unchanged from 5.x) · `$minVersionRequired = '5.1.2'`
- Porting plan, cookbook and harness: `~/Sites/plugin-testing-craft6/{PLAN.md,porting/COOKBOOK.md}`

## Layout

```
src/
├── Plugin.php                 # $events, $commands, $styles/$scripts, schedule(), permissions, nav, settingsUi()
├── Console/Commands/          # peek:releases:list|status|publish, peek:scheduler:check, peek:releases:move-to-craft
├── Data/                      # FieldDiff, ReleaseSummary (a release from either system)
├── Database/Tables.php
├── Enums/ReleaseStatus.php
├── Events/PeekReleasePublished.php
├── Http/Controllers/          # Dashboard (invokable), Diff, Releases, DraftReleases (sidebar's "Add to a release…")
├── Jobs/PublishRelease.php
├── Listeners/                 # TrackAppliedDraft, RemoveDiscardedDraft, AddEditorMetadata
├── Models/                    # Release, ReleaseEntry (Eloquent, CraftCms\Cms\Shared\BaseModel), Settings
└── Services/                  # Diff, PendingDrafts, Releases (Peek's own), ReleaseSystem (picks Peek's or Craft's)
routes/cp.php · database/migrations/Install.php · resources/{templates,css,js} · lang/en/peek.php
tests/  Pest on PluginTestCase (real Craft 6 + Peek on in-memory SQLite)
```

## Key decisions

1. **One release system per site.** Craft 6 Pro/Enterprise ship releases (`CraftCms\Cms\Release`);
   Solo/Team don't. `ReleaseSystem::usesCraft()` = `Edition::get()->supportsReleases()`. On Pro the
   sidebar, dashboard and console use Craft's releases; Peek's own rows become "earlier releases":
   publishable and deletable, no new drafts, `peek:releases:move-to-craft` moves them. Chosen by the
   user (2026-11-23) over a straight port or dropping releases.
2. **Peek's diff stays** beside Craft 6's Compare screen (`cp/compare/{id}`), which shows field
   previews, not a word diff. The diff screen links to it.
3. **Tables unchanged from 5.x.** `Install.php` skips existing tables. `draftId` is still nullable
   with ON DELETE SET NULL, and `TrackAppliedDraft` marks rows published on `DraftApplying`, before
   the apply deletes the draft element (Craft 6 still hard-deletes it).
4. **Every publish acts as a person.** `canApply()` = `can('save', $draft) && can('saveCanonical', $draft)`,
   the pair Craft 6's apply-draft action checks. No user = trusted (console system publish, tests).
5. **Draft IDs.** Peek stores the draft *element* ID. Craft's release items store `drafts.id`
   (`$draft->draftId`). `ReleaseSummary::$entries[].draftId` is always the element ID.
6. **Sidebar = `ElementMetadataResolving`.** Craft 6's Inertia editor has no sidebar-HTML hook.
   Values are closures returning HTML, computed only when drawn; the count re-fetches the saved draft.
7. **Diff of container fields.** `ElementContainerFieldInterface` (Matrix, Content Block) is serialized
   by content (`#n Type`, title, field values), always queried fresh from the owner with
   `status(null)`, never by nested-entry ID (5.1.2, issue #3).

## Traps

- **`-n` is `--no-interaction` in Laravel.** 5.x used it for `--dry-run`. Under the 5.x name
  (`peek/releases/publish … -n`, read from raw argv) it stays a dry run, or a 5.x script would publish.
- **Command aliases resolve as `craft:<alias>`.** `php craft peek/scheduler/check` works; in tests call
  `$this->artisan('craft:peek/scheduler/check')`.
- **Craft's releases always publish as a user** (`Releases::publish($release, $actor)`); a console
  publish of one needs `--as`. `checkReadiness()` also needs a user with Manage/Publish releases.
- **Solo allows one user.** Tests run on Team (`TestCase::setUp`). On Team every non-admin shares the
  Team group's permissions; per-user permissions are Pro (`saveUserPermissions` throws on Team).
- **SQLite ignores foreign keys unless asked**, and the pragma is ignored inside a transaction:
  `TestCase::beginDatabaseTransaction()` turns it on first, or SET NULL never fires.
- **Querying UTC dates:** `BaseModel` converts model attributes, but `->where('scheduledDate', '<=', now())`
  doesn't. Use `Query::prepareDateForDb(now())`.
- **Install.php is already loaded** once the plugin is installed; `require` it again and PHP fatals.
- **Opening an outdated draft in Craft's editor saves it** (canonical changes merged), bumping its
  date. Explains "the draft changed but nobody edited it" (issue #3, 2026-11).
- **CP JS loads on every page, in `<head>`, before Vue.** Delegate from `document`; Inertia swaps the
  content. No jQuery, no `Craft.*` helpers.

## Testing

```sh
# In the Craft 6 harness container (PHP 8.5), from the worktree:
docker exec -w /var/www/ports/craft-peek ddev-plugin-testing-craft6-web vendor/bin/pest      # 62 tests
docker exec -w /var/www/ports/craft-peek ddev-plugin-testing-craft6-web vendor/bin/pint --test
docker exec -w /var/www/ports/craft-peek ddev-plugin-testing-craft6-web vendor/bin/phpstan analyse --memory-limit=1G   # level 6
~/Sites/plugin-testing-craft6/bin/port-check craft-peek
```

Pest runs a real Craft 6 install with Peek on in-memory SQLite, so it doesn't touch the shared
harness database. Live checks in the harness (`bin/cp-session` + curl, `php craft peek/...`): the
harness is Craft **Pro**, so it exercises the Craft-releases path; make your own content and delete it.
