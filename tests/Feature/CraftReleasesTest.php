<?php

declare(strict_types=1);

use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Release\Enums\ReleaseStatus as CraftStatus;
use CraftCms\Cms\Release\Models\Release as CraftRelease;
use CraftCms\Cms\Release\Releases as CraftReleases;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

use function CraftCms\Cms\cp_url;

beforeEach(function () {
    // Pro and Enterprise have releases of their own; Peek works with those.
    Edition::set(Edition::Pro);
});

it('uses Craft’s releases on Pro', function () {
    expect(app(ReleaseSystem::class)->usesCraft())->toBeTrue()
        ->and(app(ReleaseSystem::class)->primarySource())->toBe(ReleaseSummary::SOURCE_CRAFT);
});

it('adds a draft to one of Craft’s releases from the editor sidebar screen', function () {
    $draft = $this->createDraft($this->createEntry('Pro'), ['title' => 'Pro draft']);
    $release = app(CraftReleases::class)->create('Craft release', null, $this->admin());

    $this->actingAs($this->admin())->get(cp_url("peek/drafts/{$draft->id}/releases"))->assertOk()->assertSee('Craft release');
    $this->actingAs($this->admin())->post(cp_url("peek/drafts/{$draft->id}/releases"), ['release' => "craft:{$release->id}"])->assertRedirect();

    expect($release->items()->pluck('draftId')->all())->toBe([$draft->draftId])
        ->and(collect(app(ReleaseSystem::class)->forDraft($draft))->pluck('name')->all())->toBe(['Craft release']);
});

it('won’t start new Peek releases on Pro', function () {
    $this->actingAs($this->admin())->get(cp_url('peek/releases/new'))->assertRedirect(cp_url('releases'));
    $this->actingAs($this->admin())->get(cp_url('peek/releases'))->assertOk()->assertSee('move-to-craft');
});

it('publishes Craft’s releases from the console only as a user', function () {
    $canonical = $this->createEntry('Pro deploy', ['body' => 'old']);
    $draft = $this->createDraft($canonical, [], ['body' => 'via craft']);
    $release = app(CraftReleases::class)->create('Deploy', null, $this->admin());
    app(CraftReleases::class)->attach($release, $draft, $this->admin());

    $this->artisan('peek:releases:list')->expectsOutputToContain('Deploy')->assertSuccessful();

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--no-interaction' => true])
        ->expectsOutputToContain('--as')
        ->assertFailed();

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--as' => $this->admin()->username, '--no-interaction' => true])
        ->assertSuccessful();

    expect($release->refresh()->status)->toBe(CraftStatus::Published)
        ->and(Entry::find()->id($canonical->id)->one()->getFieldValue('body'))->toBe('via craft');
});

it('moves Peek’s releases into Craft’s', function () {
    $draft = $this->createDraft($this->createEntry('Moving'), ['title' => 'Moving draft']);
    $peek = Release::query()->create(['name' => 'Old Peek release', 'siteId' => Sites::getPrimarySite()->id, 'description' => 'From 5.x']);
    app(Releases::class)->addDraft($peek, $draft, null);

    $this->artisan('peek:releases:move-to-craft', ['--dry-run' => true])->expectsOutputToContain('Old Peek release')->assertSuccessful();
    expect(Release::query()->find($peek->id))->not->toBeNull();

    $this->artisan('peek:releases:move-to-craft')->expectsOutputToContain('--as')->assertExitCode(2);

    $this->artisan('peek:releases:move-to-craft', ['--as' => $this->admin()->username])->assertSuccessful();

    $moved = CraftRelease::query()->where('name', 'Old Peek release')->sole();
    expect($moved->description)->toBe('From 5.x')
        ->and($moved->items()->pluck('draftId')->all())->toBe([$draft->draftId])
        ->and(Release::query()->find($peek->id))->toBeNull();
});

it('still lets a Peek release from before finish publishing on Pro', function () {
    $canonical = $this->createEntry('Legacy', ['body' => 'old']);
    $peek = Release::query()->create(['name' => 'Legacy', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($peek, $this->createDraft($canonical, [], ['body' => 'finished']), null);

    $this->artisan('peek:releases:publish', ['id' => $peek->id, '--source' => 'peek', '--no-interaction' => true])->assertSuccessful();

    expect($peek->refresh()->status)->toBe(ReleaseStatus::Published);
});

it('points the Releases nav item at Craft’s screen', function () {
    $this->actingAs($this->admin());
    $item = Plugin::getInstance()->getCpNavItem();

    $hrefs = array_map(fn ($sub) => $sub->getUrl(), $item->subnav);

    expect($hrefs)->toContain('releases')->not->toContain('peek/releases');
});

it('links to earlier Peek releases while any are unfinished', function () {
    $this->actingAs($this->admin());
    $hrefs = fn () => array_map(fn ($sub) => $sub->getUrl(), Plugin::getInstance()->getCpNavItem()->subnav);

    expect($hrefs())->not->toContain('peek/releases');

    Release::query()->create(['name' => 'Left over', 'siteId' => Sites::getPrimarySite()->id]);

    expect($hrefs())->toContain('peek/releases');
});

it('shows an earlier Peek release on Pro without a picker for new drafts', function () {
    $release = Release::query()->create(['name' => 'Earlier', 'siteId' => Sites::getPrimarySite()->id]);
    $this->createDraft($this->createEntry('Candidate'), ['title' => 'Candidate draft']);

    $this->actingAs($this->admin())->get(cp_url("peek/releases/{$release->id}"))
        ->assertOk()
        ->assertSee('Earlier')
        ->assertDontSee('addDraftId');
});
