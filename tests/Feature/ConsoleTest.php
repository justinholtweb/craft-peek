<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;

function consoleRelease(string $name = 'Deploy'): Release
{
    return Release::query()->create(['name' => $name, 'siteId' => Sites::getPrimarySite()->id]);
}

it('lists releases as a table and as JSON', function () {
    consoleRelease('Listed');

    $this->artisan('peek:releases:list')->expectsOutputToContain('Listed')->assertSuccessful();

    $this->artisan('peek:releases:list', ['--json' => true])
        ->expectsOutputToContain('"name": "Listed"')
        ->assertSuccessful();

    $this->artisan('peek:releases:list', ['--status' => 'published'])->expectsOutput('No releases.')->assertSuccessful();
    $this->artisan('craft:peek/releases/list')->assertSuccessful();
});

it('reports a healthy release with exit 0 and an empty one with exit 1', function () {
    $empty = consoleRelease('Empty');
    $this->artisan('peek:releases:status', ['id' => $empty->id])->expectsOutputToContain('Release has no entries.')->assertFailed();

    $ok = consoleRelease('Fine');
    app(Releases::class)->addDraft($ok, $this->createDraft($this->createEntry('A'), ['title' => 'A2']), null);
    $this->artisan('peek:releases:status', ['id' => $ok->id])->assertSuccessful();
});

it('dry-runs and then publishes as the system', function () {
    $canonical = $this->createEntry('A', ['body' => 'old']);
    $release = consoleRelease();
    app(Releases::class)->addDraft($release, $this->createDraft($canonical, [], ['body' => 'deployed']), null);

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--dry-run' => true])
        ->expectsOutputToContain('can be published')
        ->assertSuccessful();
    expect($release->refresh()->status)->toBe(ReleaseStatus::Draft);

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--no-interaction' => true])->assertSuccessful();

    expect($release->refresh()->status)->toBe(ReleaseStatus::Published)
        ->and(Entry::find()->id($canonical->id)->one()->getFieldValue('body'))->toBe('deployed');
});

it('holds --as to that user’s rights', function () {
    $release = consoleRelease();
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('A'), ['title' => 'A2']), null);
    $this->userWith(['peek:accessPlugin', 'peek:manageReleases', 'peek:publishReleases'], 'deployer');

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--as' => 'deployer', '--no-interaction' => true])
        ->expectsOutputToContain('isn’t allowed to publish')
        ->assertFailed();

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--as' => 'nobody', '--no-interaction' => true])->assertExitCode(2);

    expect($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});

it('won’t publish a release twice', function () {
    $release = consoleRelease();
    $release->update(['status' => ReleaseStatus::Published]);

    $this->artisan('peek:releases:publish', ['id' => $release->id, '--json' => true])
        ->expectsOutputToContain('"published": false')
        ->assertFailed();
});

it('keeps -n a dry run under the Craft 5 name, where Laravel would read --no-interaction', function () {
    $release = consoleRelease();
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('A'), ['title' => 'A2']), null);

    $argv = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['craft', 'peek/releases/publish', (string) $release->id, '-n'];

    try {
        $this->artisan('craft:peek/releases/publish', ['id' => $release->id, '--no-interaction' => true])
            ->expectsOutputToContain('-n is a dry run')
            ->assertSuccessful();
    } finally {
        $_SERVER['argv'] = $argv;
    }

    expect($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});
