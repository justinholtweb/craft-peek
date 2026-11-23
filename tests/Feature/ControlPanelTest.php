<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;

use function CraftCms\Cms\cp_url;

const EDITOR = [
    'peek:accessPlugin', 'peek:viewDiffs', 'peek:manageReleases', 'peek:publishReleases',
    'viewEntries:{section}', 'saveEntries:{section}', 'viewPeerEntries:{section}', 'savePeerEntries:{section}',
    'viewPeerEntryDrafts:{section}', 'savePeerEntryDrafts:{section}',
];

it('shows the dashboard to Peek users and no one else', function () {
    $this->createDraft($this->createEntry('Dash'), ['title' => 'Dash draft']);

    $this->actingAs($this->admin())->get(cp_url('peek'))->assertOk()->assertSee('Dash draft');

    $nobody = $this->userWith([]);
    $this->actingAs($nobody)->get(cp_url('peek'))->assertForbidden();
});

it('lists only the drafts the viewer could open', function () {
    $this->createDraft($this->createEntry('Secret'), ['title' => 'Secret draft']);
    $viewer = $this->userWith(['peek:accessPlugin']);

    $this->actingAs($viewer)->get(cp_url('peek'))->assertOk()->assertDontSee('Secret draft');
});

it('shows a diff to someone who can view the draft', function () {
    $draft = $this->createDraft($this->createEntry('Diffed', ['body' => 'before']), [], ['body' => 'after']);

    $this->actingAs($this->userWith(EDITOR))
        ->get(cp_url("peek/diff/{$draft->id}"))
        ->assertOk()
        ->assertSee('peek-field-diff', false)
        ->assertSee('after');
});

it('refuses a diff to someone who can’t view the draft, even with the diff permission', function () {
    $draft = $this->createDraft($this->createEntry('Hidden'), ['title' => 'Hidden draft']);

    $this->actingAs($this->userWith(['peek:accessPlugin', 'peek:viewDiffs']))
        ->get(cp_url("peek/diff/{$draft->id}"))
        ->assertForbidden();
});

it('never shows a provisional draft', function () {
    $canonical = $this->createEntry('Provisional');
    $provisional = Drafts::createDraft($canonical, $this->admin()->id, provisional: true);

    $this->actingAs($this->admin())->get(cp_url("peek/diff/{$provisional->id}"))->assertNotFound();
});

it('creates, edits and fills a release from the CP', function () {
    $editor = $this->userWith(EDITOR);
    $draft = $this->createDraft($this->createEntry('Fill me'), ['title' => 'Filled']);

    $this->actingAs($editor)->get(cp_url('peek/releases/new'))->assertOk();

    $this->actingAs($editor)->post(cp_url('peek/releases'), [
        'name' => 'Autumn',
        'status' => 'draft',
        'siteId' => Sites::getPrimarySite()->id,
    ])->assertRedirect();

    $release = Release::query()->where('name', 'Autumn')->sole();
    expect($release->createdBy)->toBe($editor->id);

    // The picker of drafts to add (absent on Pro, see CraftReleasesTest).
    $this->actingAs($editor)->get(cp_url("peek/releases/{$release->id}"))->assertOk()->assertSee('addDraftId')->assertSee('Filled');

    $this->actingAs($editor)->post(cp_url('peek/releases'), [
        'releaseId' => $release->id,
        'name' => 'Autumn',
        'status' => 'ready',
        'addDraftId' => $draft->id,
    ])->assertRedirect();

    expect($release->refresh()->status)->toBe(ReleaseStatus::Ready)
        ->and($release->entries()->pluck('draftId')->all())->toBe([$draft->id]);

    $this->actingAs($editor)->get(cp_url("peek/releases/{$release->id}"))->assertOk()->assertSee('Filled');
});

it('won’t let a release be set to a status only publishing sets', function () {
    $release = Release::query()->create(['name' => 'X', 'siteId' => Sites::getPrimarySite()->id]);

    $this->actingAs($this->admin())->post(cp_url('peek/releases'), [
        'releaseId' => $release->id,
        'name' => 'X',
        'status' => 'published',
    ])->assertSessionHasErrors('status');

    expect($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});

it('needs the schedule permission to schedule', function () {
    $editor = $this->userWith(EDITOR);

    $this->actingAs($editor)->post(cp_url('peek/releases'), [
        'name' => 'Later',
        'status' => 'scheduled',
        'scheduledDate' => ['date' => now()->addDay()->format('Y-m-d'), 'time' => '09:00'],
    ])->assertForbidden();

    expect(Release::query()->where('name', 'Later')->exists())->toBeFalse();
});

it('schedules as the person who set the date', function () {
    $scheduler = $this->userWith([...EDITOR, 'peek:scheduleReleases']);

    $this->actingAs($scheduler)->post(cp_url('peek/releases'), [
        'name' => 'Later',
        'status' => 'draft',
        'scheduledDate' => ['date' => now()->addDay()->format('Y-m-d'), 'time' => '09:00'],
    ])->assertRedirect();

    $release = Release::query()->where('name', 'Later')->sole();
    expect($release->status)->toBe(ReleaseStatus::Scheduled)
        ->and($release->scheduledBy)->toBe($scheduler->id)
        ->and($release->scheduledDate)->not->toBeNull();
});

it('publishes from the CP only with the publish permission', function () {
    $canonical = $this->createEntry('Pub', ['body' => 'old']);
    $release = Release::query()->create(['name' => 'Go', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($canonical, [], ['body' => 'new']), null);

    $manager = $this->userWith(array_values(array_diff(EDITOR, ['peek:publishReleases'])));
    $this->actingAs($manager)->post(cp_url("peek/releases/{$release->id}/publish"))->assertForbidden();

    $this->actingAs($this->admin())->post(cp_url("peek/releases/{$release->id}/publish"))->assertRedirect();
    expect($release->refresh()->status)->toBe(ReleaseStatus::Published);
});

it('deletes only with the delete permission', function () {
    $release = Release::query()->create(['name' => 'Bin', 'siteId' => Sites::getPrimarySite()->id]);

    $this->actingAs($this->userWith(EDITOR))->delete(cp_url("peek/releases/{$release->id}"))->assertForbidden();
    $this->actingAs($this->admin())->delete(cp_url("peek/releases/{$release->id}"))->assertRedirect();

    expect(Release::query()->find($release->id))->toBeNull();
});

it('removes a draft from a release, leaving the draft', function () {
    $draft = $this->createDraft($this->createEntry('Out'), ['title' => 'Out draft']);
    $release = Release::query()->create(['name' => 'R', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $draft, null);

    $this->actingAs($this->admin())->delete(cp_url("peek/releases/{$release->id}/drafts/{$draft->id}"))->assertRedirect();

    expect($release->entries()->count())->toBe(0)
        ->and($this->reloadDraft($draft->id)->id)->toBe($draft->id);
});

it('adds a draft to a release from the editor sidebar’s screen', function () {
    $draft = $this->createDraft($this->createEntry('Side'), ['title' => 'Side draft']);
    $release = Release::query()->create(['name' => 'Sidebar target', 'siteId' => Sites::getPrimarySite()->id]);
    $editor = $this->userWith(EDITOR);

    $this->actingAs($editor)->get(cp_url("peek/drafts/{$draft->id}/releases"))->assertOk()->assertSee('Sidebar target');

    $this->actingAs($editor)->post(cp_url("peek/drafts/{$draft->id}/releases"), ['release' => "peek:{$release->id}"])->assertRedirect();

    expect($release->entries()->pluck('draftId')->all())->toBe([$draft->id]);
});

it('adds Peek’s rows to a draft’s editor metadata', function () {
    $draft = $this->createDraft($this->createEntry('Meta', ['body' => 'a']), [], ['body' => 'b']);
    $this->actingAs($this->admin());

    $metadata = $draft->getMetadata();

    expect($metadata)->toHaveKey('Fields changed')->toHaveKey('Diff')->toHaveKey('Releases')
        ->and($metadata['Fields changed']())->toBe('1')
        ->and($metadata['Diff']())->toContain("peek/diff/{$draft->id}");
});
