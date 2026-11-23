<?php

declare(strict_types=1);

use CraftCms\Cms\Cms;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Http\Request;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Mcp\PeekTools;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;
use Mcp\Exception\ToolCallException;

const MCP_EDITOR = [
    'peek:accessPlugin', 'peek:viewDiffs', 'peek:manageReleases', 'peek:publishReleases',
    'viewEntries:{section}', 'saveEntries:{section}', 'viewPeerEntries:{section}', 'savePeerEntries:{section}',
    'viewPeerEntryDrafts:{section}', 'savePeerEntryDrafts:{section}',
];

/** MCP reads the user from Craft's auth guard, not Laravel's default one. */
function actAs(object $test, User $user): void
{
    $test->actingAs($user, Cms::config()->authGuard);

    // Outside an HTTP request the request has no user resolver; the MCP server's would.
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);
    app()->instance(McpActor::class, new McpActor($request));
}

function tools(): PeekTools
{
    return app(PeekTools::class);
}

it('lists only drafts the user can open', function () {
    $this->createDraft($this->createEntry('Visible'), ['title' => 'Visible draft']);

    actAs($this, $this->admin());
    expect(tools()->listDrafts()['drafts'][0])->toMatchArray(['title' => 'Visible draft', 'stale' => false]);

    actAs($this, $this->userWith(['peek:accessPlugin']));
    expect(tools()->listDrafts())->toBe(['total' => 0, 'drafts' => []]);
});

it('diffs a draft as text', function () {
    $draft = $this->createDraft($this->createEntry('Body', ['body' => 'old words']), [], ['body' => 'new words']);
    actAs($this, $this->userWith(MCP_EDITOR));

    $result = tools()->diffDraft($draft->id);

    expect($result['changedCount'])->toBe(1)
        ->and($result['fields'][0])->toMatchArray(['handle' => 'body', 'changed' => true, 'old' => 'old words', 'new' => 'new words']);
});

it('won’t diff a draft the user can’t view, and doesn’t say whether it exists', function () {
    $draft = $this->createDraft($this->createEntry('Secret'), ['title' => 'Secret draft']);
    actAs($this, $this->userWith(['peek:accessPlugin', 'peek:viewDiffs']));

    expect(fn () => tools()->diffDraft($draft->id))->toThrow(ToolCallException::class, "Draft {$draft->id} not found.");
    expect(fn () => tools()->diffDraft(999999))->toThrow(ToolCallException::class, 'Draft 999999 not found.');
});

it('adds a draft and publishes the release as the user', function () {
    $canonical = $this->createEntry('Ship', ['body' => 'old']);
    $draft = $this->createDraft($canonical, [], ['body' => 'via mcp']);
    $release = Release::query()->create(['name' => 'Agent release', 'siteId' => Sites::getPrimarySite()->id]);
    $editor = $this->userWith(MCP_EDITOR);
    actAs($this, $editor);

    $added = tools()->addDraft($release->id, $draft->id);
    expect($added['entries'][0])->toMatchArray(['draftId' => $draft->id, 'title' => $draft->title]);

    $dry = tools()->publishRelease($release->id, dryRun: true);
    expect($dry)->toMatchArray(['published' => false, 'dryRun' => true, 'problems' => []])
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Draft);

    $done = tools()->publishRelease($release->id);
    expect($done['published'])->toBeTrue()
        ->and($release->refresh()->status)->toBe(ReleaseStatus::Published)
        ->and($release->publishedBy)->toBe($editor->id);
});

it('won’t publish for a user without the publish permission', function () {
    $release = Release::query()->create(['name' => 'Locked', 'siteId' => Sites::getPrimarySite()->id]);
    app(Releases::class)->addDraft($release, $this->createDraft($this->createEntry('A'), ['title' => 'A2']), null);
    actAs($this, $this->userWith(array_values(array_diff(MCP_EDITOR, ['peek:publishReleases']))));

    expect(fn () => tools()->publishRelease($release->id))->toThrow(ToolCallException::class, 'can’t publish');
    expect($release->refresh()->status)->toBe(ReleaseStatus::Draft);
});

it('won’t show releases to someone who can’t manage them', function () {
    Release::query()->create(['name' => 'Hidden', 'siteId' => Sites::getPrimarySite()->id]);
    actAs($this, $this->userWith(['peek:accessPlugin']));

    expect(fn () => tools()->listReleases())->toThrow(ToolCallException::class);
});
