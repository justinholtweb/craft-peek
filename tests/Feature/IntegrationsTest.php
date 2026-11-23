<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Jobs\PublishRelease;
use JustinHoltWeb\Peek\Mail\ReleaseFailedMail;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Notifications\ReleaseFailed;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\Releases;

/** Scheduled by someone who can no longer schedule, so the job fails. */
function failingScheduledRelease(User $editor): Release
{
    return Release::query()->create([
        'name' => 'Doomed',
        'siteId' => Sites::getPrimarySite()->id,
        'status' => ReleaseStatus::Scheduled,
        'scheduledDate' => now()->subMinute(),
        'scheduledBy' => $editor->id,
    ]);
}

it('tells the scheduler, the extra addresses and Slack when a scheduled release fails', function () {
    Notification::fake();
    Mail::fake();
    Http::fake();

    $settings = app(Settings::class);
    $settings->failureEmails = 'ops@example.com, lead@example.com';
    $settings->failureSlackWebhookUrl = 'https://hooks.slack.test/services/x';

    $release = failingScheduledRelease($this->userWith(['peek:accessPlugin', 'peek:manageReleases']));

    // The scheduler can no longer schedule: the job fails, and its failed() hook notifies. On the
    // sync queue the job's exception comes straight back here.
    try {
        $this->artisan('peek:scheduler:check');
    } catch (RuntimeException) {
    }

    expect($release->refresh()->status)->toBe(ReleaseStatus::Failed);

    Notification::assertSentTo(User::find()->id($release->scheduledBy)->one(), ReleaseFailed::class);
    Mail::assertSent(ReleaseFailedMail::class, 2);
    Mail::assertSent(ReleaseFailedMail::class, fn (ReleaseFailedMail $m) => $m->hasTo('ops@example.com'));
    Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://hooks.slack.test') && str_contains($r['text'], 'Doomed'));
});

it('stays quiet when failure notices are off', function () {
    Notification::fake();
    Mail::fake();
    app(Settings::class)->notifyOnFailure = false;
    app(Settings::class)->failureEmails = 'ops@example.com';

    $release = failingScheduledRelease($this->userWith(['peek:accessPlugin', 'peek:manageReleases']));
    new PublishRelease($release->id)->failed(new RuntimeException('Nope'));

    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

it('posts a signed webhook with the published entries’ URLs', function () {
    Http::fake();
    $settings = app(Settings::class);
    $settings->publishWebhookUrl = 'https://hooks.example.test/peek';
    $settings->publishWebhookSecret = 'shh';

    $canonical = $this->createEntry('Hooked', ['body' => 'old']);
    $release = Release::query()->create(['name' => 'Hooked release', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($canonical, [], ['body' => 'new']), null);

    expect(app(Releases::class)->publish($release, $this->admin()))->toBe([]);

    Http::assertSent(function (HttpRequest $request) use ($canonical) {
        $body = $request->body();
        $data = json_decode($body, true);

        return $request->url() === 'https://hooks.example.test/peek'
            && $request->header('X-Peek-Signature')[0] === 'sha256='.hash_hmac('sha256', $body, 'shh')
            && $data['event'] === 'release.published'
            && $data['release']['name'] === 'Hooked release'
            && $data['entries'][0]['id'] === $canonical->id
            && str_ends_with((string) $data['entries'][0]['url'], 'news/'.$canonical->slug);
    });
});

it('sends no webhook when none is set', function () {
    Http::fake();
    $release = Release::query()->create(['name' => 'Quiet', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('Q'), ['title' => 'Q2']), null);

    app(Releases::class)->publish($release, $this->admin());

    Http::assertNothingSent();
});

it('resolves environment variables in URL settings', function () {
    putenv('PEEK_TEST_HOOK=https://env.example.test/hook');
    $_ENV['PEEK_TEST_HOOK'] = $_SERVER['PEEK_TEST_HOOK'] = 'https://env.example.test/hook';
    $settings = new Settings(['publishWebhookUrl' => '$PEEK_TEST_HOOK']);

    expect($settings->validate())->toBeTrue()
        ->and($settings->publishWebhook())->toBe('https://env.example.test/hook');
});

it('caches the sidebar’s change count until the draft is saved again', function () {
    $canonical = $this->createEntry('Cached', ['body' => 'a']);
    $draft = $this->createDraft($canonical, [], ['body' => 'b']);
    $diff = app(Diff::class);

    expect($diff->cachedChangedCount($draft, $canonical))->toBe(1);

    $draft->title = 'Cached, retitled';
    Elements::saveElement($draft);
    $draft = $this->reloadDraft($draft->id);

    // Saved within the same second, so its date may not have moved; the old count (1) still isn't served.
    expect($diff->cachedChangedCount($this->reloadDraft($draft->id), $canonical))->toBe(2);
});
