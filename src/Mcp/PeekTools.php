<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Mcp;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Mcp\Attributes\RequiresPermission;
use CraftCms\Cms\Mcp\McpActor;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use JustinHoltWeb\Peek\Data\FieldDiff;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use JustinHoltWeb\Peek\Support\Presenter;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Peek's tools for Craft's MCP server. Names and shapes match Peek 5's (Craft 5.12.1+).
 *
 * Every tool acts as the signed-in MCP user, with the checks the control panel makes, and never as
 * the system: an agent can only see the drafts, and publish the releases, its user could.
 */
class PeekTools
{
    public function __construct(
        private readonly McpActor $actor,
        private readonly PendingDrafts $drafts,
        private readonly Diff $diff,
        private readonly Releases $peekReleases,
        private readonly ReleaseSystem $releases,
        private readonly Settings $settings,
    ) {}

    /**
     * @return array{total: int, drafts: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_drafts_list',
        description: 'Lists saved drafts of entries that you can open, most recently saved first, with whether each is stale (no save within Peek’s threshold). Provisional drafts (unsaved edits) are never included.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    public function listDrafts(
        #[Schema(description: 'Only drafts with no save within Peek’s stale-draft threshold.')]
        bool $staleOnly = false,
        #[Schema(minimum: 1, description: 'Only drafts in this site.')]
        ?int $siteId = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $user = $this->user();

        if ($siteId !== null && Sites::isMultiSite() && ! $user->can('editSite:'.Sites::getSiteById($siteId, true)?->uid)) {
            return ['total' => 0, 'drafts' => []];
        }

        $drafts = $staleOnly
            ? $this->drafts->stale($this->settings->staleDraftDays, $siteId, $user)
            : $this->drafts->all($siteId, $user);

        return [
            'total' => count($drafts),
            'drafts' => array_map(
                fn (Entry $draft) => Presenter::draft($draft, $this->settings->staleDraftDays),
                array_slice($drafts, $offset, $limit),
            ),
        ];
    }

    /**
     * @return array{draft: array<string, mixed>, changedCount: int, fields: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_draft_diff',
        description: 'Compares a draft with its live entry, field by field, as text: what each changed field was and what it will be. Matrix and Content Block fields are spelled out block by block.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    #[RequiresPermission(Plugin::PERMISSION_VIEW_DIFFS)]
    public function diffDraft(
        #[Schema(minimum: 1, description: 'The draft’s element ID (`id` from peek_drafts_list).')]
        int $draftId,
        #[Schema(minimum: 1)]
        ?int $siteId = null,
        #[Schema(description: 'Include fields that didn’t change.')]
        bool $includeUnchanged = false,
    ): array {
        $user = $this->user();

        $draft = Entry::find()->id($draftId)->siteId($siteId)->drafts()->provisionalDrafts(false)->status(null)->one();
        $canonical = $draft instanceof Entry ? $draft->getCanonical() : null;

        // One message for "missing" and "not yours", so the tool isn't an existence oracle.
        if (! $draft instanceof Entry || ! $canonical instanceof Entry || $canonical->id === $draft->id
            || ! PendingDrafts::canView($draft, $user) || ! $user->can('view', $canonical)) {
            throw new ToolCallException('Draft not found.');
        }

        $diffs = $this->diff->diffEntry($draft, $canonical);
        $changed = array_values(array_filter($diffs, fn (FieldDiff $d) => $d->hasChanges));

        return [
            'draft' => Presenter::draft($draft, $this->settings->staleDraftDays),
            'changedCount' => count($changed),
            'fields' => array_map(Presenter::fieldDiff(...), $includeUnchanged ? $diffs : $changed),
        ];
    }

    /**
     * @return array{releases: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_releases_list',
        description: 'Lists releases, newest first. On Craft Pro and Enterprise these are Craft’s own releases plus any of Peek’s not yet finished; on Solo and Team, Peek’s.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    public function listReleases(
        #[Schema(description: 'Only releases in this status (draft, ready, scheduled, publishing, published, failed).')]
        ?string $status = null,
    ): array {
        $user = $this->user();

        $releases = array_filter(
            $this->releases->all(status: $status),
            fn (ReleaseSummary $release) => $this->canManage($user, $release->source),
        );

        if ($releases === [] && ! $this->canManage($user, ReleaseSummary::SOURCE_PEEK) && ! $this->canManage($user, ReleaseSummary::SOURCE_CRAFT)) {
            throw new ToolCallException('You can’t manage releases.');
        }

        return ['releases' => array_values(array_map(fn (ReleaseSummary $r) => Presenter::release($r), $releases))];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'peek_release_get',
        description: 'One release with its drafts and anything that would stop you publishing it. Titles are given only for drafts you can view.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    public function getRelease(
        #[Schema(minimum: 1)]
        int $releaseId,
        #[Schema(enum: ['peek', 'craft'], description: 'Which release system; defaults to the site’s.')]
        ?string $source = null,
    ): array {
        [$user, $source, $release] = $this->release($releaseId, $source);

        return $this->present($release, $user);
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'peek_release_add_draft',
        description: 'Adds a saved draft to a release. You must be able to publish the draft yourself (save it and its entry).',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    public function addDraft(
        #[Schema(minimum: 1)]
        int $releaseId,
        #[Schema(minimum: 1, description: 'The draft’s element ID.')]
        int $draftId,
        #[Schema(enum: ['peek', 'craft'])]
        ?string $source = null,
    ): array {
        [$user, $source] = $this->release($releaseId, $source);

        $draft = $this->peekReleases->draft($draftId);

        if ($draft === null || ! PendingDrafts::canView($draft, $user)) {
            throw new ToolCallException('Draft not found.');
        }

        if (($error = $this->releases->addDraft($source, $releaseId, $draft, $user)) !== null) {
            throw new ToolCallException($error);
        }

        return $this->present($this->releases->find($source, $releaseId, withEntries: true) ?? throw new ToolCallException('Release not found.'), $user);
    }

    /**
     * @return array{published: bool, dryRun: bool, problems: list<string>, release: array<string, mixed>}
     */
    #[McpTool(
        name: 'peek_release_publish',
        description: 'Publishes a release now, as you: every draft is applied, or none is. With dryRun, only reports what would stop it.',
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission(Plugin::PERMISSION_ACCESS)]
    public function publishRelease(
        #[Schema(minimum: 1)]
        int $releaseId,
        #[Schema(enum: ['peek', 'craft'])]
        ?string $source = null,
        bool $dryRun = false,
    ): array {
        [$user, $source, $release] = $this->release($releaseId, $source);

        $permission = $source === ReleaseSummary::SOURCE_CRAFT ? 'publishReleases' : Plugin::PERMISSION_PUBLISH_RELEASES;
        if (! $user->can($permission)) {
            throw new ToolCallException('You can’t publish releases.');
        }

        if (in_array($release->status, ['published', 'publishing'], true)) {
            $problems = [$release->status === 'published' ? 'The release is already published.' : 'The release is already being published.'];
        } else {
            $problems = $this->releases->problems($source, $releaseId, $user);

            if (! $dryRun && $problems === []) {
                $problems = $this->releases->publish($source, $releaseId, $user);
            }
        }

        $after = $this->releases->find($source, $releaseId, withEntries: true) ?? $release;

        return [
            'published' => ! $dryRun && $problems === [],
            'dryRun' => $dryRun,
            'problems' => $problems,
            'release' => $this->present($after, $user),
        ];
    }

    private function user(): CraftUser
    {
        return $this->actor->user();
    }

    private function canManage(CraftUser $user, string $source): bool
    {
        return $source === ReleaseSummary::SOURCE_CRAFT
            ? $user->can('manageReleases') || $user->can('publishReleases')
            : $user->can(Plugin::PERMISSION_MANAGE_RELEASES);
    }

    /**
     * @return array{0: CraftUser, 1: string, 2: ReleaseSummary}
     */
    private function release(int $releaseId, ?string $source): array
    {
        $user = $this->user();
        $source ??= $this->releases->primarySource();

        if (! in_array($source, [ReleaseSummary::SOURCE_PEEK, ReleaseSummary::SOURCE_CRAFT], true)) {
            throw new ToolCallException('source must be "peek" or "craft".');
        }

        if (! $this->canManage($user, $source)) {
            throw new ToolCallException('You can’t manage releases.');
        }

        $release = $this->releases->find($source, $releaseId, withEntries: true);

        if ($release === null) {
            throw new ToolCallException('Release not found.');
        }

        return [$user, $source, $release];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ReleaseSummary $release, CraftUser $user, bool $withProblems = true): array
    {
        $entries = array_map(function (array $entry) use ($user): array {
            $subject = $entry['draftId'] !== null ? $this->peekReleases->draft($entry['draftId']) : null;
            $subject ??= Entry::find()->id($entry['canonicalId'])->status(null)->site('*')->one();

            return $entry + [
                'title' => $subject instanceof Entry && PendingDrafts::canView($subject, $user) ? $subject->title : null,
            ];
        }, $release->entries);

        $problems = ! $withProblems ? null : ($release->status === 'published'
            ? []
            : $this->releases->problems($release->source, $release->id, $user));

        return Presenter::release($release, $entries, $problems);
    }
}
