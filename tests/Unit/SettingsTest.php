<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;

it('defaults to 5.x’s values', function () {
    $settings = new Settings;

    expect($settings->staleDraftDays)->toBe(14)
        ->and($settings->defaultSiteId)->toBeNull()
        ->and($settings->enableVisualPreview)->toBeTrue()
        ->and($settings->maxEntriesPerRelease)->toBe(50);
});

it('rejects a zero stale threshold and an oversized release limit', function () {
    $settings = new Settings(['staleDraftDays' => 0, 'maxEntriesPerRelease' => 501]);

    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('staleDraftDays', 'maxEntriesPerRelease');
});

it('saves through the plugin settings screen’s path', function () {
    expect(Plugins::savePluginSettings(Plugin::getInstance(), ['staleDraftDays' => 30, 'defaultSiteId' => null]))->toBeTrue()
        ->and(Plugin::getInstance()->getSettings()->staleDraftDays)->toBe(30);
});

it('takes the default site as the settings screen posts it', function () {
    $siteId = Sites::getPrimarySite()->id;

    expect(Plugins::savePluginSettings(Plugin::getInstance(), ['defaultSiteId' => (string) $siteId]))->toBeTrue()
        ->and(Plugin::getInstance()->getSettings()->defaultSiteId)->toBe($siteId);
});
