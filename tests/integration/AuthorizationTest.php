<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Db;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\migrations\m261002_000000_scheduled_by_and_utc_dates;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\queue\jobs\PublishReleaseJob;
use justinholtweb\peek\records\ReleaseRecord;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Who a release acts as. Until 5.0.4 a release applied its drafts with nobody's permissions: the
 * release screens checked Peek's own permissions only, the canonical ID was taken from the
 * request, and a scheduled publish ran unchecked in the queue.
 */
class AuthorizationTest extends PeekTestCase
{
    private function release(string $name = 'Auth release'): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = $name;
        $release->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($release));

        return $release;
    }

    /**
     * An active user with Peek's permissions but none on the test section.
     */
    private function editorWithoutSectionRights(array $extra = []): User
    {
        $user = new User(['username' => 'peek-editor-' . uniqid(), 'email' => uniqid('peek-') . '@example.com']);
        $this->assertTrue(Craft::$app->getElements()->saveElement($user, false));
        Craft::$app->getUsers()->activateUser($user);
        $permissions = array_merge([
            'accesscp', 'peek:accessplugin', 'peek:managereleases', 'peek:publishreleases', 'peek:schedulereleases',
        ], $extra);
        $removed = array_map(fn($p) => substr($p, 1), array_filter($permissions, fn($p) => str_starts_with($p, '-')));
        $permissions = array_values(array_diff(array_filter($permissions, fn($p) => !str_starts_with($p, '-')), $removed));
        Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);

        return User::find()->id($user->id)->one();
    }

    /**
     * A non-admin who can publish anything in the test section — everything Craft's editor asks
     * of someone applying another author's draft.
     */
    private function sectionPublisher(bool $canSchedule = true): User
    {
        $uid = $this->ensureSection()->uid;

        return $this->editorWithoutSectionRights(array_merge([
            "viewentries:$uid", "saveentries:$uid", "viewpeerentries:$uid", "savepeerentries:$uid",
            "viewpeerentrydrafts:$uid", "savepeerentrydrafts:$uid",
        ], $canSchedule ? [] : ['-peek:schedulereleases']));
    }

    private function admin(): User
    {
        return User::find()->id($this->adminId())->one();
    }

    private function isApplied(Entry $draft): bool
    {
        return !Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();
    }

    // Adding entries
    // -------------------------------------------------------------------------

    public function testAnEntryIsRecordedAgainstTheDraftsOwnCanonical(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Real');
        $other = $this->createEntry('Other');
        $draft = $this->createDraft($canonical);

        $this->assertFalse($this->releases()->addEntryToRelease($release->id, $other->id, $draft->id));
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
    }

    public function testAProvisionalDraftCannotBeAdded(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Provisional');
        $draft = Craft::$app->getDrafts()->createDraft($canonical, $this->adminId(), provisional: true);

        $this->assertFalse($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
    }

    public function testAPublishedReleaseCannotGainOrLoseEntries(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Done');
        $draft = $this->createDraft($canonical);
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));
        $later = $this->createDraft($this->createEntry('Later'));

        $release->status = ReleaseStatus::Published;
        $this->assertTrue($this->releases()->saveRelease($release));

        $this->assertFalse($this->releases()->addEntryToRelease($release->id, $later->getCanonicalId(), $later->id));
        $this->assertFalse($this->releases()->removeEntryFromRelease($release->id, $draft->id));
    }

    // Publishing as a user
    // -------------------------------------------------------------------------

    public function testAUserWhoCannotPublishADraftIsRefusedAndNothingIsApplied(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Guarded', ['body' => 'before']);
        $draft = $this->createDraft($canonical, [], ['body' => 'after']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $editor = $this->editorWithoutSectionRights();

        $this->assertNotSame([], $this->releases()->unauthorizedEntries($release, $editor));
        $this->assertFalse($this->releases()->publishRelease($release, $editor));
        $this->assertNotSame([], $release->getErrors('entries'));
        $this->assertFalse($this->isApplied($draft));
        $this->assertSame(ReleaseStatus::Draft, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testAUserWhoCanPublishEveryDraftPublishes(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Allowed');
        $draft = $this->createDraft($canonical);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $this->assertSame([], $this->releases()->unauthorizedEntries($release, $this->admin()));
        $this->assertTrue($this->releases()->publishRelease($release, $this->admin()));
        $this->assertTrue($this->isApplied($draft));
        $this->assertSame($this->adminId(), $this->releases()->getReleaseById($release->id)->publishedBy);
    }

    public function testARefusedReleaseLeftPublishingByTheSchedulerIsMarkedFailed(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Stuck');
        $draft = $this->createDraft($canonical);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);
        $release->status = ReleaseStatus::Publishing;
        $this->assertTrue($this->releases()->saveRelease($release));

        $this->assertFalse($this->releases()->publishRelease($release, $this->editorWithoutSectionRights()));
        $this->assertSame(ReleaseStatus::Failed, $this->releases()->getReleaseById($release->id)->status);
        $this->assertNotSame([], $release->getErrors('entries'), 'The reasons survive the status save.');
    }

    // The queue job
    // -------------------------------------------------------------------------

    private function queuedRelease(?int $scheduledBy, ?int $createdBy = null): array
    {
        $release = $this->release('Queued ' . uniqid());
        $canonical = $this->createEntry('Queued canonical');
        $draft = $this->createDraft($canonical);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);

        $release->status = ReleaseStatus::Publishing;
        $release->scheduledBy = $scheduledBy;
        $release->createdBy = $createdBy ?? $release->createdBy;
        $this->assertTrue($this->releases()->saveRelease($release));

        return [$release, $draft];
    }

    public function testTheJobPublishesAsTheUserWhoScheduled(): void
    {
        [$release, $draft] = $this->queuedRelease($this->adminId());

        (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());

        $this->assertTrue($this->isApplied($draft));
        $this->assertSame($this->adminId(), $this->releases()->getReleaseById($release->id)->publishedBy);
    }

    public function testTheJobRefusesDraftsTheSchedulerCannotPublish(): void
    {
        [$release, $draft] = $this->queuedRelease($this->editorWithoutSectionRights()->id);

        try {
            (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());
            $this->fail('The job should have refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('isn’t allowed to publish', $e->getMessage());
        }

        $this->assertFalse($this->isApplied($draft));
        $this->assertSame(ReleaseStatus::Failed, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testANonAdminWhoCanPublishTheSectionPublishesThroughTheJob(): void
    {
        [$release, $draft] = $this->queuedRelease($this->sectionPublisher()->id);

        (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());

        $this->assertTrue($this->isApplied($draft));
    }

    public function testTheJobRefusesASchedulerWhoLostThePermission(): void
    {
        // Every right on the section, so only the missing schedule permission can refuse it.
        [$release, $draft] = $this->queuedRelease($this->sectionPublisher(canSchedule: false)->id);

        $this->expectException(\RuntimeException::class);

        try {
            (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());
        } finally {
            $this->assertFalse($this->isApplied($draft));
            $this->assertSame(ReleaseStatus::Failed, $this->releases()->getReleaseById($release->id)->status);
        }
    }

    public function testTheJobRefusesWhenNoUserCanBeFound(): void
    {
        [$release, $draft] = $this->queuedRelease(null);
        Db::update(ReleaseRecord::tableName(), ['createdBy' => null], ['id' => $release->id]);

        $this->expectException(\RuntimeException::class);

        try {
            (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());
        } finally {
            $this->assertFalse($this->isApplied($draft));
        }
    }

    // Dates
    // -------------------------------------------------------------------------

    public function testReleaseDatesAreStoredInUtcAndRoundTrip(): void
    {
        $previous = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('America/New_York');

        try {
            $release = $this->release('Dated');
            $release->scheduledDate = new \DateTime('2030-06-01 12:00:00', new \DateTimeZone('America/New_York'));
            $this->assertTrue($this->releases()->saveRelease($release));

            $raw = (new Query())->select('scheduledDate')->from(ReleaseRecord::tableName())->where(['id' => $release->id])->scalar();
            $this->assertSame('2030-06-01 16:00:00', $raw);
            $this->assertSame($release->scheduledDate->getTimestamp(), $this->releases()->getReleaseById($release->id)->scheduledDate->getTimestamp());
        } finally {
            Craft::$app->setTimeZone($previous);
        }
    }

    public function testTheMigrationMovesLocalDatesToUtcAndBackfillsTheScheduler(): void
    {
        $previous = Craft::$app->getTimeZone();
        Craft::$app->setTimeZone('America/New_York');

        try {
            $release = $this->release('Legacy');
            Db::update(ReleaseRecord::tableName(), [
                'status' => 'scheduled',
                'scheduledDate' => '2030-06-01 12:00:00',
                'scheduledBy' => null,
            ], ['id' => $release->id]);

            (new m261002_000000_scheduled_by_and_utc_dates())->safeUp();

            $row = (new Query())->from(ReleaseRecord::tableName())->where(['id' => $release->id])->one();
            $this->assertSame('2030-06-01 16:00:00', $row['scheduledDate']);
            $this->assertSame($this->adminId(), (int)$row['scheduledBy']);
        } finally {
            Craft::$app->setTimeZone($previous);
        }
    }

    // Dashboard
    // -------------------------------------------------------------------------

    public function testTheDashboardOnlyListsDraftsTheViewerCanSee(): void
    {
        $draft = $this->createDraft($this->createEntry('Private'));

        $ids = fn(?User $viewer) => array_map(fn(Entry $e) => $e->id, $this->draftService()->getAllPendingDrafts(null, $viewer));

        $this->assertContains($draft->id, $ids(null));
        $this->assertContains($draft->id, $ids($this->admin()));
        $this->assertNotContains($draft->id, $ids($this->editorWithoutSectionRights()));
        $this->assertSame([], $this->draftService()->getDraftCountsBySection(null, $this->editorWithoutSectionRights()));
    }
}
