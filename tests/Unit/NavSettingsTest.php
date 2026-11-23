<?php

declare(strict_types=1);

use CraftCms\Cms\Cp\Data\NavItem;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;

it('renames the sidebar item and swaps its icon', function () {
    $item = Plugin::getInstance()->customiseNavItem(new NavItem()->label('Peek'), new Settings(['navLabel' => 'Drafts & releases', 'navIcon' => 'scribble']));

    expect($item->label)->toBe('Drafts & releases')->and($item->icon)->toBe('scribble');
});

it('prefers a pasted SVG, sanitised', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>';
    $item = Plugin::getInstance()->customiseNavItem(new NavItem()->label('Peek'), new Settings(['navIconSvg' => $svg, 'navIcon' => 'scribble']));

    expect($item->iconSvg)->toContain('<circle')->not->toContain('<script')
        ->and($item->icon)->toBeNull();
});

it('rejects an icon that isn’t in the picker and markup that isn’t one svg', function () {
    $settings = new Settings(['navIcon' => 'not-a-real-icon-name', 'navIconSvg' => '<div>nope</div>']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('navIcon', 'navIconSvg');
});

it('rejects a webhook that isn’t http(s), and bad addresses', function () {
    $settings = new Settings(['publishWebhookUrl' => 'ftp://example.com', 'failureEmails' => 'ops@example.com, not-an-address']);

    expect($settings->validate())->toBeFalse()
        ->and($settings->errors()->keys())->toContain('publishWebhookUrl', 'failureEmails');
});
