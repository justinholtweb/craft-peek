# Peek — Content Staging & Visual Diff for Craft CMS 5

Peek gives editorial teams field-by-field diff comparison, side-by-side visual preview, and coordinated multi-entry releases for Craft CMS 5.

## Features

- **Field Diff** — Side-by-side comparison of every field between a draft and its live entry, with syntax-highlighted changes
- **Visual Preview** — Two-pane iframe preview showing live vs. draft with synchronized scrolling
- **Releases** — Group multiple drafts into a release and publish them atomically (all or nothing)
- **Scheduled Publishing** — Set a future date and let Peek auto-publish via cron
- **Dashboard** — Overview of pending drafts, stale drafts, and active releases across the site
- **Entry Sidebar** — See changed field count, diff link and release membership in the draft editor, and add the draft to a release from there
- **MCP tools** — AI agents can list drafts, read diffs, and check, fill and publish releases through Craft's MCP server (Craft 5.12.1+), with the agent user's own permissions
- **GraphQL** — Read-only queries for drafts, diffs and releases, scoped by the schema's sections and sites
- **Custom nav item** — Rename Peek's control panel nav item and give it any icon

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

```bash
composer require justinholtweb/craft-peek
php craft plugin/install peek
```

## Scheduling

To enable scheduled releases, add a cron job that runs every minute:

```
* * * * * /path/to/craft peek/scheduler/check
```

## Console commands

For deploy pipelines and scripts, releases can be listed, checked and published from the command line:

```bash
php craft peek/releases/list                     # every release, newest first
php craft peek/releases/list --status=ready --site=default --json
php craft peek/releases/status 12                # one release, its entries, and anything that would stop it
php craft peek/releases/publish 12 --dry-run     # check it could be published, apply nothing
php craft peek/releases/publish 12 --interactive=0
php craft peek/releases/publish 12 --as=jane@example.com
```

- Every command takes `--json` for machine-readable output.
- `status` exits `0` for a release that's fine and `1` for one that failed or can't be published as it
  stands (no entries, a draft that's gone). A missing release exits `65`.
- `publish` applies every draft in the release or none of them, exactly like the control panel's
  Publish button, and exits `1` if nothing was published. It won't publish a release that's already
  published, or one the scheduler has handed to the queue. Without `--interactive=0` it asks first.

> **The console runs as the system.** There's no signed-in user, so `publish` applies the release's
> drafts without checking anyone's permissions — whoever can run `php craft` can already change
> anything on the site. Pass `--as=<username or email>` to hold the publish to one user's rights
> instead: they need the Publish releases permission, and every draft must be one they could apply
> in Craft's editor, or nothing is published. The user is recorded as the publisher.

## MCP (AI agents)

On **Craft 5.12.1 or later**, Peek adds six tools to Craft's MCP server. On older Craft 5 releases
there's no MCP server, and Peek works as before without them.

| Tool | Does | Permission | Read-only |
|---|---|---|---|
| `peek_drafts_list` | Saved drafts waiting to be applied, newest first — the dashboard's list (`staleOnly`, `siteId`, `limit` 1–100, `offset`) | Access Peek | ✓ |
| `peek_draft_diff` | A draft compared with its entry, field by field, as plain text (`draftId` is the draft's element ID; `siteId`, `includeUnchanged`) | View diffs | ✓ |
| `peek_releases_list` | Releases, newest first (`status`) | Manage releases | ✓ |
| `peek_release_get` | One release with its entries and anything that would stop you publishing it | Manage releases | ✓ |
| `peek_release_add_draft` | Adds a draft to a release | Manage releases | |
| `peek_release_publish` | Publishes a release now, all or nothing (`dryRun` to check first) | Manage releases + Publish releases | |

Every tool also needs control panel access and acts as the MCP user, with the same checks as the
control panel: only drafts that user could open (view rights and, on multi-site installs, the site)
are listed or diffed, a draft's title is only shown to someone who could open it, and adding or
publishing a draft needs the rights to apply it in Craft's editor. **Publishing through MCP always
runs as the agent's user, never as the system** — unlike `php craft peek/releases/publish` without
`--as`.

To use them, turn on Craft's MCP server under **Settings → MCP** and connect your agent with a token
for a user who has Peek's permissions. The two write tools need the token's `mcp:write` scope; the
rest need `mcp:read`. To try them locally without a token, run Craft's stdio server as a user:

```bash
php craft mcp/serve --user=admin
```

## GraphQL

Peek adds read-only queries for drafts, their diffs and releases. In a GraphQL schema, under
**Peek**, turn on:

- **Query drafts and their diffs (peekDrafts, peekDraftDiff)** — `peekDrafts:read`. The schema also
  needs Craft's own **Query for element drafts** option.
- **Query releases (peekReleases, peekRelease)** — `peekReleases:read`.

```graphql
{
  peekDrafts(staleOnly: false, limit: 20) { id draftId canonicalId title draftName sectionHandle siteHandle dateUpdated stale }
  peekDraftDiff(draftId: 1234, includeUnchanged: false) {
    changedCount
    draft { title }
    fields { handle label type changed oldValue newValue error }
  }
  peekReleases(status: "scheduled") { id name status siteHandle entryCount scheduledDate }
  peekRelease(id: 12) { name status publishedDate entries { canonicalId draftId status } }
}
```

Drafts are unpublished content, so:

- **The public schema never gets anything** from Peek's queries — `null` or an empty list, whatever
  its scopes. Use a token with its own schema.
- Drafts and diffs are only returned for entries in sections (and sites) the schema can read; any
  other draft is left out of `peekDrafts`, and `peekDraftDiff` returns `null` for it.
- Releases on a site the schema can't read are left out. A release's `entries` and `entryCount`
  include only entries the schema could read.
- Without the matching scope, the queries aren't in the schema at all.

Field values are the text Peek compares, with anything the schema might not be allowed to read left
out:

- Related elements (Entries, Categories, Users, Assets and other relation fields, also inside
  Matrix blocks) are only counted: `1 related element`, `3 related elements`, or `(none)`.
- A Link field pointing at an element is `1 linked element`; a plain URL link is shown.
- Rich text is read from its stored HTML, without its tags and reference tags, and is never
  rendered (rendering can pull in other elements).
- Any other object value, such as a third-party field's, is `(not shown over GraphQL)`, and so is
  an array that holds anything but plain values and dates. Plain arrays, such as Table fields, are
  shown.
- Booleans, such as `enabled`, are `On` or `Off`.

`changed` is still decided on the full values, so swapping one related entry for another shows as a
change even though both sides read `1 related element`. The control panel and MCP diffs show
everything the viewer may see. There are no mutations.

## Permissions

| Permission | Description |
|---|---|
| Access Peek | View the Peek CP section |
| View diffs | View field-by-field diff comparisons |
| Manage releases | Create, edit, and manage releases |
| Publish releases | Publish releases (apply all drafts) |
| Schedule releases | Set scheduled publish dates |
| Delete releases | Delete releases |
| Manage Peek settings | Access plugin settings |

Peek's permissions decide which Peek screens someone can use; Craft's entry permissions still decide
which drafts:

- **The dashboard** lists only drafts the viewer could open in Craft's editor — view rights and, on
  multi-site installs, the Edit site permission for the draft's site.
- **Diffs** need the right to view the draft and its entry (and, on multi-site installs, its site).
- **Adding a draft** to a release, and **publishing** it, need what Craft's editor needs to apply
  that draft — saving the draft and saving its entry. If the publisher can't apply any one draft,
  nothing in the release is published.
- **Scheduled releases** publish as the user who scheduled them, with that user's permissions at the
  moment the release runs. If they can no longer publish a draft, lost the Schedule releases
  permission, or are no longer active, the release is marked Failed and nothing is applied.

## Configuration

Settings are available in the Craft CP under Peek > Settings, or via `config/peek.php`:

```php
return [
    'staleDraftDays' => 14,
    'defaultSiteId' => null,
    'enableVisualPreview' => true,
    'maxEntriesPerRelease' => 50,
    'navLabel' => null,
    'navIcon' => null,
    'navIconSvg' => null,
];
```

### Control panel nav item

Peek's nav item can be renamed and given another icon under **Peek → Settings → Navigation**, or in
the config file:

- `navLabel` — the label, up to 64 characters. Empty is “Peek”.
- `navIcon` — an icon name from Craft's icon picker, such as `eye`.
- `navIconSvg` — your own icon, as a single `<svg>` element (up to 50 KB). It wins over `navIcon`,
  and it's sanitized every time it's drawn: scripts, event handlers and external references are
  removed.

The SVG wins over the icon name, which wins over Peek's own icon. A control panel navigation plugin
that customizes Peek's item overrides these.
