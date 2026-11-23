<?php

declare(strict_types=1);

use CraftCms\Cms\Cms;
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Peek\Events\DashboardChanged;
use JustinHoltWeb\Peek\Widgets\DraftsWidget;

use function CraftCms\Cms\cp_url;

it('serves the dashboard’s content for a refresh, with the viewer’s permissions', function () {
    $this->createDraft($this->createEntry('Fragment'), ['title' => 'Fragment draft']);

    $this->actingAs($this->admin())->getJson(cp_url('peek/dashboard/fragment'))
        ->assertOk()
        ->assertJsonPath('html', fn (string $html) => str_contains($html, 'Fragment draft'));

    $this->actingAs($this->userWith(['peek:accessPlugin']))->getJson(cp_url('peek/dashboard/fragment'))
        ->assertOk()
        ->assertJsonPath('html', fn (string $html) => ! str_contains($html, 'Fragment draft'));

    $this->actingAs($this->userWith([], 'outsider'))->getJson(cp_url('peek/dashboard/fragment'))->assertForbidden();
});

it('marks the dashboard live only when the site broadcasts', function () {
    // The screen's HTML is inside Inertia's page JSON, quotes escaped.
    $page = fn () => stripslashes((string) $this->actingAs($this->admin())->get(cp_url('peek'))->assertOk()->getContent());

    expect($page())->toContain('data-live="0"');

    config(['broadcasting.default' => 'reverb']);
    expect($page())->toContain('data-live="1"');
});

it('broadcasts draft saves when broadcasting is set up, and not otherwise', function () {
    Event::fake([DashboardChanged::class]);
    $canonical = $this->createEntry('Live');

    $this->createDraft($canonical, ['title' => 'Quiet']);
    Event::assertNotDispatched(DashboardChanged::class);

    config(['broadcasting.default' => 'reverb']);
    $this->createDraft($canonical, ['title' => 'Loud']);
    Event::assertDispatched(DashboardChanged::class, fn (DashboardChanged $e) => $e->reason === 'draft' && $e->broadcastOn()->name === 'private-peek.dashboard');
});

it('shows drafts waiting on Craft’s dashboard', function () {
    $this->createDraft($this->createEntry('Widget'), ['title' => 'Widget draft']);
    $this->actingAs($this->admin(), Cms::config()->authGuard);

    $html = new DraftsWidget(['limit' => 5])->props()['html'];

    expect($html)->toContain('Widget draft')->toContain('peek/diff/');

    $this->actingAs($this->userWith(['peek:accessPlugin']), Cms::config()->authGuard);
    expect(new DraftsWidget(['limit' => 5])->props()['html'])->not->toContain('Widget draft');
});
