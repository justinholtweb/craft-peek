<?php

namespace justinholtweb\peek\gql;

use Craft;
use craft\elements\Entry;
use craft\errors\GqlException;
use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;
use justinholtweb\peek\Plugin;

/**
 * What the active GraphQL schema may read through Peek. Drafts are unpublished content, so:
 *
 * - nothing on the public schema, whatever its scopes;
 * - drafts and diffs need Peek's `peekDrafts:read` and Craft's own "Query for element drafts";
 * - releases need `peekReleases:read`, and only those on a site the schema can read are shown;
 * - a draft, a diff or a release entry only for an entry in a section (`sections.{uid}:read`) and
 *   on a site (`sites.{uid}:read`) the schema can read; a release's `entryCount` counts only those.
 */
class GqlAccess
{
    public static function schema(): ?GqlSchema
    {
        try {
            $schema = Craft::$app->getGql()->getActiveSchema();
        } catch (GqlException) {
            return null;
        }

        return $schema->isPublic ? null : $schema;
    }

    public static function canQueryDrafts(?GqlSchema $schema = null): bool
    {
        $schema ??= self::schema();

        return $schema !== null
            && !$schema->isPublic
            && GqlHelper::canSchema(Plugin::GQL_SCOPE_DRAFTS, 'read', $schema)
            && GqlHelper::canQueryDrafts($schema);
    }

    public static function canQueryReleases(?GqlSchema $schema = null): bool
    {
        $schema ??= self::schema();

        return $schema !== null
            && !$schema->isPublic
            && GqlHelper::canSchema(Plugin::GQL_SCOPE_RELEASES, 'read', $schema);
    }

    /**
     * @return int[]
     */
    public static function readableSiteIds(?GqlSchema $schema = null): array
    {
        return array_values(array_map(fn($site) => (int)$site->id, GqlHelper::getAllowedSites($schema)));
    }

    /**
     * Whether the schema can read an entry (or a draft of one): its section and its site.
     */
    public static function canReadEntry(Entry $entry, ?GqlSchema $schema = null): bool
    {
        $schema ??= self::schema();

        if ($schema === null || !in_array((int)$entry->siteId, self::readableSiteIds($schema), true)) {
            return false;
        }

        try {
            $section = $entry->getSection();
        } catch (\yii\base\InvalidConfigException) {
            return false;
        }

        $sections = GqlHelper::extractAllowedEntitiesFromSchema('read', $schema)['sections'] ?? [];

        return $section !== null && in_array($section->uid, $sections, true);
    }
}
