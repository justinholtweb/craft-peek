<?php

namespace justinholtweb\peek\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PeekDraftDiff` — A draft compared with its live entry, field by field.
 */
class PeekDraftDiffType
{
    public static function getName(): string
    {
        return 'PeekDraftDiff';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A draft compared with its live entry, field by field.',
            'fields' => fn() => [
                'draft' => ['type' => PeekDraftType::getType()],
                'changedCount' => ['type' => Type::int()],
                'fields' => ['type' => Type::listOf(PeekFieldDiffType::getType())],
            ],
        ]));
    }
}
