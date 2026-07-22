<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\db\Table;
use craft\elements\Entry;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Covers draft discovery: which drafts Peek surfaces, how it groups them, and
 * how it decides one has gone stale.
 */
class DraftServiceTest extends PeekTestCase
{
    public function testNoDraftsOnAFreshInstall(): void
    {
        $this->assertSame([], $this->draftService()->getAllPendingDrafts());
    }

    public function testSavedDraftsAreReturned(): void
    {
        $entry = $this->createEntry('Listed');
        $draft = $this->createDraft($entry, ['title' => 'Listed draft']);

        $drafts = $this->draftService()->getAllPendingDrafts();

        $this->assertCount(1, $drafts);
        $this->assertSame($draft->id, $drafts[0]->id);
    }

    public function testCanonicalEntriesAreNotTreatedAsDrafts(): void
    {
        $this->createEntry('Just a canonical entry');

        $this->assertSame([], $this->draftService()->getAllPendingDrafts());
    }

    public function testProvisionalDraftsAreExcluded(): void
    {
        $entry = $this->createEntry('Has provisional');
        Craft::$app->getDrafts()->createDraft($entry, $this->adminId(), null, null, [], true);

        $this->assertSame([], $this->draftService()->getAllPendingDrafts());
    }

    public function testDisabledDraftsAreStillReturned(): void
    {
        $entry = $this->createEntry('Disabled');
        $draft = $this->createDraft($entry, ['enabled' => false]);

        $ids = array_map(fn($d) => $d->id, $this->draftService()->getAllPendingDrafts());

        $this->assertContains($draft->id, $ids, 'status(null) should include disabled drafts.');
    }

    public function testDraftsAreFilteredBySite(): void
    {
        $entry = $this->createEntry('Site scoped');
        $this->createDraft($entry, ['title' => 'Site scoped draft']);

        $this->assertCount(1, $this->draftService()->getAllPendingDrafts($this->primarySiteId()));
        $this->assertCount(0, $this->draftService()->getAllPendingDrafts(999999));
    }

    public function testDraftsAreOrderedByMostRecentlyUpdated(): void
    {
        $older = $this->createDraft($this->createEntry('Older'), ['title' => 'Older draft']);
        $newer = $this->createDraft($this->createEntry('Newer'), ['title' => 'Newer draft']);

        $this->touchDraft($older, '-2 days');

        $drafts = $this->draftService()->getAllPendingDrafts();

        $this->assertSame([$newer->id, $older->id], array_map(fn($d) => $d->id, $drafts));
    }

    public function testDraftCountsBySection(): void
    {
        $this->createDraft($this->createEntry('One'), ['title' => 'One draft']);
        $this->createDraft($this->createEntry('Two'), ['title' => 'Two draft']);

        $counts = $this->draftService()->getDraftCountsBySection();

        $this->assertSame(['Test Section' => 2], $counts);
    }

    public function testDraftCountsAreEmptyWithNoDrafts(): void
    {
        $this->assertSame([], $this->draftService()->getDraftCountsBySection());
    }

    public function testStaleDraftsOnlyIncludeOldOnes(): void
    {
        $fresh = $this->createDraft($this->createEntry('Fresh'), ['title' => 'Fresh draft']);
        $stale = $this->createDraft($this->createEntry('Stale'), ['title' => 'Stale draft']);

        $this->touchDraft($stale, '-30 days');

        $staleIds = array_map(fn($d) => $d->id, $this->draftService()->getStaleDrafts(14));

        $this->assertSame([$stale->id], $staleIds);
        $this->assertNotContains($fresh->id, $staleIds);
    }

    public function testStaleThresholdIsRespected(): void
    {
        $draft = $this->createDraft($this->createEntry('Borderline'), ['title' => 'Borderline draft']);
        $this->touchDraft($draft, '-10 days');

        $this->assertCount(1, $this->draftService()->getStaleDrafts(7), '10 days old is stale at a 7 day threshold.');
        $this->assertCount(0, $this->draftService()->getStaleDrafts(14), '10 days old is fresh at a 14 day threshold.');
    }

    public function testStaleDraftsAreOrderedOldestFirst(): void
    {
        $old = $this->createDraft($this->createEntry('Old'), ['title' => 'Old draft']);
        $oldest = $this->createDraft($this->createEntry('Oldest'), ['title' => 'Oldest draft']);

        $this->touchDraft($old, '-20 days');
        $this->touchDraft($oldest, '-40 days');

        $ids = array_map(fn($d) => $d->id, $this->draftService()->getStaleDrafts(14));

        $this->assertSame([$oldest->id, $old->id], $ids);
    }

    public function testStaleDraftsAreFilteredBySite(): void
    {
        $draft = $this->createDraft($this->createEntry('Stale scoped'), ['title' => 'Stale scoped draft']);
        $this->touchDraft($draft, '-30 days');

        $this->assertCount(1, $this->draftService()->getStaleDrafts(14, $this->primarySiteId()));
        $this->assertCount(0, $this->draftService()->getStaleDrafts(14, 999999));
    }

    /**
     * Backdates a draft's `dateUpdated` directly — element saves always stamp
     * it with the current time.
     */
    private function touchDraft(Entry $draft, string $modifier): void
    {
        $date = (new \DateTime())->modify($modifier)->format('Y-m-d H:i:s');

        foreach ([Table::ELEMENTS, Table::ELEMENTS_SITES] as $table) {
            $column = $table === Table::ELEMENTS ? 'id' : 'elementId';
            Craft::$app->getDb()->createCommand()
                ->update($table, ['dateUpdated' => $date], [$column => $draft->id])
                ->execute();
        }
    }
}
