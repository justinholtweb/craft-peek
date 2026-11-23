<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Scheduling\Schedule;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Jobs\PublishRelease;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;

function scheduledRelease(array $attributes = []): Release
{
    return Release::query()->create([
        'name' => 'Scheduled',
        'siteId' => Sites::getPrimarySite()->id,
        'status' => ReleaseStatus::Scheduled,
        'scheduledDate' => now()->subMinute(),
        ...$attributes,
    ]);
}

it('publishes a due release as the person who scheduled it', function () {
    $canonical = $this->createEntry('A', ['body' => 'old']);
    $release = scheduledRelease(['scheduledBy' => $this->admin()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($canonical, [], ['body' => 'scheduled']), null);

    // QUEUE_CONNECTION=sync: the job runs as soon as the scheduler queues it.
    $this->artisan('peek:scheduler:check')->assertSuccessful();

    expect($release->refresh()->status)->toBe(ReleaseStatus::Published)
        ->and(Entry::find()->id($canonical->id)->one()->getFieldValue('body'))->toBe('scheduled');
});

it('leaves releases that are not due yet', function () {
    $release = scheduledRelease(['scheduledDate' => now()->addHour()]);

    $this->artisan('peek:scheduler:check')->expectsOutput('No scheduled releases due.')->assertSuccessful();

    expect($release->refresh()->status)->toBe(ReleaseStatus::Scheduled);
});

it('answers to the Craft 5 command name', function () {
    // `php craft peek/scheduler/check` (the 5.x cron line) resolves to this alias.
    $this->artisan('craft:peek/scheduler/check')->assertSuccessful();
});

it('fails a scheduled release whose scheduler can no longer schedule', function () {
    $release = scheduledRelease(['status' => ReleaseStatus::Publishing]);
    $editor = $this->userWith(['peek:accessPlugin', 'peek:manageReleases']);
    $release->update(['scheduledBy' => $editor->id]);

    expect(fn () => app()->call([new PublishRelease($release->id), 'handle']))
        ->toThrow(RuntimeException::class, 'can no longer schedule');

    expect($release->refresh()->status)->toBe(ReleaseStatus::Failed);
});

it('registers the scheduler check on Laravel’s scheduler', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(fn ($event) => str_contains((string) $event->command, 'peek:scheduler:check')))->toBeTrue();
});
