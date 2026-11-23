<?php

namespace justinholtweb\peek\gql\resolvers;

use craft\elements\Entry;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\gql\GqlAccess;
use justinholtweb\peek\helpers\Shapes;
use justinholtweb\peek\models\FieldDiff;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;

/**
 * Resolvers for Peek's GraphQL queries. Each one re-checks the active schema (GqlAccess), so a
 * query that slipped into a schema without the scope, or the public schema, still returns nothing.
 */
class PeekResolver
{
    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function drafts(mixed $source, array $arguments): array
    {
        $schema = GqlAccess::schema();
        if ($schema === null || !GqlAccess::canQueryDrafts($schema)) {
            return [];
        }

        $siteId = isset($arguments['siteId']) ? (int)$arguments['siteId'] : null;
        if ($siteId !== null && !in_array($siteId, GqlAccess::readableSiteIds($schema), true)) {
            return [];
        }

        $plugin = Plugin::getInstance();
        $staleDays = $plugin->getSettings()->staleDraftDays;
        $drafts = !empty($arguments['staleOnly'])
            ? $plugin->drafts->getStaleDrafts($staleDays, $siteId)
            : $plugin->drafts->getAllPendingDrafts($siteId);

        $drafts = array_values(array_filter($drafts, fn(Entry $draft) => GqlAccess::canReadEntry($draft, $schema)));

        $limit = max(1, min(100, (int)($arguments['limit'] ?? 50)));
        $offset = max(0, (int)($arguments['offset'] ?? 0));

        return array_map(fn(Entry $draft) => self::draft($draft, $staleDays), array_slice($drafts, $offset, $limit));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function draftDiff(mixed $source, array $arguments): ?array
    {
        $schema = GqlAccess::schema();
        if ($schema === null || !GqlAccess::canQueryDrafts($schema)) {
            return null;
        }

        $siteId = isset($arguments['siteId']) ? (int)$arguments['siteId'] : null;
        if ($siteId !== null && !in_array($siteId, GqlAccess::readableSiteIds($schema), true)) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $draft = $plugin->drafts->getDraftById((int)$arguments['draftId'], $siteId);
        /** @var Entry|null $canonical */
        $canonical = $draft?->getCanonical();

        if (!$draft || !$canonical || $canonical->id === $draft->id
            || !GqlAccess::canReadEntry($draft, $schema) || !GqlAccess::canReadEntry($canonical, $schema)
        ) {
            return null;
        }

        // Relations show as counts: the schema's scopes cover this draft, not what it relates to
        $diffs = $plugin->diff->diffEntry($draft, $canonical, redactRelations: true);
        $changed = array_values(array_filter($diffs, fn(FieldDiff $diff) => $diff->hasChanges));

        return [
            'draft' => self::draft($draft, $plugin->getSettings()->staleDraftDays),
            'changedCount' => count($changed),
            'fields' => array_map([self::class, 'fieldDiff'], !empty($arguments['includeUnchanged']) ? $diffs : $changed),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<array<string, mixed>>
     */
    public static function releases(mixed $source, array $arguments): array
    {
        if (!GqlAccess::canQueryReleases()) {
            return [];
        }

        $status = null;
        if (isset($arguments['status']) && $arguments['status'] !== '') {
            $status = ReleaseStatus::tryFrom((string)$arguments['status']);
            if ($status === null) {
                return [];
            }
        }

        $releases = Plugin::getInstance()->releases->getAllReleases();
        $rows = [];

        foreach ($releases as $release) {
            if (($status === null || $release->status === $status) && self::canReadRelease($release)) {
                $rows[] = self::release($release);
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function releaseById(mixed $source, array $arguments): ?array
    {
        if (!GqlAccess::canQueryReleases()) {
            return null;
        }

        // Craft 6 also has Craft's own releases; on Craft 5 there are only Peek's.
        $from = $arguments['source'] ?? null;
        if ($from !== null && $from !== 'peek') {
            return null;
        }

        $release = Plugin::getInstance()->releases->getReleaseById((int)$arguments['id']);

        return $release && self::canReadRelease($release) ? self::release($release) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function draft(Entry $draft, int $staleDays): array
    {
        return [
            'id' => (int)$draft->id,
            'draftId' => $draft->draftId,
            'canonicalId' => $draft->getCanonicalId(),
            'title' => $draft->title,
            'draftName' => Shapes::draftBehavior($draft)?->draftName,
            'sectionHandle' => Shapes::sectionHandle($draft),
            'siteHandle' => Shapes::siteHandle($draft->siteId),
            'dateUpdated' => $draft->dateUpdated,
            'stale' => Shapes::isStale($draft, $staleDays),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fieldDiff(FieldDiff $diff): array
    {
        $shape = Shapes::fieldDiff($diff);
        $string = fn(string|bool $value) => is_bool($value) ? ($value ? 'On' : 'Off') : $value;

        return [
            'handle' => $shape['handle'],
            'label' => $shape['label'],
            'type' => $shape['type'],
            'changed' => $shape['changed'],
            'oldValue' => $string($shape['old']),
            'newValue' => $string($shape['new']),
            'error' => $shape['error'],
        ];
    }

    /**
     * Whether the release is on a site the schema can read.
     */
    private static function canReadRelease(Release $release): bool
    {
        return $release->siteId !== null && in_array((int)$release->siteId, GqlAccess::readableSiteIds(), true);
    }

    /**
     * A release with only the entries the schema could read, and a count of those alone — the
     * number of hidden entries is itself something the schema shouldn't learn.
     *
     * @return array<string, mixed>
     */
    private static function release(Release $release): array
    {
        $schema = GqlAccess::schema();
        $entries = [];

        foreach ($release->getEntries() as $entry) {
            $canonical = $entry->canonicalId
                ? (Entry::find()->id($entry->canonicalId)->siteId($release->siteId)->status(null)->one()
                    ?? Entry::find()->id($entry->canonicalId)->site('*')->unique()->status(null)->one())
                : null;

            if ($canonical !== null && $schema !== null && GqlAccess::canReadEntry($canonical, $schema)) {
                $entries[] = [
                    'canonicalId' => $entry->canonicalId,
                    'draftId' => $entry->draftId,
                    'status' => $entry->status,
                ];
            }
        }

        return [
            'id' => (int)$release->id,
            'source' => 'peek',
            'name' => $release->name,
            'status' => $release->status->value,
            'siteHandle' => Shapes::siteHandle($release->siteId),
            'entryCount' => count($entries),
            'scheduledDate' => $release->scheduledDate,
            'publishedDate' => $release->publishedDate,
            'entries' => $entries,
        ];
    }
}
