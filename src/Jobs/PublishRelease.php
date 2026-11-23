<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Jobs;

use CraftCms\Cms\Queue\Job;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Services\Releases;
use RuntimeException;

use function CraftCms\Cms\t;

/**
 * Publishes one of Peek's own scheduled releases. Queued by `peek:scheduler:check`, which sets the
 * release to Publishing first so it isn't picked up twice.
 *
 * Nobody is signed in here, so the publish is authorized as the user who scheduled it, with their
 * permissions as they are now. Before 5.0.4 the queue applied every draft unchecked, which made
 * scheduling a way round the publish permission.
 */
class PublishRelease extends Job
{
    public function __construct(
        public int $releaseId,
    ) {}

    public function handle(Releases $releases): void
    {
        $release = $releases->find($this->releaseId);

        if ($release === null) {
            Log::warning("Peek: release #{$this->releaseId} no longer exists, so there's nothing to publish.");

            return;
        }

        $userId = $release->scheduledBy ?? $release->createdBy;
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;

        if (! $user instanceof User || $user->getStatus() !== User::STATUS_ACTIVE || ! $user->can('peek:scheduleReleases')) {
            if ($release->status === ReleaseStatus::Publishing) {
                $release->update(['status' => ReleaseStatus::Failed]);
            }

            throw new RuntimeException("Peek release #{$this->releaseId} was not published: the user who scheduled it no longer exists, isn’t active, or can no longer schedule releases.");
        }

        $problems = $releases->publish($release, $user);

        if ($problems !== []) {
            throw new RuntimeException("Peek couldn't publish release #{$this->releaseId} “{$release->name}”: ".implode(' ', $problems));
        }

        Log::info("Peek published release #{$this->releaseId} “{$release->name}”.");
    }

    protected function defaultDescription(): string
    {
        return t('Publishing release #{id}', ['id' => $this->releaseId], category: 'peek');
    }
}
