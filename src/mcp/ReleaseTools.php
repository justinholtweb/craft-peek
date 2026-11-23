<?php

namespace justinholtweb\peek\mcp;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\helpers\Shapes;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseEntryRecord;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Peek's release tools for Craft's MCP server (Craft 5.12.1+; registered by Plugin::registerMcp()).
 *
 * The actor is checked as the release screens check a signed-in user: adding a draft needs the
 * rights to apply it in Craft's editor, and publishing applies every draft as the actor — never as
 * the system — all or nothing. A draft's title is only shown to someone who could open it.
 */
class ReleaseTools
{
    public function __construct(private McpActor $actor)
    {
    }

    /**
     * @return array{releases: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_releases_list',
        description: 'Lists Peek releases (groups of drafts published together), newest first. Filter by status: draft, ready, scheduled, publishing, published or failed.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:manageReleases')]
    public function listReleases(
        #[Schema(enum: ['draft', 'ready', 'scheduled', 'publishing', 'published', 'failed'])]
        ?string $status = null,
    ): array {
        $this->user('peek:manageReleases');

        $filter = null;
        if ($status !== null && $status !== '') {
            $filter = ReleaseStatus::tryFrom($status);
            if ($filter === null) {
                throw new ToolCallException("Unknown release status: $status");
            }
        }

        $releases = Plugin::getInstance()->releases->getAllReleases();

        if ($filter !== null) {
            $releases = array_filter($releases, fn(Release $release) => $release->status === $filter);
        }

        return [
            'releases' => array_values(array_map([Shapes::class, 'release'], $releases)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'peek_release_get',
        description: 'Gets a Peek release with its entries and the problems that would stop you publishing it now (missing drafts, drafts you aren’t allowed to apply). An entry’s title is null when you can’t view its draft.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:manageReleases')]
    public function getRelease(
        #[Schema(minimum: 1)]
        int $releaseId,
    ): array {
        $user = $this->user('peek:manageReleases');

        return $this->detail($this->release($releaseId), $user);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'peek_release_add_draft',
        description: 'Adds a saved draft to a Peek release. You must be allowed to apply the draft in Craft’s editor, and the release must not be publishing or published.',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:manageReleases')]
    public function addDraft(
        #[Schema(minimum: 1)]
        int $releaseId,
        #[Schema(minimum: 1, description: 'The draft’s element ID (the `id` from peek_drafts_list).')]
        int $draftId,
    ): array {
        $user = $this->user('peek:manageReleases');
        $releases = Plugin::getInstance()->releases;
        $release = $this->release($releaseId);

        $draft = Entry::find()->id($draftId)->drafts(true)->provisionalDrafts(null)->status(null)->site('*')->one();
        $elements = Craft::$app->getElements();

        // Same answer for "doesn't exist" and "can't see it".
        if ($draft === null || $draft->isProvisionalDraft || !Plugin::getInstance()->drafts->canViewEntry($draft, $user)) {
            throw new ToolCallException('Draft not found.');
        }

        if (!$elements->canSave($draft, $user) || !$elements->canSaveCanonical($draft, $user)) {
            throw new ToolCallException('You can’t publish this draft, so you can’t add it to a release.');
        }

        if (!$releases->isEditable($release)) {
            throw new ToolCallException('A release that is publishing or published can’t be changed.');
        }

        if (ReleaseEntryRecord::find()->where(['releaseId' => $release->id, 'draftId' => $draft->id])->exists()) {
            throw new ToolCallException('The draft is already in this release.');
        }

        $max = Plugin::getInstance()->getSettings()->maxEntriesPerRelease;
        if ($release->getEntryCount() >= $max) {
            throw new ToolCallException("The release already has the most entries allowed ($max).");
        }

        if (!$releases->addEntryToRelease((int)$release->id, (int)$draft->getCanonicalId(), (int)$draft->id)) {
            throw new ToolCallException('Could not add the draft to the release.');
        }

        return $this->detail($this->release($releaseId), $user);
    }

    /**
     * @return array{published: bool, dryRun: bool, problems: list<string>, release: array<string, mixed>}
     */
    #[McpTool(
        name: 'peek_release_publish',
        description: 'Publishes a Peek release now, as you: every draft in it is applied, or none is. Refused if any draft is missing or is one you aren’t allowed to apply. dryRun reports the problems without publishing.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:manageReleases')]
    #[RequiresPermission('peek:publishReleases')]
    public function publishRelease(
        #[Schema(minimum: 1)]
        int $releaseId,
        bool $dryRun = false,
    ): array {
        $user = $this->user('peek:manageReleases', 'peek:publishReleases');
        $releases = Plugin::getInstance()->releases;
        $release = $this->release($releaseId);

        $problems = match ($release->status) {
            ReleaseStatus::Published => [Craft::t('peek', 'The release is already published.')],
            // The scheduler sets Publishing before it queues the job; publishing here too would race it.
            ReleaseStatus::Publishing => [Craft::t('peek', 'The release is already being published by the queue.')],
            default => $this->problems($release, $user),
        };

        $published = false;

        if ($problems === [] && !$dryRun) {
            $published = $releases->publishRelease($release, $user);

            if (!$published) {
                $problems = $release->getErrors('entries')
                    ?: [Craft::t('peek', 'Applying a draft failed, so nothing was published. Check the peek log.')];
            }
        }

        return [
            'published' => $published,
            'dryRun' => $dryRun,
            'problems' => array_values($problems),
            'release' => $this->detail($this->release($releaseId), $user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Release $release, User $user): array
    {
        return Shapes::release($release) + [
            'entries' => Shapes::releaseEntries($release, $user),
            // A published release's drafts are gone by design; only a pending one has problems.
            'problems' => $release->status === ReleaseStatus::Published ? [] : $this->problems($release, $user),
        ];
    }

    /**
     * What would stop `$user` publishing the release now, as publishRelease() would decide it.
     *
     * @return list<string>
     */
    private function problems(Release $release, User $user): array
    {
        $releases = Plugin::getInstance()->releases;
        $problems = $releases->validateRelease($release);

        return array_values($problems === [] ? $releases->unauthorizedEntries($release, $user) : $problems);
    }

    private function release(int $releaseId): Release
    {
        return Plugin::getInstance()->releases->getReleaseById($releaseId)
            ?? throw new ToolCallException('Release not found.');
    }

    /**
     * The actor, if they hold every permission given. Craft's server already hides the tool from
     * anyone without them; this keeps the handler safe when it's called some other way.
     */
    private function user(string ...$permissions): User
    {
        $user = $this->actor->user();

        foreach (['accessCp', ...$permissions] as $permission) {
            if (!$user->can($permission)) {
                throw new ToolCallException('You don’t have permission to do that.');
            }
        }

        return $user;
    }
}
