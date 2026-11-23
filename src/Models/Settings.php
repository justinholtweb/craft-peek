<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Models;

use CraftCms\Cms\Plugin\PluginSettings;

/**
 * Peek's settings. The keys are 5.x's, so an upgraded site keeps what it had in project config,
 * and `config/craft/peek.php` overrides them as `config/peek.php` did.
 */
class Settings extends PluginSettings
{
    /** How many days without a save before a draft counts as stale on the dashboard. */
    public int $staleDraftDays = 14;

    /** The site new releases default to. Null is the primary site. */
    public ?int $defaultSiteId = null;

    /** Show the Visual Preview tab (live page and draft side by side) on the diff screen. */
    public bool $enableVisualPreview = true;

    /** The most drafts one of Peek's own releases can hold. */
    public int $maxEntriesPerRelease = 50;

    public function getRules(): array
    {
        return [
            'staleDraftDays' => ['required', 'integer', 'min:1'],
            'defaultSiteId' => ['nullable', 'integer'],
            'enableVisualPreview' => ['boolean'],
            'maxEntriesPerRelease' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }
}
