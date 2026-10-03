<?php

namespace justinholtweb\peek\queue\jobs;

use Craft;
use craft\elements\User;
use craft\queue\BaseJob;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\Plugin;

class PublishReleaseJob extends BaseJob
{
    public int $releaseId;

    public function execute($queue): void
    {
        $releasesService = Plugin::getInstance()->releases;
        $release = $releasesService->getReleaseById($this->releaseId);

        if (!$release) {
            Craft::warning("PublishReleaseJob: Release #{$this->releaseId} not found.", 'peek');
            return;
        }

        // Nobody is signed in here, so the publish is authorized as the user who scheduled it —
        // with their permissions as they are now, not when they scheduled it. Before 5.0.4 the
        // queue applied every draft unchecked, so scheduling was a way round publish rights.
        $userId = $release->scheduledBy ?? $release->createdBy;
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;

        if ($user === null || $user->getStatus() !== User::STATUS_ACTIVE || !$user->can('peek:scheduleReleases')) {
            if ($release->status === ReleaseStatus::Publishing) {
                $release->status = ReleaseStatus::Failed;
                $releasesService->saveRelease($release);
            }

            throw new \RuntimeException("Release #{$this->releaseId} was not published: the user who scheduled it no longer exists, isn’t active, or can no longer schedule releases.");
        }

        $success = $releasesService->publishRelease($release, $user);

        if (!$success) {
            $reasons = implode(' ', $release->getErrors('entries'));
            throw new \RuntimeException("Failed to publish release #{$this->releaseId}: {$release->name}" . ($reasons !== '' ? " — $reasons" : ''));
        }

        Craft::info("Published release #{$this->releaseId}: {$release->name}", 'peek');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('peek', 'Publishing release #{id}', ['id' => $this->releaseId]);
    }
}
