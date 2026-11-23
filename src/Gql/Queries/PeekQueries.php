<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Gql\Queries;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\GqlHelper;
use CraftCms\Cms\Gql\Queries\Query;
use CraftCms\Cms\Support\Facades\Sites;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Peek\Data\FieldDiff;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Gql\GqlAccess;
use JustinHoltWeb\Peek\Gql\Types\PeekTypes;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use JustinHoltWeb\Peek\Support\Presenter;
use Override;

/**
 * `peekDrafts`, `peekDraftDiff`, `peekReleases`, `peekRelease`. Read-only.
 *
 * Each needs its scope under "Peek" in the schema; the draft queries also need the schema's
 * "Query for element drafts". None resolve on the public schema. See {@see GqlAccess}.
 */
final class PeekQueries extends Query
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public static function getQueries(bool $checkToken = true): array
    {
        $queries = [];

        if (! $checkToken || GqlHelper::canSchema(GqlAccess::SCOPE_DRAFTS, 'read')) {
            $queries['peekDrafts'] = [
                'type' => Type::listOf(Type::nonNull(PeekTypes::draft())),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Only drafts in this site.'],
                    'staleOnly' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Only drafts past Peek’s stale threshold.'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => 'At most 100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => self::drafts(...),
                'description' => 'Saved drafts of entries the schema can read, most recently saved first.',
            ];

            $queries['peekDraftDiff'] = [
                'type' => PeekTypes::draftDiff(),
                'args' => [
                    'draftId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The draft’s element ID.'],
                    'siteId' => ['type' => Type::int()],
                    'includeUnchanged' => ['type' => Type::boolean(), 'defaultValue' => false],
                ],
                'resolve' => self::draftDiff(...),
                'description' => 'A draft compared with its entry, field by field.',
            ];
        }

        if (! $checkToken || GqlHelper::canSchema(GqlAccess::SCOPE_RELEASES, 'read')) {
            $queries['peekReleases'] = [
                'type' => Type::listOf(Type::nonNull(PeekTypes::release())),
                'args' => [
                    'status' => ['type' => Type::string()],
                ],
                'resolve' => self::releases(...),
                'description' => 'Releases, newest first.',
            ];

            $queries['peekRelease'] = [
                'type' => PeekTypes::release(),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::int())],
                    'source' => ['type' => Type::string(), 'description' => '`peek` or `craft`; defaults to the site’s release system.'],
                ],
                'resolve' => self::release(...),
            ];
        }

        return $queries;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function drafts(mixed $source, array $arguments): array
    {
        if (! GqlAccess::canQueryDrafts()) {
            return [];
        }

        $siteId = isset($arguments['siteId']) ? (int) $arguments['siteId'] : null;
        $readableSites = GqlAccess::readableSiteIds();

        if ($siteId !== null && ! in_array($siteId, $readableSites, true)) {
            return [];
        }

        $settings = app(Settings::class);
        $service = app(PendingDrafts::class);
        $drafts = ($arguments['staleOnly'] ?? false)
            ? $service->stale($settings->staleDraftDays, $siteId)
            : $service->all($siteId);

        $drafts = array_values(array_filter($drafts, fn (Entry $draft) => GqlAccess::canReadEntry($draft)));
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 50)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        return array_map(
            fn (Entry $draft) => Presenter::draft($draft, $settings->staleDraftDays),
            array_slice($drafts, $offset, $limit),
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function draftDiff(mixed $source, array $arguments): ?array
    {
        if (! GqlAccess::canQueryDrafts()) {
            return null;
        }

        $siteId = isset($arguments['siteId']) ? (int) $arguments['siteId'] : null;

        $sites = $siteId !== null ? [$siteId] : GqlAccess::readableSiteIds();

        if ($sites === []) {
            return null;
        }

        $draft = Entry::find()
            ->id((int) $arguments['draftId'])
            ->siteId($sites)
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->one();

        // No existence oracle: a draft the schema can't read simply isn't there.
        if (! $draft instanceof Entry || ! GqlAccess::canReadEntry($draft)) {
            return null;
        }

        $canonical = $draft->getCanonical();
        if (! $canonical instanceof Entry || $canonical->id === $draft->id) {
            return null;
        }

        $diffs = app(Diff::class)->diffEntry($draft, $canonical);
        $changed = array_values(array_filter($diffs, fn (FieldDiff $d) => $d->hasChanges));

        return [
            'draft' => Presenter::draft($draft, app(Settings::class)->staleDraftDays),
            'changedCount' => count($changed),
            'fields' => array_map(
                Presenter::fieldDiff(...),
                ($arguments['includeUnchanged'] ?? false) ? $diffs : $changed,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    public static function releases(mixed $source, array $arguments): array
    {
        if (! GqlAccess::canQueryReleases()) {
            return [];
        }

        $system = app(ReleaseSystem::class);
        $status = isset($arguments['status']) ? (string) $arguments['status'] : null;

        $releases = array_filter(
            $system->all(status: $status),
            fn (ReleaseSummary $r) => $r->source === ReleaseSummary::SOURCE_PEEK ? self::siteReadable($r) : GqlAccess::canQueryCraftReleases(),
        );

        return array_values(array_map(function (ReleaseSummary $r) use ($system): array {
            $full = $system->find($r->source, $r->id, withEntries: true) ?? $r;

            return self::presentRelease($full);
        }, $releases));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    public static function release(mixed $source, array $arguments): ?array
    {
        if (! GqlAccess::canQueryReleases()) {
            return null;
        }

        $system = app(ReleaseSystem::class);
        $from = (string) ($arguments['source'] ?? $system->primarySource());

        if ($from === ReleaseSummary::SOURCE_CRAFT && ! GqlAccess::canQueryCraftReleases()) {
            return null;
        }

        $release = $system->find($from, (int) $arguments['id'], withEntries: true);

        if ($release === null || ($release->source === ReleaseSummary::SOURCE_PEEK && ! self::siteReadable($release))) {
            return null;
        }

        return self::presentRelease($release);
    }

    /** A Peek release belongs to a site: the schema must be able to read it. */
    private static function siteReadable(ReleaseSummary $release): bool
    {
        if ($release->site === null) {
            return true;
        }

        $site = Sites::getSiteByHandle($release->site, true);

        return $site !== null && in_array((int) $site->id, GqlAccess::readableSiteIds(), true);
    }

    /**
     * Only entries in sections and sites the schema can read are listed, and counted.
     *
     * @return array<string, mixed>
     */
    private static function presentRelease(ReleaseSummary $release): array
    {
        $sites = GqlAccess::readableSiteIds();

        $entries = $sites === [] ? [] : array_values(array_filter($release->entries, function (array $entry) use ($sites): bool {
            $canonical = Entry::find()->id($entry['canonicalId'])->status(null)->siteId($sites)->one();

            return $canonical instanceof Entry && GqlAccess::canReadEntry($canonical);
        }));

        $data = Presenter::release($release, $entries);
        $data['entryCount'] = count($entries);

        return $data;
    }
}
