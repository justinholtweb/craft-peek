<?php

namespace justinholtweb\peek\tests\integration;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\mcp\McpActor;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\mcp\DraftTools;
use justinholtweb\peek\mcp\ReleaseTools;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\tests\Support\PeekTestCase;
use Mcp\Exception\ToolCallException;

// Peek's dev install is Craft 5.10, without the MCP server. The stand-ins mirror McpActor and the
// SDK classes; tests/harness/mcp.php calls the same tools through Craft 5.12.1's real server.
require_once dirname(__DIR__) . '/_stubs/mcp.php';

/**
 * Peek's MCP tools, called directly as the actor Craft's server would sign in.
 */
class McpToolsTest extends PeekTestCase
{
    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
        parent::_after();
    }

    private function as(User $user): void
    {
        Craft::$app->getUser()->setIdentity($user);
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = User::find()->id($this->adminId())->one();

        return $user;
    }

    private function draftTools(): DraftTools
    {
        return new DraftTools(new McpActor());
    }

    private function releaseTools(): ReleaseTools
    {
        return new ReleaseTools(new McpActor());
    }

    private function release(string $name = 'MCP release'): Release
    {
        $release = new Release();
        $release->siteId = $this->primarySiteId();
        $release->name = $name;
        $release->createdBy = $this->adminId();
        $this->assertTrue($this->releases()->saveRelease($release));

        return $release;
    }

    private function isApplied(Entry $draft): bool
    {
        return !Entry::find()->id($draft->id)->drafts(true)->status(null)->exists();
    }

    private function assertToolError(string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a ToolCallException: $message");
        } catch (ToolCallException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    /**
     * @param string[] $extra
     */
    private function peekUser(array $extra = []): User
    {
        return $this->createUser(array_merge(['accesscp', 'peek:accessplugin', 'peek:viewdiffs', 'peek:managereleases'], $extra));
    }

    // Registration
    // -------------------------------------------------------------------------

    public function testMcpIsOnlyAvailableFromCraft5121(): void
    {
        $this->assertFalse(Plugin::mcpAvailable('5.10.5'));
        $this->assertFalse(Plugin::mcpAvailable('5.12.0'));
        $this->assertTrue(Plugin::mcpAvailable('5.12.1'));
        $this->assertTrue(Plugin::mcpAvailable('5.13.0'));
        // This install's own Craft is older, so Peek registered nothing.
        $this->assertFalse(Plugin::mcpAvailable());
    }

    public function testNoActorIsRefused(): void
    {
        $this->assertToolError('Authentication is required.', fn() => $this->draftTools()->listDrafts());
    }

    // peek_drafts_list
    // -------------------------------------------------------------------------

    public function testDraftsListReturnsTheDraftShape(): void
    {
        $draft = $this->createDraft($this->createEntry('Listed'), ['title' => 'Listed draft']);
        $this->as($this->admin());

        $result = $this->draftTools()->listDrafts();

        $this->assertSame(1, $result['total']);
        $row = $result['drafts'][0];
        $this->assertSame([
            'id', 'draftId', 'canonicalId', 'title', 'draftName', 'section', 'site', 'creator',
            'dateUpdated', 'stale', 'cpEditUrl', 'diffUrl',
        ], array_keys($row));
        $this->assertSame($draft->id, $row['id']);
        $this->assertSame($draft->draftId, $row['draftId']);
        $this->assertSame($draft->getCanonicalId(), $row['canonicalId']);
        $this->assertSame('Listed draft', $row['title']);
        $this->assertSame('testSection', $row['section']);
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->handle, $row['site']);
        $this->assertFalse($row['stale']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $row['dateUpdated']);
        $this->assertStringContainsString("peek/diff/{$draft->id}", $row['diffUrl']);
    }

    public function testDraftsListPagesAndFiltersStale(): void
    {
        $old = $this->createDraft($this->createEntry('Old'), ['title' => 'Old draft']);
        $this->createDraft($this->createEntry('New'), ['title' => 'New draft']);
        $this->backdateDraft($old, '-30 days');
        $this->as($this->admin());

        $page = $this->draftTools()->listDrafts(limit: 1, offset: 1);
        $this->assertSame(2, $page['total']);
        $this->assertCount(1, $page['drafts']);

        $stale = $this->draftTools()->listDrafts(staleOnly: true);
        $this->assertSame(1, $stale['total']);
        $this->assertSame($old->id, $stale['drafts'][0]['id']);
        $this->assertTrue($stale['drafts'][0]['stale']);
    }

    public function testDraftsListOnlyShowsDraftsTheActorCanView(): void
    {
        $this->createDraft($this->createEntry('Hidden'), ['title' => 'Hidden draft']);

        $this->as($this->peekUser());
        $this->assertSame(['total' => 0, 'drafts' => []], $this->draftTools()->listDrafts());

        $this->as($this->peekUser($this->sectionPermissions('view')));
        $this->assertSame(1, $this->draftTools()->listDrafts()['total']);
    }

    public function testDraftsListNeedsThePeekPermission(): void
    {
        $this->as($this->createUser(['accesscp']));

        $this->assertToolError('You don’t have permission to do that.', fn() => $this->draftTools()->listDrafts());
    }

    public function testDraftsListRejectsAnUnknownSite(): void
    {
        $this->as($this->admin());

        $this->assertToolError('Site not found.', fn() => $this->draftTools()->listDrafts(siteId: 999999));
    }

    public function testOnMultiSiteADraftNeedsItsSite(): void
    {
        $primary = Craft::$app->getSites()->getPrimarySite();
        $second = new \craft\models\Site([
            'groupId' => $primary->groupId,
            'name' => 'Second',
            'handle' => 'second' . uniqid(),
            'language' => 'en-US',
            'hasUrls' => false,
        ]);
        $this->assertTrue(Craft::$app->getSites()->saveSite($second), json_encode($second->getErrors()));
        $this->assertTrue(Craft::$app->getIsMultiSite());

        $draft = $this->createDraft($this->createEntry('Sited'), ['title' => 'Sited draft']);
        $release = $this->release();
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $draft->getCanonicalId(), $draft->id));

        // Every view right on the section, but no site.
        $this->as($this->peekUser($this->sectionPermissions('view')));
        $this->assertSame(0, $this->draftTools()->listDrafts()['total']);
        $this->assertToolError('Draft not found.', fn() => $this->draftTools()->draftDiff($draft->id));
        $this->assertNull($this->releaseTools()->getRelease($release->id)['entries'][0]['title']);
        $this->assertFalse($this->draftService()->canViewEntry($draft, Craft::$app->getUser()->getIdentity()));

        $this->as($this->peekUser([...$this->sectionPermissions('view'), "editsite:{$primary->uid}"]));
        $this->assertSame(1, $this->draftTools()->listDrafts()['total']);
        $this->assertSame($draft->id, $this->draftTools()->draftDiff($draft->id)['draft']['id']);
        $this->assertSame('Sited draft', $this->releaseTools()->getRelease($release->id)['entries'][0]['title']);
        $this->assertSame(['total' => 0, 'drafts' => []], $this->draftTools()->listDrafts(siteId: $second->id), 'A site they can’t edit lists nothing.');
    }

    // peek_draft_diff
    // -------------------------------------------------------------------------

    public function testDraftDiffReturnsChangedFieldsAsPlainValues(): void
    {
        $canonical = $this->createEntry('Before', ['body' => 'old body']);
        $draft = $this->createDraft($canonical, ['title' => 'After', 'enabled' => false], ['body' => 'new body']);
        $this->as($this->admin());

        $result = $this->draftTools()->draftDiff($draft->id);

        $this->assertSame($draft->id, $result['draft']['id']);
        $this->assertSame(3, $result['changedCount']);
        $fields = array_column($result['fields'], null, 'handle');
        $this->assertSame(['title', 'enabled', 'body'], array_keys($fields));
        $this->assertSame(['handle', 'label', 'type', 'changed', 'old', 'new', 'error'], array_keys($fields['title']));
        $this->assertSame('Before', $fields['title']['old']);
        $this->assertSame('After', $fields['title']['new']);
        $this->assertTrue($fields['enabled']['old']);
        $this->assertFalse($fields['enabled']['new']);
        $this->assertSame('new body', $fields['body']['new']);
        $this->assertNull($fields['body']['error']);

        $all = $this->draftTools()->draftDiff($draft->id, includeUnchanged: true);
        $this->assertContains('slug', array_column($all['fields'], 'handle'));
        $this->assertSame(3, $all['changedCount']);
    }

    public function testDraftDiffHidesDraftsTheActorCannotView(): void
    {
        $draft = $this->createDraft($this->createEntry('Secret'), ['title' => 'Secret draft']);

        $this->as($this->peekUser());
        $this->assertToolError('Draft not found.', fn() => $this->draftTools()->draftDiff($draft->id));
        $this->assertToolError('Draft not found.', fn() => $this->draftTools()->draftDiff(999999));

        $this->as($this->peekUser($this->sectionPermissions('view')));
        $this->assertSame($draft->id, $this->draftTools()->draftDiff($draft->id)['draft']['id']);
    }

    public function testDraftDiffNeverShowsAProvisionalDraft(): void
    {
        $canonical = $this->createEntry('Provisional');
        $draft = Craft::$app->getDrafts()->createDraft($canonical, $this->adminId(), provisional: true);
        $this->as($this->admin());

        $this->assertToolError('Draft not found.', fn() => $this->draftTools()->draftDiff($draft->id));
    }

    public function testDraftDiffNeedsTheDiffPermission(): void
    {
        $draft = $this->createDraft($this->createEntry('No diff'));
        $this->as($this->createUser(['accesscp', 'peek:accessplugin', ...$this->sectionPermissions('view')]));

        $this->assertToolError('You don’t have permission to do that.', fn() => $this->draftTools()->draftDiff($draft->id));
    }

    // peek_releases_list / peek_release_get
    // -------------------------------------------------------------------------

    public function testReleasesListFiltersByStatus(): void
    {
        $ready = $this->release('Ready one');
        $ready->status = ReleaseStatus::Ready;
        $this->assertTrue($this->releases()->saveRelease($ready));
        $this->release('Draft one');
        $this->as($this->admin());

        $all = $this->releaseTools()->listReleases();
        $this->assertCount(2, $all['releases']);
        $this->assertSame([
            'source', 'id', 'name', 'status', 'site', 'entryCount', 'scheduledDate', 'publishedDate', 'cpUrl',
        ], array_keys($all['releases'][0]));

        $filtered = $this->releaseTools()->listReleases('ready');
        $this->assertSame([$ready->id], array_column($filtered['releases'], 'id'));
        $this->assertSame('peek', $filtered['releases'][0]['source']);

        $this->assertToolError('Unknown release status: nope', fn() => $this->releaseTools()->listReleases('nope'));
    }

    public function testReleaseGetHidesTitlesOfDraftsTheActorCannotView(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Private');
        $draft = $this->createDraft($canonical, ['title' => 'Private draft title']);
        $this->assertTrue($this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id));

        $this->as($this->admin());
        $asAdmin = $this->releaseTools()->getRelease($release->id);
        $this->assertSame('Private draft title', $asAdmin['entries'][0]['title']);
        $this->assertSame([], $asAdmin['problems']);

        $this->as($this->peekUser());
        $asEditor = $this->releaseTools()->getRelease($release->id);
        $this->assertSame([
            'canonicalId' => $canonical->id,
            'draftId' => $draft->id,
            'title' => null,
            'status' => 'pending',
        ], $asEditor['entries'][0]);
        $this->assertCount(1, $asEditor['problems'], 'The editor may not apply the draft.');
        $this->assertStringNotContainsString('Private draft title', json_encode($asEditor));
    }

    public function testReleaseGetNotFound(): void
    {
        $this->as($this->admin());

        $this->assertToolError('Release not found.', fn() => $this->releaseTools()->getRelease(999999));
    }

    public function testReleaseToolsNeedManageReleases(): void
    {
        $release = $this->release();
        $this->as($this->createUser(['accesscp', 'peek:accessplugin']));

        $this->assertToolError('You don’t have permission to do that.', fn() => $this->releaseTools()->listReleases());
        $this->assertToolError('You don’t have permission to do that.', fn() => $this->releaseTools()->getRelease($release->id));
    }

    // peek_release_add_draft
    // -------------------------------------------------------------------------

    public function testAddDraftAddsADraftTheActorCouldApply(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Addable');
        $draft = $this->createDraft($canonical);
        $this->as($this->peekUser($this->sectionPermissions('publish')));

        $result = $this->releaseTools()->addDraft($release->id, $draft->id);

        $this->assertSame(1, $result['entryCount']);
        $this->assertSame($canonical->id, $result['entries'][0]['canonicalId']);
        $this->assertToolError('The draft is already in this release.', fn() => $this->releaseTools()->addDraft($release->id, $draft->id));
    }

    public function testAddDraftRefusesDraftsTheActorCannotApply(): void
    {
        $release = $this->release();
        $draft = $this->createDraft($this->createEntry('Guarded'));

        $this->as($this->peekUser());
        $this->assertToolError('Draft not found.', fn() => $this->releaseTools()->addDraft($release->id, $draft->id));

        $this->as($this->peekUser($this->sectionPermissions('view')));
        $this->assertToolError('You can’t publish this draft, so you can’t add it to a release.', fn() => $this->releaseTools()->addDraft($release->id, $draft->id));

        $this->assertSame(0, $this->releases()->getReleaseById($release->id)->getEntryCount());
    }

    public function testAddDraftRefusesAPublishedReleaseAndTheLimit(): void
    {
        $draft = $this->createDraft($this->createEntry('Late'));
        $this->as($this->admin());

        $published = $this->release('Done');
        $published->status = ReleaseStatus::Published;
        $this->assertTrue($this->releases()->saveRelease($published));
        $this->assertToolError('A release that is publishing or published can’t be changed.', fn() => $this->releaseTools()->addDraft($published->id, $draft->id));

        $settings = Plugin::getInstance()->getSettings();
        $max = $settings->maxEntriesPerRelease;
        $settings->maxEntriesPerRelease = 1;

        try {
            $full = $this->release('Full');
            $first = $this->createDraft($this->createEntry('First'));
            $this->releaseTools()->addDraft($full->id, $first->id);
            $this->assertToolError('The release already has the most entries allowed (1).', fn() => $this->releaseTools()->addDraft($full->id, $draft->id));
        } finally {
            $settings->maxEntriesPerRelease = $max;
        }
    }

    // peek_release_publish
    // -------------------------------------------------------------------------

    public function testPublishAsTheActorAppliesEveryDraft(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Go');
        $draft = $this->createDraft($canonical);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);
        $publisher = $this->peekUser(['peek:publishreleases', ...$this->sectionPermissions('publish')]);
        $this->as($publisher);

        $dry = $this->releaseTools()->publishRelease($release->id, dryRun: true);
        $this->assertSame(['published' => false, 'dryRun' => true, 'problems' => []], array_slice($dry, 0, 3, true));
        $this->assertFalse($this->isApplied($draft));

        $result = $this->releaseTools()->publishRelease($release->id);

        $this->assertTrue($result['published']);
        $this->assertSame('published', $result['release']['status']);
        $this->assertTrue($this->isApplied($draft));
        $this->assertSame($publisher->id, $this->releases()->getReleaseById($release->id)->publishedBy);

        $again = $this->releaseTools()->publishRelease($release->id);
        $this->assertFalse($again['published']);
        $this->assertSame(['The release is already published.'], $again['problems']);
    }

    public function testPublishRefusesWhenTheActorCannotApplyADraft(): void
    {
        $release = $this->release();
        $canonical = $this->createEntry('Not yours');
        $draft = $this->createDraft($canonical, ['title' => 'Not yours draft']);
        $this->releases()->addEntryToRelease($release->id, $canonical->id, $draft->id);
        $this->as($this->peekUser(['peek:publishreleases']));

        $result = $this->releaseTools()->publishRelease($release->id);

        $this->assertFalse($result['published']);
        $this->assertCount(1, $result['problems']);
        $this->assertStringContainsString("Entry #{$canonical->id}", $result['problems'][0]);
        $this->assertStringNotContainsString('Not yours draft', json_encode($result));
        $this->assertFalse($this->isApplied($draft));
        $this->assertSame(ReleaseStatus::Draft, $this->releases()->getReleaseById($release->id)->status);
    }

    public function testPublishNeedsThePublishPermission(): void
    {
        $release = $this->release();
        $this->as($this->peekUser($this->sectionPermissions('publish')));

        $this->assertToolError('You don’t have permission to do that.', fn() => $this->releaseTools()->publishRelease($release->id));
    }

    public function testPublishLeavesAReleaseTheQueueIsPublishing(): void
    {
        $release = $this->release();
        $release->status = ReleaseStatus::Publishing;
        $this->assertTrue($this->releases()->saveRelease($release));
        $this->as($this->admin());

        $result = $this->releaseTools()->publishRelease($release->id);

        $this->assertFalse($result['published']);
        $this->assertSame(['The release is already being published by the queue.'], $result['problems']);
    }
}
