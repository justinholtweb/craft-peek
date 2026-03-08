# Peek — Content Staging & Visual Diff for Craft CMS 5

Peek gives editorial teams field-by-field diff comparison, side-by-side visual preview, and coordinated multi-entry releases for Craft CMS 5.

## Features

- **Field Diff** — Side-by-side comparison of every field between a draft and its live entry, with syntax-highlighted changes
- **Visual Preview** — Two-pane iframe preview showing live vs. draft with synchronized scrolling
- **Releases** — Group multiple drafts into a release and publish them atomically (all or nothing)
- **Scheduled Publishing** — Set a future date and let Peek auto-publish via cron
- **Dashboard** — Overview of pending drafts, stale drafts, and active releases across the site
- **Entry Sidebar** — See changed field count, diff link, and release membership directly in the draft editor

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

## Configuration

Settings are available in the Craft CP under Peek > Settings, or via `config/peek.php`:

```php
return [
    'staleDraftDays' => 14,
    'enableVisualPreview' => true,
    'maxEntriesPerRelease' => 50,
];
```
