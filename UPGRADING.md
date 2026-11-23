# Upgrading Peek from Craft 5 to Craft 6

Peek 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2 adapter.
Your releases, their drafts and your settings carry over: Peek's tables are unchanged.

> **Beta.** Peek 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site before
> relying on it in production. Peek 5 remains the stable release for Craft 5.

## Before you start

| | Peek 5.x | Peek 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.0.0+ | 6.0.0-beta.1+ |
| PHP | 8.2+ | 8.5+ |
| Install command | `php craft plugin/install peek` | `php artisan craft:plugin:install peek` |
| Console commands | `php craft peek/releases/publish 12` | `php artisan peek:releases:publish 12` (old names still work) |

1. **Update to Peek 5.1.2 first**, while the site is still on Craft 5:

   ```bash
   composer require justinholtweb/craft-peek:^5.1.2
   php craft up
   ```

   Peek 6 won't install over anything older than 5.1.2.

2. **Publish or cancel what's mid-flight.** A release in the *Publishing* state when you upgrade
   stays there; publish it on Craft 5 first. Releases waiting for a date are fine (see
   [Scheduling](#scheduling)).

3. **Check your Craft edition.** On Craft 6 Pro and Enterprise, releases are Craft's own (see
   [Releases on Pro and Enterprise](#releases-on-pro-and-enterprise)). On Solo and Team, Peek's
   releases carry on as they were.

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), and require Peek 6
at the same time as Craft:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-peek:^6.0@beta" -W
php artisan craft:up
```

Drop the `@beta` flags once Craft 6 and Peek 6 have stable releases.

## What carries over

- **Releases and their drafts.** `peek_releases` and `peek_release_entries` keep their rows and
  schema. Statuses, schedules (stored in UTC), who scheduled what, and what was already published.
- **Settings.** Same keys in project config: the stale-draft threshold, default site, visual preview
  and the per-release limit.
- **Config file.** Move `config/peek.php` to `config/craft/peek.php`. The keys are the same.
- **Permissions.** Same names (`peek:accessPlugin`, `peek:viewDiffs`, `peek:manageReleases` and the
  rest), so user and group permissions carry over.
- **Console command names.** `php craft peek/releases/list`, `…/status`, `…/publish` and
  `peek/scheduler/check` still work. The new names are `peek:releases:list` and so on.

## What changed

### Releases on Pro and Enterprise

Craft 6 Pro and Enterprise have releases built in (**Releases** in the CP): grouped drafts, scheduled
publishing, readiness checks, and Workflows. Solo and Team don't. So Peek uses one release system per
site:

- **Solo and Team:** Peek's releases, exactly as on Craft 5.
- **Pro and Enterprise:** Craft's releases. The draft sidebar's *Add to a release…*, the dashboard's
  count and the `peek:releases:*` commands work with Craft's releases. Peek's nav links to Craft's
  Releases screen.

Releases you made in Peek on Craft 5 are still there on Pro, under **Peek → Earlier releases**. They
can be published or deleted, and a scheduled one still publishes on time, but none of them take new
drafts. To move them into Craft's releases:

```bash
php artisan peek:releases:move-to-craft --dry-run
php artisan peek:releases:move-to-craft --as=<username>
```

`--as` names the user the Craft releases are made by. They need Craft's *Manage releases* permission,
and the right to apply each draft. Craft lets a draft be in only one release; a draft that was in two
Peek releases moves with the first, and the command lists what stayed behind.

Who can do what on Pro is then Craft's *Manage releases* and *Publish releases* permissions, not
Peek's release permissions.

### The draft sidebar

Craft 6's entry editor has no hook for free HTML in its sidebar. Peek's rows (*Fields changed*,
*Diff*, *Releases*) now sit with the draft's details (ID, status, dates). *Add to a release…* opens a
small screen to pick the release, instead of a picker in the sidebar.

### Scheduling

Peek registers `peek:scheduler:check` on Laravel's scheduler, every minute. If the site runs
`php artisan schedule:run` from cron (or `schedule:work`), you can remove Peek's own cron line. If it
doesn't, keep the line you have: `php craft peek/scheduler/check` still works. The release is published
by a queued job, as before, so keep a worker running: `php artisan queue:work`.

On Pro and Enterprise, Craft schedules its own releases.

### Console commands

- Artisan names: `peek:releases:list`, `peek:releases:status`, `peek:releases:publish`,
  `peek:scheduler:check`, and new `peek:releases:move-to-craft`.
- **`-n` means `--no-interaction` now, not `--dry-run`.** That's Laravel's meaning, and it can't be
  reused. So that a Craft 5 script that checks a release with `php craft peek/releases/publish 12 -n`
  doesn't publish it, `-n` under the old name is still a dry run (and prints a warning). Update
  scripts to `--dry-run`.
- `--interactive=0` is now `--no-interaction`.
- Exit codes: a missing release or invalid option exits `2` (was `65`). `0` and `1` are unchanged.
- `--source=peek|craft` picks the release system on Pro and Enterprise; `list --all` shows both.
- Publishing one of Craft's releases needs `--as=<username>`: Craft always publishes as a person.

### Diff

- **Compare in Craft.** The diff screen links to Craft 6's own Compare screen for the same draft,
  which shows each field's preview.
- **Content Block fields** are compared by content, like Matrix (Peek 5 compared them as JSON).
- **Categories and Tags** fields are gone from Craft 6 (they became entries in Craft 5's
  `entrify` commands), so there's nothing Category-specific left in the diff.

### For developers

| Peek 5 | Peek 6 |
| --- | --- |
| `justinholtweb\peek\Plugin::getInstance()->releases` | `app(JustinHoltWeb\Peek\Services\Releases::class)` (or inject it) |
| `->diff` (`DiffService`) | `JustinHoltWeb\Peek\Services\Diff` |
| `->drafts` (`DraftService`) | `JustinHoltWeb\Peek\Services\PendingDrafts` |
| `models\Release`, `records\ReleaseRecord` | `JustinHoltWeb\Peek\Models\Release` (Eloquent) |
| `models\ReleaseEntry`, `records\ReleaseEntryRecord` | `JustinHoltWeb\Peek\Models\ReleaseEntry` (Eloquent) |
| `queue\jobs\PublishReleaseJob` | `JustinHoltWeb\Peek\Jobs\PublishRelease` |
| `publishRelease($release, $user): bool`, errors on the model | `publish($release, $user): array` (empty when published, else the reasons) |
| — | `JustinHoltWeb\Peek\Events\PeekReleasePublished` |

## Downgrading

Peek's tables are the same on both, so a site rolled back to Craft 5 and Peek 5.1.2 from a backup
taken before the upgrade carries on as before. Releases moved into Craft's with `move-to-craft` aren't
in Peek's tables any more.
