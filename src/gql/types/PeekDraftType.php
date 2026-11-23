<?php

namespace justinholtweb\peek\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * `PeekDraft` — A saved draft of an entry, waiting to be applied.
 */
class PeekDraftType
{
    public static function getName(): string
    {
        return 'PeekDraft';
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::getName(), new ObjectType([
            'name' => self::getName(),
            'description' => 'A saved draft of an entry, waiting to be applied.',
            'fields' => fn() => [
                'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The draft’s element ID.'],
                'draftId' => ['type' => Type::int(), 'description' => 'The draft’s ID in Craft’s drafts table.'],
                'canonicalId' => ['type' => Type::int(), 'description' => 'The entry the draft belongs to.'],
                'title' => ['type' => Type::string()],
                'draftName' => ['type' => Type::string()],
                'sectionHandle' => ['type' => Type::string()],
                'siteHandle' => ['type' => Type::string()],
                'dateUpdated' => ['type' => DateTime::getType()],
                'stale' => ['type' => Type::boolean(), 'description' => 'Not updated in Peek’s stale-draft period.'],
            ],
        ]));
    }
}
