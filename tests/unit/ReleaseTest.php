<?php

namespace justinholtweb\peek\tests\unit;

use Codeception\Test\Unit;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\models\ReleaseEntry;

/**
 * Covers the Release model's entry collection management and defaults.
 */
class ReleaseTest extends Unit
{
    public function testDefaultsToDraftWithNoEntries(): void
    {
        $release = new Release();

        $this->assertSame(ReleaseStatus::Draft, $release->status);
        $this->assertSame(0, $release->getEntryCount());
        $this->assertSame([], $release->getEntries());
    }

    public function testEntriesRoundTripThroughGetterSetter(): void
    {
        $release = new Release();
        $entryA = new ReleaseEntry();
        $entryB = new ReleaseEntry();

        $release->setEntries([$entryA, $entryB]);

        $this->assertSame(2, $release->getEntryCount());
        $this->assertSame([$entryA, $entryB], $release->getEntries());
    }

    public function testSettingEntriesReplacesPrevious(): void
    {
        $release = new Release();
        $release->setEntries([new ReleaseEntry(), new ReleaseEntry()]);
        $release->setEntries([new ReleaseEntry()]);

        $this->assertSame(1, $release->getEntryCount());
    }

    public function testNameAndSiteIdAreRequired(): void
    {
        $release = new Release();

        $this->assertFalse($release->validate());
        $this->assertArrayHasKey('name', $release->getErrors());
        $this->assertArrayHasKey('siteId', $release->getErrors());
    }

    public function testMinimalValidRelease(): void
    {
        $release = new Release();
        $release->name = 'Spring launch';
        $release->siteId = 1;

        $this->assertTrue($release->validate());
    }

    public function testNameIsCappedAt255Characters(): void
    {
        $release = new Release();
        $release->name = str_repeat('a', 256);
        $release->siteId = 1;

        $this->assertFalse($release->validate());
        $this->assertArrayHasKey('name', $release->getErrors());
    }
}
