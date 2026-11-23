<?php

namespace justinholtweb\peek\mcp;

use Craft;
use craft\elements\User;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use justinholtweb\peek\helpers\Shapes;
use justinholtweb\peek\Plugin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Peek's draft tools for Craft's MCP server (Craft 5.12.1+; registered by Plugin::registerMcp()).
 *
 * Every tool acts as the MCP actor and makes the checks the control panel makes. Plugin services
 * are reached through Plugin::getInstance(): the MCP container would autowire fresh, unconfigured
 * instances if they were constructor arguments. Tool names are final (public approvals are stored
 * by name) and shared with Peek 6.
 */
class DraftTools
{
    public function __construct(private McpActor $actor)
    {
    }

    /**
     * @return array{total: int, drafts: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_drafts_list',
        description: 'Lists saved drafts of entries that are waiting to be applied, newest first — the drafts on Peek’s dashboard. Only drafts you can view are listed. staleOnly lists the ones not updated in Peek’s stale-draft period.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:accessPlugin')]
    public function listDrafts(
        #[Schema(description: 'Only drafts not updated in Peek’s stale-draft period (14 days by default).')]
        bool $staleOnly = false,
        #[Schema(description: 'Only drafts in this site. Defaults to the primary site.')]
        ?int $siteId = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 50,
        #[Schema(minimum: 0)]
        int $offset = 0,
    ): array {
        $user = $this->user('peek:accessPlugin');
        $plugin = Plugin::getInstance();
        $staleDays = $plugin->getSettings()->staleDraftDays;

        if ($siteId !== null && !Craft::$app->getSites()->getSiteById($siteId)) {
            throw new ToolCallException('Site not found.');
        }

        // A site the actor can't edit has nothing in it for them.
        if ($siteId !== null && !$plugin->drafts->canAccessSite($siteId, $user)) {
            return ['total' => 0, 'drafts' => []];
        }

        $drafts = $staleOnly
            ? $plugin->drafts->getStaleDrafts($staleDays, $siteId, $user)
            : $plugin->drafts->getAllPendingDrafts($siteId, $user);

        $page = array_slice($drafts, max(0, $offset), max(1, min(100, $limit)));

        return [
            'total' => count($drafts),
            'drafts' => array_map(fn($draft) => Shapes::draft($draft, $staleDays), $page),
        ];
    }

    /**
     * @return array{draft: array<string, mixed>, changedCount: int, fields: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'peek_draft_diff',
        description: 'Compares a saved draft with its live entry field by field, the way Peek’s Diff screen does. Values are plain text (rich text without its HTML, relations as “Title (#id)” lines); the entry’s enabled state is a boolean. Only changed fields are returned unless includeUnchanged is true.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    #[RequiresPermission('peek:viewDiffs')]
    public function draftDiff(
        #[Schema(minimum: 1, description: 'The draft’s element ID (the `id` from peek_drafts_list, not its `draftId`).')]
        int $draftId,
        #[Schema(description: 'The site to compare in. Defaults to the primary site.')]
        ?int $siteId = null,
        bool $includeUnchanged = false,
    ): array {
        $user = $this->user('peek:viewDiffs');
        $plugin = Plugin::getInstance();

        // Same answer for "doesn't exist" and "can't see it".
        $pair = $plugin->drafts->getViewableDraft($draftId, $siteId, $user);
        if ($pair === null) {
            throw new ToolCallException('Draft not found.');
        }

        [$draft, $canonical] = $pair;
        $diffs = $plugin->diff->diffEntry($draft, $canonical);
        $changed = array_values(array_filter($diffs, fn($diff) => $diff->hasChanges));

        return [
            'draft' => Shapes::draft($draft, $plugin->getSettings()->staleDraftDays),
            'changedCount' => count($changed),
            'fields' => array_map([Shapes::class, 'fieldDiff'], $includeUnchanged ? $diffs : $changed),
        ];
    }

    /**
     * The actor, if they hold `$permission`. Craft's server already hides the tool from anyone
     * without it; this keeps the handler safe when it's called some other way.
     */
    private function user(string $permission): User
    {
        $user = $this->actor->user();

        if (!$user->can('accessCp') || !$user->can($permission)) {
            throw new ToolCallException('You don’t have permission to do that.');
        }

        return $user;
    }
}
