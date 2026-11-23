<?php

namespace justinholtweb\peek\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PeekFieldDiff` — One field of a draft compared with its live entry.
 */
class PeekFieldDiffType
{
    public static function getName(): string
    {
        return 'PeekFieldDiff';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'One field of a draft compared with its live entry.',
            'fields' => fn() => [
                'handle' => ['type' => Type::string()],
                'label' => ['type' => Type::string()],
                'type' => ['type' => Type::string(), 'description' => '`native` for title, slug and enabled; otherwise the field’s class.'],
                'changed' => ['type' => Type::boolean()],
                'oldValue' => ['type' => Type::string(), 'description' => 'The live value as Peek compares it: plain text, booleans as On/Off.'],
                'newValue' => ['type' => Type::string(), 'description' => 'The draft’s value, likewise.'],
                'error' => ['type' => Type::string(), 'description' => 'Set when the field couldn’t be compared.'],
            ],
        ]));
    }
}
