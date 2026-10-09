# Peek — Content Staging & Visual Diff for Craft CMS 5

Peek gives editorial teams field-by-field diff comparison, side-by-side visual preview, and coordinated multi-entry releases for Craft CMS 5.

## Features

- **Field Diff** — Side-by-side comparison of every field between a draft and its live entry, with syntax-highlighted changes
- **Visual Preview** — Two-pane iframe preview showing live vs. draft with synchronized scrolling
- **Releases** — Group multiple drafts into a release and publish them atomically (all or nothing)
- **Scheduled Publishing** — Set a future date and let Peek auto-publish via cron
- **Dashboard** — Overview of pending drafts, stale drafts, and active releases across the site
- **Entry Sidebar** — See changed field count, diff link and release membership in the draft editor, and add the draft to a release from there

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

- **Diffs** need the right to view the draft and its entry.
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
];
```
