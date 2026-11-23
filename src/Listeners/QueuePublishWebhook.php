<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Release\Events\ReleasePublished;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Events\PeekReleasePublished;
use JustinHoltWeb\Peek\Jobs\SendPublishWebhook;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use JustinHoltWeb\Peek\Support\Presenter;

/**
 * When a release publishes (Peek's or Craft's), queues the publish webhook with the release and the
 * live URLs of the entries it changed: enough for a CDN purge or a static rebuild.
 */
readonly class QueuePublishWebhook
{
    public function __construct(
        private Settings $settings,
        private ReleaseSystem $releases,
    ) {}

    public function handle(PeekReleasePublished|ReleasePublished $event): void
    {
        if ($this->settings->publishWebhook() === null) {
            return;
        }

        $summary = $event instanceof PeekReleasePublished
            ? $this->releases->fromPeek($event->release, withEntries: true)
            : $this->releases->fromCraft($event->release, withEntries: true);

        SendPublishWebhook::dispatch(self::payload($summary));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(ReleaseSummary $release): array
    {
        $entries = [];

        foreach ($release->entries as $item) {
            $entry = Entry::find()->id($item['canonicalId'])->site('*')->unique()->one();

            $entries[] = [
                'id' => $item['canonicalId'],
                'uri' => $entry instanceof Entry ? $entry->uri : null,
                'url' => $entry instanceof Entry ? $entry->getUrl() : null,
            ];
        }

        $data = Presenter::release($release);
        unset($data['cpUrl']);

        return [
            'event' => 'release.published',
            'release' => $data,
            'entries' => $entries,
            'sentAt' => now()->toAtomString(),
        ];
    }
}
