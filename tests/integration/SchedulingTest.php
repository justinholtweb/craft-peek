<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use justinholtweb\peek\console\controllers\SchedulerController;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\queue\jobs\PublishReleaseJob;
use justinholtweb\peek\records\ReleaseRecord;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Covers the scheduled-release path: the console command that finds due
 * releases, and the queue job that publishes them.
 */
class SchedulingTest extends PeekTestCase
{
    private function scheduledRelease(string $when, string $name = 'Scheduled'): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = $name;
        $release->createdBy = $this->adminId();
        $release->status = ReleaseStatus::Scheduled;
        $release->scheduledDate = new \DateTime($when);
        $this->assertTrue($this->releases()->saveRelease($release));

        return $release;
    }

    private function releaseWithOneDraft(string $name = 'Queued'): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = $name;
        $release->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($release));

        $canonical = $this->createEntry("$name canonical", ['body' => 'before']);
        $draft = $this->createDraft($canonical, ['title' => "$name updated"], ['body' => 'after']);
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));

        return $release;
    }

    private function runScheduler(): int
    {
        $controller = new SchedulerController('scheduler', Craft::$app);

        return $controller->actionCheck();
    }

    // Scheduler
    // -------------------------------------------------------------------------

    public function testSchedulerIgnoresReleasesThatAreNotDueYet(): void
    {
        $release = $this->scheduledRelease('+7 days');

        $this->assertSame(0, $this->runScheduler());
        $this->assertSame(ReleaseStatus::Scheduled, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testSchedulerIgnoresUnscheduledReleases(): void
    {
        $release = $this->releaseWithOneDraft();

        $this->assertSame(0, $this->runScheduler());
        $this->assertSame(ReleaseStatus::Draft, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testSchedulerQueuesADueRelease(): void
    {
        $release = $this->scheduledRelease('-1 hour');
        $before = Craft::$app->getQueue()->getTotalJobs();

        $this->assertSame(0, $this->runScheduler());

        $this->assertSame($before + 1, Craft::$app->getQueue()->getTotalJobs());
        $this->assertSame(
            ReleaseStatus::Publishing,
            $this->releases()->getReleaseById($release->id)->status,
            'A queued release must leave the Scheduled state so it is not picked up twice.',
        );
    }

    public function testSchedulerDoesNotQueueTheSameReleaseTwice(): void
    {
        $this->scheduledRelease('-1 hour');

        $this->runScheduler();
        $after = Craft::$app->getQueue()->getTotalJobs();

        $this->runScheduler();

        $this->assertSame($after, Craft::$app->getQueue()->getTotalJobs());
    }

    public function testSchedulerHandlesSeveralDueReleases(): void
    {
        $this->scheduledRelease('-2 hours', 'First');
        $this->scheduledRelease('-1 hour', 'Second');
        $before = Craft::$app->getQueue()->getTotalJobs();

        $this->assertSame(0, $this->runScheduler());

        $this->assertSame($before + 2, Craft::$app->getQueue()->getTotalJobs());
        $this->assertSame(
            0,
            (int)ReleaseRecord::find()->where(['status' => ReleaseStatus::Scheduled->value])->count(),
        );
    }

    // Queue job
    // -------------------------------------------------------------------------

    public function testJobPublishesItsRelease(): void
    {
        $release = $this->releaseWithOneDraft();

        (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());

        $after = $this->releases()->getReleaseById($release->id);
        $this->assertSame(ReleaseStatus::Published, $after->status);
        $this->assertSame('published', $after->getEntries()[0]->status);
    }

    public function testJobPublishesAReleaseLeftInThePublishingState(): void
    {
        $release = $this->releaseWithOneDraft();

        // This is the state the scheduler hands the job.
        $release->status = ReleaseStatus::Publishing;
        $this->assertTrue($this->releases()->saveRelease($release));

        (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());

        $this->assertSame(ReleaseStatus::Published, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testJobIsANoOpForAMissingRelease(): void
    {
        $job = new PublishReleaseJob(['releaseId' => 999999]);

        $job->execute(Craft::$app->getQueue());

        $this->assertTrue(true, 'A vanished release should be logged and skipped, not thrown.');
    }

    public function testJobThrowsWhenPublishingFails(): void
    {
        // No entries, so publishing can't succeed.
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'Doomed';
        $this->assertTrue($this->releases()->saveRelease($release));

        $this->expectException(\RuntimeException::class);
        (new PublishReleaseJob(['releaseId' => $release->id]))->execute(Craft::$app->getQueue());
    }

    public function testJobHasADescription(): void
    {
        $job = new PublishReleaseJob(['releaseId' => 7]);

        $this->assertStringContainsString('7', $job->getDescription());
    }
}
