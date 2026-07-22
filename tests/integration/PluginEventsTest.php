<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\db\Query;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseEntryRecord;
use justinholtweb\peek\tests\Support\PeekTestCase;

/**
 * Covers the plugin's element event wiring: keeping release membership honest
 * when drafts are applied outside a release, or deleted outright.
 */
class PluginEventsTest extends PeekTestCase
{
    private ?\craft\elements\Entry $canonical = null;
    private ?\craft\elements\Entry $draft = null;

    /**
     * Sets up a release containing one draft, exposing both elements on
     * `$this->canonical` / `$this->draft`.
     */
    private function releaseWithDraft(): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = 'Event release';
        $release->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($release));

        $this->canonical = $this->createEntry('Event canonical', ['body' => 'old']);
        $this->draft = $this->createDraft($this->canonical, ['title' => 'Event draft'], ['body' => 'new']);
        $this->assertTrue(
            $this->releases()->addEntryToRelease($release->id, $this->canonical->id, $this->draft->id),
        );

        return $release;
    }

    public function testApplyingADraftDirectlyMarksItPublishedInItsReleases(): void
    {
        $release = $this->releaseWithDraft();

        // Applied through Craft (e.g. the author hit "Publish draft" in the CP),
        // not through Peek's release publisher.
        Craft::$app->getDrafts()->applyDraft($this->draft);

        $entries = $this->releases()->getReleaseById($release->id)->getEntries();

        $this->assertCount(1, $entries, 'Applying a draft must not drop it from the release.');
        $this->assertSame('published', $entries[0]->status);
        $this->assertNull($entries[0]->draftId);
        $this->assertSame($this->canonical->id, $entries[0]->canonicalId);
    }

    public function testDeletingADraftRemovesItFromItsReleases(): void
    {
        $release = $this->releaseWithDraft();

        Craft::$app->getElements()->deleteElement($this->draft);

        $this->assertCount(
            0,
            $this->releases()->getReleaseById($release->id)->getEntries(),
            'A discarded draft should no longer be part of the release.',
        );
    }

    public function testDeletingADraftInOneReleaseClearsItFromAllOfThem(): void
    {
        $release = $this->releaseWithDraft();

        $second = new Release();
        $second->siteId = $this->primarySiteId();
        $second->name = 'Second release';
        $second->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($second));
        $this->assertTrue(
            $this->releases()->addEntryToRelease($second->id, $this->canonical->id, $this->draft->id),
        );

        Craft::$app->getElements()->deleteElement($this->draft);

        $this->assertSame(0, (int)ReleaseEntryRecord::find()->count());
    }

    public function testDeletingACanonicalEntryDoesNotStrandReleaseRows(): void
    {
        $release = $this->releaseWithDraft();

        Craft::$app->getElements()->deleteElement($this->canonical, true);

        $rows = (new Query())
            ->from(ReleaseEntryRecord::tableName())
            ->where(['releaseId' => $release->id])
            ->all();

        $this->assertSame([], $rows, 'The canonicalId FK cascades, so the row should be gone.');
    }

    public function testDeletingANonDraftEntryLeavesOtherReleasesAlone(): void
    {
        $release = $this->releaseWithDraft();

        // An unrelated entry being deleted must not touch release membership.
        Craft::$app->getElements()->deleteElement($this->createEntry('Unrelated'));

        $this->assertCount(1, $this->releases()->getReleaseById($release->id)->getEntries());
    }

    public function testPluginComponentsAreRegistered(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(\justinholtweb\peek\services\Releases::class, $plugin->releases);
        $this->assertInstanceOf(\justinholtweb\peek\services\DiffService::class, $plugin->diff);
        $this->assertInstanceOf(\justinholtweb\peek\services\DraftService::class, $plugin->drafts);
        $this->assertInstanceOf(\justinholtweb\peek\models\Settings::class, $plugin->getSettings());
    }

    /**
     * The sidebar panel is built by hand rather than through a template, so its
     * escaping and its "not in any release" branch are worth pinning down.
     */
    private function renderSidebar(\craft\elements\Entry $draft): string
    {
        $method = new \ReflectionMethod(Plugin::getInstance(), '_renderPeekSidebar');
        $method->setAccessible(true);

        return $method->invoke(Plugin::getInstance(), $draft);
    }

    public function testSidebarReportsTheChangedFieldCountAndRelease(): void
    {
        $release = $this->releaseWithDraft();

        $html = $this->renderSidebar($this->draft);

        $this->assertStringContainsString('Fields Changed', $html);
        $this->assertStringContainsString('View Diff', $html);
        $this->assertStringContainsString("peek/diff/{$this->draft->id}", $html);
        $this->assertStringContainsString('Event release', $html);
        $this->assertStringContainsString("peek/releases/{$release->id}", $html);
    }

    public function testSidebarSaysWhenADraftIsInNoRelease(): void
    {
        $canonical = $this->createEntry('Lonely');
        $draft = $this->createDraft($canonical, ['title' => 'Lonely draft']);

        $this->assertStringContainsString('Not in any release', $this->renderSidebar($draft));
    }

    public function testSidebarIsEmptyForADraftWithoutADistinctCanonical(): void
    {
        $canonical = $this->createEntry('Self');

        $this->assertSame('', $this->renderSidebar($canonical));
    }

    public function testSidebarEscapesTheReleaseName(): void
    {
        $release = $this->releaseWithDraft();
        $release->name = '<script>alert(1)</script>';
        $this->assertTrue($this->releases()->saveRelease($release));

        $html = $this->renderSidebar($this->draft);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testPermissionsAreRegistered(): void
    {
        $flat = json_encode(Craft::$app->getUserPermissions()->getAllPermissions());

        foreach ([
            'peek:accessPlugin',
            'peek:viewDiffs',
            'peek:manageReleases',
            'peek:publishReleases',
            'peek:scheduleReleases',
            'peek:deleteReleases',
            'peek:manageSettings',
        ] as $permission) {
            $this->assertStringContainsString($permission, $flat, "Missing permission: $permission");
        }
    }
}
