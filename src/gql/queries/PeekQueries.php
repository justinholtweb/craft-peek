<?php

namespace justinholtweb\peek\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use justinholtweb\peek\gql\GqlAccess;
use justinholtweb\peek\gql\resolvers\PeekResolver;
use justinholtweb\peek\gql\types\PeekDraftDiffType;
use justinholtweb\peek\gql\types\PeekDraftType;
use justinholtweb\peek\gql\types\PeekReleaseType;

/**
 * Peek's read-only GraphQL queries. Each pair is in the schema only when it has the matching Peek
 * scope (and, for drafts, Craft's "Query for element drafts"); never on the public schema.
 */
class PeekQueries extends Query
{
    /**
     * @return array<string, mixed>
     */
    public static function getQueries(bool $checkToken = true): array
    {
        $queries = [];

        if (!$checkToken || GqlAccess::canQueryDrafts()) {
            $queries['peekDrafts'] = [
                'type' => Type::listOf(PeekDraftType::getType()),
                'args' => [
                    'siteId' => ['type' => Type::int(), 'description' => 'Only drafts in this site. Defaults to the current site.'],
                    'staleOnly' => ['type' => Type::boolean(), 'defaultValue' => false, 'description' => 'Only drafts not updated in Peek’s stale-draft period.'],
                    'limit' => ['type' => Type::int(), 'defaultValue' => 50, 'description' => '1–100.'],
                    'offset' => ['type' => Type::int(), 'defaultValue' => 0],
                ],
                'resolve' => [PeekResolver::class, 'drafts'],
                'description' => 'Saved drafts waiting to be applied, newest first.',
            ];
            $queries['peekDraftDiff'] = [
                'type' => PeekDraftDiffType::getType(),
                'args' => [
                    'draftId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The draft’s element ID.'],
                    'siteId' => ['type' => Type::int()],
                    'includeUnchanged' => ['type' => Type::boolean(), 'defaultValue' => false],
                ],
                'resolve' => [PeekResolver::class, 'draftDiff'],
                'description' => 'A draft compared with its live entry, field by field.',
            ];
        }

        if (!$checkToken || GqlAccess::canQueryReleases()) {
            $queries['peekReleases'] = [
                'type' => Type::listOf(PeekReleaseType::getType()),
                'args' => [
                    'status' => ['type' => Type::string(), 'description' => 'draft, ready, scheduled, publishing, published or failed.'],
                ],
                'resolve' => [PeekResolver::class, 'releases'],
                'description' => 'Peek releases, newest first.',
            ];
            $queries['peekRelease'] = [
                'type' => PeekReleaseType::getType(),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::int())],
                    'source' => ['type' => Type::string(), 'description' => 'Always `peek` on Craft 5.'],
                ],
                'resolve' => [PeekResolver::class, 'releaseById'],
                'description' => 'One Peek release.',
            ];
        }

        return $queries;
    }
}
