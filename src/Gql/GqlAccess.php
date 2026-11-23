<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Gql;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use CraftCms\Cms\Gql\GqlHelper;
use Throwable;

/**
 * What the active GraphQL schema may read through Peek.
 *
 * Drafts are unpublished content, so: never on the public schema; drafts and diffs only with the
 * schema's core "Query for element drafts" option as well as Peek's scope; and only entries in
 * sections and sites the schema can read.
 */
final class GqlAccess
{
    public const string SCOPE_DRAFTS = 'peekDrafts';

    public const string SCOPE_RELEASES = 'peekReleases';

    public static function schema(): ?GqlSchema
    {
        try {
            return app(Gql::class)->getActiveSchema();
        } catch (Throwable) {
            return null;
        }
    }

    public static function canQueryDrafts(): bool
    {
        $schema = self::schema();

        return $schema !== null && ! $schema->isPublic
            && GqlHelper::canSchema(self::SCOPE_DRAFTS, 'read', $schema)
            && GqlHelper::canQueryDrafts($schema);
    }

    public static function canQueryReleases(): bool
    {
        $schema = self::schema();

        return $schema !== null && ! $schema->isPublic && GqlHelper::canSchema(self::SCOPE_RELEASES, 'read', $schema);
    }

    /** Craft's own releases (Pro, Enterprise) also need core's release option. */
    public static function canQueryCraftReleases(): bool
    {
        return self::canQueryReleases() && GqlHelper::canQueryReleases(self::schema());
    }

    /** Whether the schema can read the entry a draft belongs to, in the draft's site. */
    public static function canReadEntry(Entry $entry): bool
    {
        $schema = self::schema();

        if ($schema === null) {
            return false;
        }

        try {
            $section = $entry->getSection();
        } catch (Throwable) {
            return false;
        }

        $allowed = GqlHelper::extractAllowedEntitiesFromSchema('read', $schema);

        return $section !== null
            && in_array($section->uid, $allowed['sections'] ?? [], true)
            && in_array($entry->getSite()->uid, $allowed['sites'] ?? [], true);
    }

    /** @return list<int> */
    public static function readableSiteIds(): array
    {
        return array_map(fn ($site) => (int) $site->id, GqlHelper::getAllowedSites(self::schema()));
    }
}
