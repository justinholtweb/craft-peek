<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\events\DraftEvent;
use craft\services\Drafts;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseEntryRecord;
use justinholtweb\peek\records\ReleaseRecord;
use justinholtweb\peek\tests\Support\PeekTestCase;
use yii\base\Event;
use yii\base\InvalidArgumentException;

/**
 * Exercises the Releases service against a real database: persistence,
 * membership management, validation and the atomic publish path.
 */
class ReleasesServiceTest extends PeekTestCase
{
    private function makeRelease(string $name = 'Sprint 1', ?ReleaseStatus $status = null): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = $name;
        $release->description = 'A release';
        $release->createdBy = $this->adminId();

        if ($status !== null) {
            $release->status = $status;
        }

        $this->assertTrue($this->releases()->saveRelease($release), 'Release should save.');

        return $release;
    }

    // Persistence
    // -------------------------------------------------------------------------

    public function testSaveReleasePopulatesGeneratedAttributes(): void
    {
        $release = $this->makeRelease();

        $this->assertNotNull($release->id);
        $this->assertNotNull($release->uid);
        $this->assertNotNull($release->dateCreated);
        $this->assertNotNull($release->dateUpdated);
    }

    public function testSaveReleaseRejectsInvalidModel(): void
    {
        $release = new Release();
        $release->name = null;
        $release->siteId = null;

        $this->assertFalse($this->releases()->saveRelease($release));
        $this->assertArrayHasKey('name', $release->getErrors());
        $this->assertArrayHasKey('siteId', $release->getErrors());
    }

    public function testGetReleaseByIdRoundTripsEveryField(): void
    {
        $release = $this->makeRelease('Round trip');
        $release->scheduledDate = new \DateTime('2030-01-02 03:04:05');
        $release->status = ReleaseStatus::Scheduled;
        $this->assertTrue($this->releases()->saveRelease($release));

        $loaded = $this->releases()->getReleaseById($release->id);

        $this->assertNotNull($loaded);
        $this->assertSame('Round trip', $loaded->name);
        $this->assertSame('A release', $loaded->description);
        $this->assertSame($this->primarySiteId(), $loaded->siteId);
        $this->assertSame(ReleaseStatus::Scheduled, $loaded->status);
        $this->assertSame('2030-01-02 03:04:05', $loaded->scheduledDate->format('Y-m-d H:i:s'));
        $this->assertSame($this->adminId(), $loaded->createdBy);
    }

    public function testGetReleaseByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->releases()->getReleaseById(999999));
    }

    public function testUpdatingAReleaseDoesNotCreateASecondRow(): void
    {
        $release = $this->makeRelease('Original name');
        $release->name = 'Renamed';
        $this->assertTrue($this->releases()->saveRelease($release));

        $this->assertSame(1, (int)ReleaseRecord::find()->count());
        $this->assertSame('Renamed', $this->releases()->getReleaseById($release->id)->name);
    }

    public function testSavingAReleaseWithAnUnknownIdThrows(): void
    {
        $release = new Release();
        $release->id = 999999;
        $release->siteId = $this->primarySiteId();
        $release->name = 'Ghost';

        $this->expectException(InvalidArgumentException::class);
        $this->releases()->saveRelease($release);
    }

    public function testUnknownStoredStatusFallsBackToDraft(): void
    {
        $release = $this->makeRelease();

        $record = ReleaseRecord::findOne($release->id);
        $record->status = 'bogus';
        $record->save(false);

        $this->assertSame(ReleaseStatus::Draft, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testGetAllReleasesFiltersBySite(): void
    {
        $this->makeRelease('Site release');

        $this->assertCount(1, $this->releases()->getAllReleases());
        $this->assertCount(1, $this->releases()->getAllReleases($this->primarySiteId()));
        $this->assertCount(0, $this->releases()->getAllReleases(999999));
    }

    public function testDeleteRelease(): void
    {
        $release = $this->makeRelease();

        $this->assertTrue($this->releases()->deleteRelease($release->id));
        $this->assertNull($this->releases()->getReleaseById($release->id));
        $this->assertFalse($this->releases()->deleteRelease($release->id));
    }

    public function testDeletingAReleaseCascadesToItsEntries(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Cascade');
        $draft = $this->createDraft($canonical, ['title' => 'Cascade draft']);

        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
        $this->assertTrue($this->releases()->deleteRelease($release->id));

        $this->assertSame(0, (int)ReleaseEntryRecord::find()->count());
    }

    // Membership
    // -------------------------------------------------------------------------

    public function testAddEntryToRelease(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Adding');
        $draft = $this->createDraft($canonical, ['title' => 'Adding draft']);

        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));

        $entries = $this->releases()->getReleaseById($release->id)->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame($canonical->id, $entries[0]->canonicalId);
        $this->assertSame($draft->id, $entries[0]->draftId);
        $this->assertSame('pending', $entries[0]->status);
        $this->assertSame(1, $entries[0]->sortOrder);
    }

    public function testAddingTheSameDraftTwiceIsRejected(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Dupe');
        $draft = $this->createDraft($canonical, ['title' => 'Dupe draft']);

        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
        $this->assertFalse($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
        $this->assertSame(1, (int)ReleaseEntryRecord::find()->count());
    }

    public function testTheSameDraftCanBelongToTwoReleases(): void
    {
        $releaseA = $this->makeRelease('A');
        $releaseB = $this->makeRelease('B');
        $canonical = $this->createEntry('Shared');
        $draft = $this->createDraft($canonical, ['title' => 'Shared draft']);

        $this->assertTrue($this->releases()->addEntryToRelease($releaseA->id, $canonical->id, $draft->id));
        $this->assertTrue($this->releases()->addEntryToRelease($releaseB->id, $canonical->id, $draft->id));

        $releases = $this->releases()->getReleasesForDraft($draft->id);
        $this->assertCount(2, $releases);
        $this->assertEqualsCanonicalizing(['A', 'B'], array_map(fn($r) => $r->name, $releases));
    }

    public function testSortOrderIncrementsPerRelease(): void
    {
        $release = $this->makeRelease();

        for ($i = 1; $i <= 3; $i++) {
            $canonical = $this->createEntry("Entry $i");
            $draft = $this->createDraft($canonical, ['title' => "Draft $i"]);
            $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
        }

        $sortOrders = array_map(
            fn($e) => $e->sortOrder,
            $this->releases()->getReleaseById($release->id)->getEntries(),
        );

        $this->assertSame([1, 2, 3], $sortOrders);
    }

    public function testMaxEntriesPerReleaseIsEnforced(): void
    {
        Plugin::getInstance()->getSettings()->maxEntriesPerRelease = 2;

        $release = $this->makeRelease();

        for ($i = 1; $i <= 3; $i++) {
            $canonical = $this->createEntry("Capped $i");
            $draft = $this->createDraft($canonical, ['title' => "Capped draft $i"]);
            $added = $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

            $this->assertSame($i <= 2, $added, "Entry $i should " . ($i <= 2 ? '' : 'not ') . 'have been added.');
        }

        $this->assertSame(2, (int)ReleaseEntryRecord::find()->count());
    }

    public function testRemoveEntryFromRelease(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Removable');
        $draft = $this->createDraft($canonical, ['title' => 'Removable draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $this->assertTrue($this->releases()->removeEntryFromRelease($release->id, $draft->id));
        $this->assertCount(0, $this->releases()->getReleaseById($release->id)->getEntries());
        $this->assertFalse($this->releases()->removeEntryFromRelease($release->id, $draft->id));
    }

    public function testRemoveEntryByDraftIdClearsEveryRelease(): void
    {
        $releaseA = $this->makeRelease('A');
        $releaseB = $this->makeRelease('B');
        $canonical = $this->createEntry('Everywhere');
        $draft = $this->createDraft($canonical, ['title' => 'Everywhere draft']);
        $this->releases()->addEntryToRelease($releaseA->id, $canonical->id, $draft->id);
        $this->releases()->addEntryToRelease($releaseB->id, $canonical->id, $draft->id);

        $this->releases()->removeEntryByDraftId($draft->id);

        $this->assertSame(0, (int)ReleaseEntryRecord::find()->count());
    }

    public function testMarkEntryPublishedByDraftId(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Marked');
        $draft = $this->createDraft($canonical, ['title' => 'Marked draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $this->releases()->markEntryPublishedByDraftId($draft->id);

        $this->assertSame('published', $this->releases()->getReleaseById($release->id)->getEntries()[0]->status);
    }

    public function testGetReleasesForDraftIsEmptyWhenUnused(): void
    {
        $this->assertSame([], $this->releases()->getReleasesForDraft(999999));
    }

    // Validation
    // -------------------------------------------------------------------------

    public function testValidateReleaseRejectsAnEmptyRelease(): void
    {
        $release = $this->makeRelease();

        $errors = $this->releases()->validateRelease($release);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('no entries', $errors[0]);
    }

    public function testValidateReleasePassesForALiveDraft(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Valid');
        $draft = $this->createDraft($canonical, ['title' => 'Valid draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $this->assertSame([], $this->releases()->validateRelease($this->releases()->getReleaseById($release->id)));
    }

    public function testValidateReleaseReportsAMissingDraft(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Vanishing');
        $draft = $this->createDraft($canonical, ['title' => 'Vanishing draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        // Point the row at a draft that was never there, bypassing the delete hook.
        Craft::$app->getDb()->createCommand()
            ->update(ReleaseEntryRecord::tableName(), ['draftId' => null], ['releaseId' => $release->id])
            ->execute();

        $errors = $this->releases()->validateRelease($this->releases()->getReleaseById($release->id));

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('no longer has a draft to publish', $errors[0]);
    }

    public function testValidateReleaseReportsADraftThatWasHardDeleted(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Deleted');
        $draft = $this->createDraft($canonical, ['title' => 'Deleted draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $loaded = $this->releases()->getReleaseById($release->id);

        // Soft-delete the draft element without going through Peek's delete hook,
        // so the release still points at a draft ID that no longer resolves.
        Craft::$app->getDb()->createCommand()
            ->update('{{%elements}}', ['dateDeleted' => (new \DateTime())->format('Y-m-d H:i:s')], ['id' => $draft->id])
            ->execute();

        $errors = $this->releases()->validateRelease($loaded);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('no longer exists', $errors[0]);
    }

    public function testAnEntryWithNoDraftIsNeverPublished(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Orphan', ['body' => 'untouched']);
        $draft = $this->createDraft($canonical, ['title' => 'Orphan draft'], ['body' => 'changed']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        // This is exactly the state a release entry is left in after publishing:
        // the SET NULL FK clears draftId when the draft element goes away.
        Craft::$app->getDb()->createCommand()
            ->update(ReleaseEntryRecord::tableName(), ['draftId' => null], ['releaseId' => $release->id])
            ->execute();

        $loaded = $this->releases()->getReleaseById($release->id);
        $this->assertFalse($this->releases()->publishRelease($loaded));

        // The unrelated draft must not have been applied to anything.
        $after = Entry::find()->id($canonical->id)->status(null)->one();
        $this->assertSame('Orphan', $after->title);
        $this->assertSame('untouched', $after->getFieldValue('body'));
    }

    public function testAPublishedReleaseCannotBePublishedAgain(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Once');
        $draft = $this->createDraft($canonical, ['title' => 'Once updated']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $loaded = $this->releases()->getReleaseById($release->id);
        $this->assertTrue($this->releases()->publishRelease($loaded));

        $published = $this->releases()->getReleaseById($release->id);
        $this->assertSame(ReleaseStatus::Published, $published->status);
        $this->assertFalse($this->releases()->publishRelease($published));

        // ...and it stays published rather than flipping to failed.
        $this->assertSame(ReleaseStatus::Published, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testValidateReleaseOnAnUnsavedReleaseReportsNoEntries(): void
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'Never saved';

        $this->assertNotEmpty($this->releases()->validateRelease($release));
    }

    // Publishing
    // -------------------------------------------------------------------------

    public function testPublishReleaseAppliesEveryDraft(): void
    {
        $release = $this->makeRelease();

        $first = $this->createEntry('First', ['body' => 'old first']);
        $second = $this->createEntry('Second', ['body' => 'old second']);
        $firstDraft = $this->createDraft($first, ['title' => 'First updated'], ['body' => 'new first']);
        $secondDraft = $this->createDraft($second, ['title' => 'Second updated'], ['body' => 'new second']);

        $this->releases()->addEntryToRelease($release->id, $first->id, $firstDraft->id);
        $this->releases()->addEntryToRelease($release->id, $second->id, $secondDraft->id);

        $loaded = $this->releases()->getReleaseById($release->id);
        $this->assertTrue($this->releases()->publishRelease($loaded));

        $firstAfter = Entry::find()->id($first->id)->status(null)->one();
        $secondAfter = Entry::find()->id($second->id)->status(null)->one();

        $this->assertSame('First updated', $firstAfter->title);
        $this->assertSame('new first', $firstAfter->getFieldValue('body'));
        $this->assertSame('Second updated', $secondAfter->title);
        $this->assertSame('new second', $secondAfter->getFieldValue('body'));
    }

    public function testPublishReleaseRecordsCompletionMetadata(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('Meta');
        $draft = $this->createDraft($canonical, ['title' => 'Meta updated']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $loaded = $this->releases()->getReleaseById($release->id);
        $this->assertTrue($this->releases()->publishRelease($loaded));

        $after = $this->releases()->getReleaseById($release->id);
        $this->assertSame(ReleaseStatus::Published, $after->status);
        $this->assertNotNull($after->publishedDate);
        $this->assertCount(1, $after->getEntries());
        $this->assertSame('published', $after->getEntries()[0]->status);
    }

    public function testPublishingClearsTheDraftIdButKeepsTheEntryRow(): void
    {
        $release = $this->makeRelease();
        $canonical = $this->createEntry('FK');
        $draft = $this->createDraft($canonical, ['title' => 'FK updated']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $loaded = $this->releases()->getReleaseById($release->id);
        $this->assertTrue($this->releases()->publishRelease($loaded));

        $row = (new Query())
            ->from(ReleaseEntryRecord::tableName())
            ->where(['releaseId' => $release->id])
            ->one();

        $this->assertNotFalse($row, 'The release entry row must survive the draft being applied.');
        $this->assertNull($row['draftId'], 'The draftId FK is SET NULL when the draft element is removed.');
        $this->assertSame($canonical->id, (int)$row['canonicalId']);
        $this->assertSame('published', $row['status']);
    }

    public function testPublishingAnEmptyReleaseFails(): void
    {
        $release = $this->makeRelease();

        $this->assertFalse($this->releases()->publishRelease($release));
        $this->assertSame(ReleaseStatus::Draft, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testAFailedPublishRollsBackEveryDraftAndMarksTheReleaseFailed(): void
    {
        $release = $this->makeRelease();

        $good = $this->createEntry('Good', ['body' => 'original good']);
        $goodDraft = $this->createDraft($good, ['title' => 'Good updated'], ['body' => 'changed good']);
        $this->releases()->addEntryToRelease($release->id, $good->id, $goodDraft->id);

        $bad = $this->createEntry('Bad');
        $badDraft = $this->createDraft($bad, ['title' => 'Bad updated']);
        $this->releases()->addEntryToRelease($release->id, $bad->id, $badDraft->id);

        // Blow up while applying the *second* draft, so the failure lands after
        // the first one has already been applied.
        Event::on(
            Drafts::class,
            Drafts::EVENT_BEFORE_APPLY_DRAFT,
            $handler = function(DraftEvent $event) use ($badDraft) {
                if ($event->draft->id === $badDraft->id) {
                    throw new \RuntimeException('Boom');
                }
            },
        );

        try {
            $loaded = $this->releases()->getReleaseById($release->id);
            $this->assertFalse($this->releases()->publishRelease($loaded));
        } finally {
            Event::off(Drafts::class, Drafts::EVENT_BEFORE_APPLY_DRAFT, $handler);
        }

        $goodAfter = Entry::find()->id($good->id)->status(null)->one();
        $this->assertSame('Good', $goodAfter->title, 'The first draft must not survive a rolled-back release.');
        $this->assertSame('original good', $goodAfter->getFieldValue('body'));

        $this->assertSame(ReleaseStatus::Failed, $this->releases()->getReleaseById($release->id)->status);
    }
}
