<?php

namespace justinholtweb\peek\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PeekReleaseEntry` — A draft in a release.
 */
class PeekReleaseEntryType
{
    public static function getName(): string
    {
        return 'PeekReleaseEntry';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A draft in a release.',
            'fields' => fn() => [
                'canonicalId' => ['type' => Type::int()],
                'draftId' => ['type' => Type::int(), 'description' => 'Null once the draft has been applied.'],
                'status' => ['type' => Type::string(), 'description' => 'pending, published or failed.'],
            ],
        ]));
    }
}
