# Peek — Content Staging & Visual Diff for Craft CMS 6

Peek gives editorial teams a word-level diff of every draft, a side-by-side visual preview, a
dashboard of what's waiting to publish, and coordinated releases of several drafts at once.

This is the `6.0.0` branch: a native Craft 6 plugin, built on Laravel, with no dependency on the
Yii 2 adapter. **Craft 6 is in beta, and so is Peek 6.** Peek 5 (the `main` branch) is the stable
release for Craft 5. Upgrading from Craft 5? Read [UPGRADING.md](UPGRADING.md).

## Features

- **Field diff.** Every field of a draft compared with its live entry, word by word, side by side.
  Matrix and Content Block fields are compared by what's in their nested entries.
- **Visual preview.** The live page and the draft in two panes that scroll together.
- **Dashboard.** Every saved draft you could open, the stale ones, drafts by section, open releases.
- **Draft sidebar.** In the entry editor: how many fields a draft changes, a link to its diff, and
  the releases it's in, with *Add to a release…*.
- **Releases.** Group drafts and publish them all at once, or none of them. Schedule them. Publish
  them from the command line in a deploy.
- **AI agents (MCP).** Tools on Craft's own MCP server: list drafts, read a draft's diff, list,
  fill and publish releases, always as the signed-in user.
- **GraphQL.** `peekDrafts`, `peekDraftDiff`, `peekReleases`, `peekRelease`, behind schema scopes.
- **Notifications and hooks.** A failed scheduled release lands in the CP's notifications, email and
  Slack; a published release POSTs a signed webhook (CDN purge, static rebuild, deploy hook).
- **Craft dashboard widget** and a **live Peek dashboard** (Laravel broadcasting, or polling).
- **Your own sidebar label and icon.**

## Releases on each Craft edition

Craft 6 Pro and Enterprise have releases built in. Solo and Team don't. Peek uses one release
system per site, so two systems never try to apply the same drafts:

| | Craft Solo, Team | Craft Pro, Enterprise |
| --- | --- | --- |
| Release system | Peek's own | Craft's own (**Releases** in the CP) |
| Where you manage releases | Peek → Releases | Craft's Releases screen (Peek's nav links there) |
| Draft sidebar, *Add to a release…* | Peek's releases | Craft's releases |
| Dashboard's *Active releases* | Peek's | Craft's, plus any of Peek's not yet finished |
| `peek:releases:*` commands | Peek's | Craft's (`--source=peek` for Peek's) |
| Permissions | Peek's *Manage/Publish/Schedule/Delete releases* | Craft's *Manage releases*, *Publish releases* |
| Scheduling | Peek's scheduler (below) | Craft's |

On Pro and Enterprise, releases made in Peek before (an upgraded Craft 5 site, or a Team site that
moved up) can still be opened, published or deleted under Peek → Earlier releases (shown while there
are any), but take no new drafts.
Move them into Craft's releases with:

```bash
php artisan peek:releases:move-to-craft --dry-run        # what would move
php artisan peek:releases:move-to-craft --as=jane        # move them, as a user with Craft's Manage releases
```

Each becomes a Craft release with the same name, description and drafts, scheduled again for the same
time if it was scheduled. Craft lets a draft be in only one release, so a draft that was in two Peek
releases moves with the first; the other keeps it and the command says so.

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5 or later

## Installation

```bash
composer require "justinholtweb/craft-peek:^6.0@beta"
php artisan craft:plugin:install peek
```

## Field diff and Craft's Compare screen

Craft 6 has a Compare screen of its own, which shows each field's preview side by side. Peek's diff
is the text: it marks exactly which words changed, collapses the fields that didn't, and covers
native attributes (title, slug, enabled) too. Peek's diff screen links to Craft's Compare screen
(*Compare in Craft*) for the same draft.

How each kind of field is compared:

| Field | Compared as |
| --- | --- |
| Plain Text, Email, Color, Link, Number | The value |
| CKEditor and other rich text | The text, without the markup |
| Lightswitch | On / Off |
| Date | `Y-m-d H:i:s` |
| Entries, Users and other relations | `Title (#id)`, one per line |
| Assets | `filename.ext (#id)`, one per line |
| Matrix, Content Block | Each nested entry as `#n Type`, its title and field values, nested Matrix included |
| Anything else | JSON |

A field Peek can't read shows *This field can't be compared* instead of breaking the screen.

## Scheduling (Peek's own releases)

Peek registers its check on Laravel's scheduler, every minute. If the site runs the scheduler,
there's nothing to set up:

```bash
* * * * * cd /path/to/site && php artisan schedule:run >> /dev/null 2>&1
```

If it doesn't, run Peek's check from cron yourself, as on Craft 5 (the old command name still works):

```bash
* * * * * cd /path/to/site && php craft peek/scheduler/check
```

Due releases are queued (`PublishRelease`), so a queue worker has to be running too:
`php artisan queue:work`. Craft's own releases (Pro, Enterprise) are scheduled by Craft.

## Console commands

```bash
php artisan peek:releases:list                       # newest first
php artisan peek:releases:list --status=ready --site=default --json
php artisan peek:releases:status 12                  # one release, its drafts, anything stopping it
php artisan peek:releases:publish 12 --dry-run       # check it could publish, apply nothing
php artisan peek:releases:publish 12 --no-interaction
php artisan peek:releases:publish 12 --as=jane@example.com
php artisan peek:scheduler:check
php artisan peek:releases:move-to-craft --as=jane    # Pro and Enterprise
```

- `list`, `status` and `publish` take `--json` for machine-readable output.
- `--source=peek|craft` picks the release system; it defaults to the site's. `list --all` shows both.
- `status` exits `0` for a release that's fine and `1` for one that failed or can't publish as it
  stands. `publish` exits `1` if nothing was published. A missing release or bad option exits `2`.
- `publish` applies every draft or none, won't touch a published release, and won't race the queue
  for one the scheduler has already handed over. Without `--no-interaction` it asks first.
- The Craft 5 names still work through `php craft`: `peek/releases/list`, `peek/releases/status`,
  `peek/releases/publish`, `peek/scheduler/check`.

> **Peek's releases publish as the system from the console.** There's no signed-in user, so a Peek
> release's drafts are applied without a permission check: whoever can run artisan can already
> change anything. Pass `--as=<username or email>` to hold the publish to that user's rights.
> **Craft's releases always publish as a user**, so on Pro and Enterprise `publish` needs `--as`.

> **`-n` is `--no-interaction` in Craft 6.** In Peek 5 it meant `--dry-run`. So a 5.x script doesn't
> publish by accident, `php craft peek/releases/publish 12 -n` (the Craft 5 name) is still a dry run,
> and says so. Under the new name, `peek:releases:publish 12 -n` publishes. Write `--dry-run`.

## MCP (AI agents)

Peek adds six tools to Craft's MCP server. Turn on Craft's MCP server as Craft's docs describe, or
run it locally over stdio with `php artisan craft:mcp:serve --user=<username>`.

| Tool | Does | Needs |
| --- | --- | --- |
| `peek_drafts_list` | Drafts you can open, newest first; `staleOnly`, `siteId`, `limit`, `offset` | Access Peek |
| `peek_draft_diff` | One draft compared with its entry, field by field, as text | View diffs |
| `peek_releases_list` | Releases (`status` filter) | Manage releases (Peek's, or Craft's on Pro) |
| `peek_release_get` | One release, its drafts, and what would stop it publishing | Manage releases |
| `peek_release_add_draft` | Adds a draft you could publish yourself | Manage releases |
| `peek_release_publish` | Publishes now, or `dryRun` | Publish releases |

Every tool acts as the MCP user, with the control panel's checks: an agent sees only the drafts its
user could open, and publishes only what its user could. `peek_release_publish` never publishes as
the system. The read-only tools need the token's `mcp:read` scope; adding and publishing need
`mcp:write`. On Pro and Enterprise, release tools take `source: "peek"|"craft"`.

The same tools, with the same names, are in Peek 5.2 for Craft 5.12.1+.

## GraphQL

Enable under **Settings → GraphQL → Schemas → Peek**:

| Scope | Queries |
| --- | --- |
| Query drafts and their diffs | `peekDrafts(siteId, staleOnly, limit, offset)`, `peekDraftDiff(draftId!, siteId, includeUnchanged)` |
| Query releases | `peekReleases(status)`, `peekRelease(id!, source)` |

```graphql
{
  peekDraftDiff(draftId: 1234) {
    changedCount
    draft { title draftName stale }
    fields { handle label oldValue newValue }
  }
}
```

Drafts are unpublished content, so:

- Nothing resolves on the **public** schema, whatever its scopes.
- The draft queries also need the schema's **Query for element drafts** option.
- Only entries in sections and sites the schema can read are returned, and a release's entries
  (and its `entryCount`) are filtered the same way. On Pro and Enterprise, Craft's releases also need
  the schema's release option.
- Related elements in a diff (relation fields, also inside Matrix) are shown only as a count, such
  as `1 related element`: they may be outside the schema's scopes. Links to an element show as
  `1 linked element`, and third-party field values Peek can't see into as `(not shown over
  GraphQL)`. `changed` still reflects all of them.
- It's read-only: there are no mutations.

## Notifications and the publish webhook

Settings → Plugins → Peek → Releases:

- **Tell people when a scheduled release fails.** One of Peek's scheduled releases that doesn't
  publish (a draft can't be applied, or its scheduler lost the right to) notifies the person who
  scheduled it in the CP's notifications and by email, plus any **Also email** addresses and a
  **Slack webhook URL**. Sent once, after the queue's last attempt. Craft notifies about its own
  releases.
- **Publish webhook URL.** When any release publishes, Peek's or Craft's, Peek POSTs this JSON from
  the queue (retried with backoff):

  ```json
  {
    "event": "release.published",
    "release": {"source": "peek", "id": 12, "name": "Spring launch", "status": "published", "site": "default", "entryCount": 3, "scheduledDate": null, "publishedDate": "2026-11-23T15:00:00+00:00"},
    "entries": [{"id": 101, "uri": "news/spring", "url": "https://example.com/news/spring"}],
    "sentAt": "2026-11-23T15:00:01+00:00"
  }
  ```

  With a **secret**, the body is signed: `X-Peek-Signature: sha256=<hex HMAC-SHA256 of the raw body>`.
  Verify it before acting on the request.

URLs, addresses and the secret accept environment variables (`$PEEK_PUBLISH_WEBHOOK`); keep secrets
in `.env`.

## Dashboards

- **Craft's dashboard:** add the **Drafts waiting** widget: counts of drafts, stale drafts and open
  releases, and the latest (or stalest) drafts with links to their diffs.
- **Peek's dashboard refreshes itself.** If the site has Laravel broadcasting set up (Reverb, Pusher
  or Ably, with `Broadcast::routes()` and Laravel Echo on CP pages as `window.Echo`), it updates the
  moment a draft is saved or a release publishes, on the private `peek.dashboard` channel. Otherwise
  it refetches every **Dashboard refresh** seconds (60 by default; 0 turns it off) while the tab is
  visible. Either way it fetches its own content, so it only ever shows what the viewer may see.
- The draft sidebar's *Fields changed* count is cached until the draft or its entry is saved again.

## Sidebar label and icon

Settings → Plugins → Peek → Control panel: a **Sidebar label**, a **Sidebar icon** from Craft's icon
picker, or a **Custom SVG icon** (sanitised each time it's drawn). An override made in Craft's
Settings → Control Panel Navigation still wins.

## Permissions

| Permission | What it allows |
| --- | --- |
| Access Peek | The Peek section and dashboard |
| ↳ View diffs | The diff and preview screens, and the sidebar's diff rows |
| ↳ Manage releases | Peek's releases: create, edit, add and remove drafts |
| ↳↳ Publish releases | Publish one of Peek's releases |
| ↳↳ Schedule releases | Give one of Peek's releases a date |
| ↳↳ Delete releases | Delete one of Peek's releases |
| Manage Peek settings | Peek's settings |

Peek's permissions decide which screens someone can use. Craft's entry permissions still decide
which drafts:

- **A diff** needs the right to view the draft and its entry, and on a multi-site install, to edit
  the draft's site.
- **Adding a draft** to a release, and **publishing** it, need what Craft's own *Apply draft* needs:
  saving the draft and saving its entry. If the publisher can't apply any one draft, nothing in the
  release is published.
- **A scheduled release** publishes as the user who scheduled it, with their permissions when it
  runs. If they can't publish a draft any more, lost *Schedule releases*, or were deactivated, the
  release is marked failed and nothing is published.
- **The dashboard** lists only drafts the viewer could open in Craft's editor.

## Configuration

Settings are in **Settings → Plugins → Peek**, or in `config/craft/peek.php`:

```php
<?php

return [
    'staleDraftDays' => 14,          // days without a save before a draft counts as stale
    'defaultSiteId' => null,         // the site new Peek releases start on (null: primary)
    'enableVisualPreview' => true,   // the Visual preview tab
    'maxEntriesPerRelease' => 50,    // the most drafts one of Peek's releases can hold
    'navLabel' => null,              // sidebar label (null: "Peek")
    'navIcon' => null,               // an icon name from Craft's icon picker
    'navIconSvg' => null,            // or raw SVG, which wins
    'notifyOnFailure' => true,       // tell people when a scheduled release fails
    'failureEmails' => '$PEEK_FAILURE_EMAILS',
    'failureSlackWebhookUrl' => '$PEEK_SLACK_WEBHOOK',
    'publishWebhookUrl' => '$PEEK_PUBLISH_WEBHOOK',
    'publishWebhookSecret' => '$PEEK_WEBHOOK_SECRET',
    'dashboardRefreshSeconds' => 60, // without broadcasting; 0 turns it off
];
```

## Events

```php
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Peek\Events\PeekReleasePublished;

Event::listen(PeekReleasePublished::class, function (PeekReleasePublished $event) {
    // $event->release (JustinHoltWeb\Peek\Models\Release), $event->user (null for a console publish)
});
```

Craft's own releases dispatch `CraftCms\Cms\Release\Events\ReleasePublished`.

## Troubleshooting

**A draft's "Saved at" changed, but nobody edited it.** Opening an older draft in Craft's editor
merges the entry's newer changes into it and saves it. That updates its date, moves it up Craft's
drafts menu, and resets its age on Peek's dashboard. The name next to a draft in that menu is the
person who created it, not who last saved it.

**A Peek release says it can't take new drafts.** On Craft Pro and Enterprise, new drafts go into
Craft's releases. Finish the Peek release, or move it with `peek:releases:move-to-craft`.

**`publish` says "Craft's releases publish as a user".** On Pro and Enterprise, pass `--as=<user>`.
The user needs Craft's *Publish releases* permission and the right to apply each draft.

**Scheduled releases never publish.** Check that Laravel's scheduler runs (`php artisan
schedule:list` shows `peek:scheduler:check`), or that cron runs `php craft peek/scheduler/check`,
and that a queue worker is running.

**The Visual preview's panes don't scroll together.** Scrolling is linked only when the site and the
control panel share an origin; a browser won't let one origin read another's frame.

## Development

```bash
composer install
vendor/bin/pest          # a real Craft 6 install with Peek, on in-memory SQLite
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

## License

Proprietary. See [LICENSE.md](LICENSE.md).
