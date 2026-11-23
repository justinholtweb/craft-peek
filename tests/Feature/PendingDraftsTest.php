<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Drafts;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Peek\Services\PendingDrafts;

it('lists saved drafts and never provisional ones', function () {
    $canonical = $this->createEntry('A');
    $saved = $this->createDraft($canonical, ['title' => 'Saved']);
    Drafts::createDraft($canonical, $this->admin()->id, provisional: true);

    expect(collect(app(PendingDrafts::class)->all())->pluck('id')->all())->toBe([$saved->id]);
});

it('finds drafts with no save in N days', function () {
    $old = $this->createDraft($this->createEntry('Old'), ['title' => 'Old draft']);
    $this->createDraft($this->createEntry('New'), ['title' => 'New draft']);

    DB::table('elements')->where('id', $old->id)->update(['dateUpdated' => now()->subDays(30)->utc()->format('Y-m-d H:i:s')]);

    expect(collect(app(PendingDrafts::class)->stale(14))->pluck('id')->all())->toBe([$old->id]);
});

it('counts drafts by section', function () {
    $this->createDraft($this->createEntry('A'), ['title' => 'A2']);
    $this->createDraft($this->createEntry('B'), ['title' => 'B2']);

    $drafts = app(PendingDrafts::class)->all();

    expect(app(PendingDrafts::class)->countsBySection($drafts))->toBe(['News' => 2]);
});
