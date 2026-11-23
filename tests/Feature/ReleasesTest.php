<?php

declare(strict_types=1);

use CraftCms\Cms\Element\Events\DraftApplying;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\ReleaseEntry;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\Releases;

function newRelease(array $attributes = []): Release
{
    return Release::query()->create([
        'name' => 'Spring launch',
        'siteId' => Sites::getPrimarySite()->id,
        ...$attributes,
    ]);
}

it('adds a draft with its own entry, once', function () {
    $canonical = $this->createEntry('A');
    $draft = $this->createDraft($canonical, ['title' => 'A2']);
    $release = newRelease();

    expect(app(Releases::class)->addDraft($release, $draft, $this->admin()))->toBeNull();

    $entry = $release->entries()->sole();
    expect($entry->canonicalId)->toBe($canonical->id)
        ->and($entry->draftId)->toBe($draft->id)
        ->and($entry->status)->toBe('pending')
        ->and(app(Releases::class)->addDraft($release, $draft, $this->admin()))->toContain('already');
});

it('refuses a draft the user could not publish themselves', function () {
    $draft = $this->createDraft($this->createEntry('Not yours'), ['title' => 'Changed']);
    $viewer = $this->userWith(['viewEntries:{section}', 'viewPeerEntryDrafts:{section}', 'peek:accessPlugin', 'peek:manageReleases']);

    expect(app(Releases::class)->addDraft(newRelease(), $draft, $viewer))->toContain('can’t publish this draft');
});

it('holds a release to the most drafts setting', function () {
    app(Settings::class)->maxEntriesPerRelease = 1;
    $release = newRelease();

    expect(app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('One'), ['title' => 'One!']), null))->toBeNull()
        ->and(app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('Two'), ['title' => 'Two!']), null))->toContain('at most 1');
});

it('publishes every draft in one go and records it', function () {
    $a = $this->createEntry('A', ['body' => 'old a']);
    $b = $this->createEntry('B', ['body' => 'old b']);
    $release = newRelease();
    $releases = app(Releases::class);
    $releases->addDraft($release, $this->createDraft($a, [], ['body' => 'new a']), null);
    $releases->addDraft($release, $this->createDraft($b, [], ['body' => 'new b']), null);

    expect($releases->publish($release, $this->admin()))->toBe([]);

    $release->refresh();
    expect($release->status)->toBe(ReleaseStatus::Published)
        ->and($release->publishedDate)->not->toBeNull()
        ->and($release->publishedBy)->toBe($this->admin()->id)
        ->and(Entry::find()->id($a->id)->one()->getFieldValue('body'))->toBe('new a')
        ->and(Entry::find()->id($b->id)->one()->getFieldValue('body'))->toBe('new b');

    // Applying deletes the draft element; the rows survive (SET NULL), marked published first.
    foreach ($release->entries()->get() as $entry) {
        expect($entry->status)->toBe('published')->and($entry->draftId)->toBeNull();
    }
});

it('publishes nothing when one draft is gone, and says why', function () {
    $a = $this->createEntry('A', ['body' => 'old a']);
    $b = $this->createEntry('B');
    $release = newRelease();
    $releases = app(Releases::class);
    $releases->addDraft($release, $this->createDraft($a, [], ['body' => 'new a']), null);
    $releases->addDraft($release, $gone = $this->createDraft($b, ['title' => 'B2']), null);

    // Deleting a draft takes it out of the release (the discard listener)…
    Elements::deleteElement($gone, true);
    expect($release->entries()->count())->toBe(1);

    // …and a release whose draft vanished without the listener (FK SET NULL) is refused.
    $release->entries()->first()->update(['draftId' => null]);
    $problems = $releases->publish($release);

    expect($problems)->not->toBeEmpty()
        ->and(Entry::find()->id($a->id)->one()->getFieldValue('body'))->toBe('old a')
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});

it('rolls every draft back when one fails to apply', function () {
    $a = $this->createEntry('A', ['body' => 'old a']);
    $b = $this->createEntry('B', ['body' => 'old b']);
    $release = newRelease();
    $releases = app(Releases::class);
    $releases->addDraft($release, $this->createDraft($a, [], ['body' => 'new a']), null);
    $releases->addDraft($release, $this->createDraft($b, [], ['body' => 'new b']), null);

    Event::listen(DraftApplying::class, function ($event) use ($b) {
        if ($event->canonical->id === $b->id) {
            throw new RuntimeException('Boom');
        }
    });

    $problems = $releases->publish($release, $this->admin());

    expect($problems)->toHaveCount(1)
        ->and($problems[0])->toContain('Boom')
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Failed)
        ->and(Entry::find()->id($a->id)->one()->getFieldValue('body'))->toBe('old a')
        ->and($release->entries()->whereNull('draftId')->count())->toBe(0);
});

it('refuses to publish as someone who could not apply a draft', function () {
    $release = newRelease();
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('A'), ['title' => 'A2']), null);
    $publisher = $this->userWith(['peek:accessPlugin', 'peek:manageReleases', 'peek:publishReleases', 'viewEntries:{section}']);

    $problems = app(Releases::class)->publish($release, $publisher);

    expect($problems[0])->toContain('isn’t allowed to publish')
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});

it('marks a release entry published when the draft is applied from Craft’s editor', function () {
    $canonical = $this->createEntry('A');
    $draft = $this->createDraft($canonical, ['title' => 'A applied elsewhere']);
    $release = newRelease();
    app(Releases::class)->addDraft($release, $draft, null);

    Drafts::applyDraft($draft);

    $entry = $release->entries()->sole();
    expect($entry->status)->toBe(ReleaseEntry::STATUS_PUBLISHED)
        ->and($entry->draftId)->toBeNull();
});

it('lists the unpublished releases a draft is in', function () {
    $draft = $this->createDraft($this->createEntry('A'), ['title' => 'A2']);
    $open = newRelease(['name' => 'Open']);
    $done = newRelease(['name' => 'Done', 'status' => ReleaseStatus::Published]);
    app(Releases::class)->addDraft($open, $draft, null);
    $done->entries()->create(['canonicalId' => $draft->getCanonicalId(), 'draftId' => $draft->id]);

    expect(app(Releases::class)->forDraft($draft->id)->pluck('name')->all())->toBe(['Open']);
});

it('doesn’t name a draft the publisher can’t view', function () {
    $release = newRelease();
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('Classified'), ['title' => 'Classified plan']), null);
    $publisher = $this->userWith(['peek:accessPlugin', 'peek:manageReleases', 'peek:publishReleases']);

    $problems = app(Releases::class)->publish($release, $publisher);

    expect(implode(' ', $problems))->toContain('isn’t allowed to publish')->not->toContain('Classified');
});
