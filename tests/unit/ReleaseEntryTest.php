<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
use justinholtweb\peek\models\ReleaseEntry;

/**
 * Covers the ReleaseEntry model's defaults and validation rules — the guards
 * that keep a release's queued entries in a known, applyable state.
 */
class ReleaseEntryTest extends Unit
{
    public function testDefaults(): void
    {
        $entry = new ReleaseEntry();

        $this->assertSame(0, $entry->sortOrder);
        $this->assertSame('pending', $entry->status);
        $this->assertNull($entry->errorMessage);
        $this->assertNull($entry->draftId);
    }

    public function testReleaseIdAndCanonicalIdAreRequired(): void
    {
        $entry = new ReleaseEntry();

        $this->assertFalse($entry->validate());
        $this->assertArrayHasKey('releaseId', $entry->getErrors());
        $this->assertArrayHasKey('canonicalId', $entry->getErrors());
    }

    public function testMinimalValidEntry(): void
    {
        $entry = new ReleaseEntry();
        $entry->releaseId = 1;
        $entry->canonicalId = 2;

        $this->assertTrue($entry->validate());
    }

    public function testStatusMustBeWithinAllowedRange(): void
    {
        $entry = new ReleaseEntry();
        $entry->releaseId = 1;
        $entry->canonicalId = 2;
        $entry->status = 'bogus';

        $this->assertFalse($entry->validate());
        $this->assertArrayHasKey('status', $entry->getErrors());
    }

    /**
     * @dataProvider allowedStatuses
     */
    public function testAllAllowedStatusesValidate(string $status): void
    {
        $entry = new ReleaseEntry();
        $entry->releaseId = 1;
        $entry->canonicalId = 2;
        $entry->status = $status;

        $this->assertTrue($entry->validate());
    }

    public function allowedStatuses(): array
    {
        return [
            'pending' => ['pending'],
            'published' => ['published'],
            'failed' => ['failed'],
        ];
    }

    public function testDraftIdIsOptional(): void
    {
        // A canonical entry with no associated draft (draftId null) is still valid;
        // draftId is only required once a draft is attached.
        $entry = new ReleaseEntry();
        $entry->releaseId = 1;
        $entry->canonicalId = 2;
        $entry->draftId = null;

        $this->assertTrue($entry->validate());
    }
}
