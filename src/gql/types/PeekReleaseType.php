<?php

namespace justinholtweb\peek\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PeekRelease` — A group of drafts published together.
 */
class PeekReleaseType
{
    public static function getName(): string
    {
        return 'PeekRelease';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A group of drafts published together.',
            'fields' => fn() => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'source' => ['type' => Type::string(), 'description' => 'Always `peek` on Craft 5.'],
                'name' => ['type' => Type::string()],
                'status' => ['type' => Type::string(), 'description' => 'draft, ready, scheduled, publishing, published or failed.'],
                'siteHandle' => ['type' => Type::string()],
                'entryCount' => ['type' => Type::int(), 'description' => 'Entries in sections and sites this schema can read.'],
                'scheduledDate' => ['type' => DateTime::getType()],
                'publishedDate' => ['type' => DateTime::getType()],
                'entries' => ['type' => Type::listOf(PeekReleaseEntryType::getType()), 'description' => 'Only entries in sections and sites this schema can read.'],
            ],
        ]));
    }
}
