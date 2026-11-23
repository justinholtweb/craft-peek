<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Support;

use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Peek\Data\FieldDiff;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use Throwable;

use function CraftCms\Cms\cp_url;

/**
 * The one shape drafts, diffs and releases take outside the CP: MCP tools and GraphQL both use it,
 * and Peek 5's MCP tools return the same keys.
 */
final class Presenter
{
    /**
     * @return array{id: int, draftId: int|null, canonicalId: int, title: string|null, draftName: string|null, section: string|null, sectionHandle: string|null, site: string|null, creator: string|null, dateUpdated: string|null, stale: bool, cpEditUrl: string|null, diffUrl: string}
     */
    public static function draft(Entry $draft, int $staleDays): array
    {
        $section = null;
        try {
            $section = $draft->getSection();
        } catch (Throwable) {
        }

        $creator = null;
        try {
            $creator = $draft->getCreator()?->getFriendlyName();
        } catch (Throwable) {
        }

        $updated = $draft->dateUpdated;

        return [
            'id' => (int) $draft->id,
            'draftId' => $draft->draftId !== null ? (int) $draft->draftId : null,
            'canonicalId' => (int) $draft->getCanonicalId(),
            'title' => $draft->title !== null ? (string) $draft->title : null,
            'draftName' => $draft->draftName,
            'section' => $section !== null ? $section->name : null,
            'sectionHandle' => $section?->handle,
            'site' => $draft->getSite()->handle,
            'creator' => $creator,
            'dateUpdated' => $updated?->format(DATE_ATOM),
            'stale' => $updated !== null && $updated->getTimestamp() < time() - $staleDays * 86400,
            'cpEditUrl' => $draft->getCpEditUrl(),
            'diffUrl' => cp_url("peek/diff/{$draft->id}", ['siteId' => $draft->siteId]),
        ];
    }

    /**
     * @return array{handle: string, label: string, type: string, changed: bool, old: mixed, new: mixed, error: string|null}
     */
    public static function fieldDiff(FieldDiff $diff): array
    {
        return [
            'handle' => $diff->handle,
            'label' => $diff->label,
            'type' => $diff->type,
            'changed' => $diff->hasChanges,
            'old' => $diff->oldValue,
            'new' => $diff->newValue,
            'error' => $diff->error,
        ];
    }

    /**
     * @param  list<array{canonicalId: int, draftId: int|null, title?: string|null, status: string}>|null  $entries
     * @param  list<string>|null  $problems
     * @return array<string, mixed>
     */
    public static function release(ReleaseSummary $release, ?array $entries = null, ?array $problems = null): array
    {
        $data = [
            'source' => $release->source,
            'id' => $release->id,
            'name' => $release->name,
            'status' => $release->status,
            'site' => $release->site,
            'entryCount' => $release->entryCount,
            'scheduledDate' => $release->scheduledAt?->toAtomString(),
            'publishedDate' => $release->publishedAt?->toAtomString(),
            'cpUrl' => $release->cpUrl,
        ];

        if ($entries !== null) {
            $data['entries'] = $entries;
        }

        if ($problems !== null) {
            $data['problems'] = $problems;
        }

        return $data;
    }
}
