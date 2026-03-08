<?php

namespace justinholtweb\peek\queue\jobs;

use Craft;
use craft\queue\BaseJob;
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

        $success = $releasesService->publishRelease($release);

        if (!$success) {
            throw new \RuntimeException("Failed to publish release #{$this->releaseId}: {$release->name}");
        }

        Craft::info("Published release #{$this->releaseId}: {$release->name}", 'peek');
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('peek', 'Publishing release #{id}', ['id' => $this->releaseId]);
    }
}
