<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Gql\Types;

use CraftCms\Cms\Gql\GqlEntityRegistry;
use CraftCms\Cms\Gql\Types\DateTime;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use JustinHoltWeb\Peek\Support\Presenter;

/**
 * Peek's GraphQL object types. Resolved from the arrays {@see Presenter} builds.
 */
final class PeekTypes
{
    public static function draft(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('PeekDraft', fn () => new ObjectType([
            'name' => 'PeekDraft',
            'description' => 'A saved draft of an entry.',
            'fields' => fn () => [
                'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The draft’s element ID.'],
                'draftId' => ['type' => Type::int()],
                'canonicalId' => ['type' => Type::nonNull(Type::int()), 'description' => 'The entry the draft belongs to.'],
                'title' => ['type' => Type::string()],
                'draftName' => ['type' => Type::string()],
                'sectionHandle' => ['type' => Type::string()],
                'siteHandle' => ['type' => Type::string(), 'resolve' => fn (array $d) => $d['site']],
                'dateUpdated' => ['type' => DateTime::getType(), 'resolve' => fn (array $d) => $d['dateUpdated'] !== null ? new \DateTimeImmutable($d['dateUpdated']) : null],
                'stale' => ['type' => Type::nonNull(Type::boolean()), 'description' => 'No save within Peek’s stale-draft threshold.'],
            ],
        ]));
    }

    public static function fieldDiff(): ObjectType
    {
        $text = fn (string $key) => fn (array $d) => is_bool($d[$key]) ? ($d[$key] ? 'On' : 'Off') : (string) $d[$key];

        return GqlEntityRegistry::getOrCreate('PeekFieldDiff', fn () => new ObjectType([
            'name' => 'PeekFieldDiff',
            'description' => 'One field compared between a draft and its entry, as text.',
            'fields' => fn () => [
                'handle' => ['type' => Type::nonNull(Type::string())],
                'label' => ['type' => Type::nonNull(Type::string())],
                'type' => ['type' => Type::nonNull(Type::string()), 'description' => '`native` or the field type’s class.'],
                'changed' => ['type' => Type::nonNull(Type::boolean())],
                'oldValue' => ['type' => Type::string(), 'resolve' => $text('old')],
                'newValue' => ['type' => Type::string(), 'resolve' => $text('new')],
                'error' => ['type' => Type::string(), 'description' => 'Set when the field couldn’t be compared.'],
            ],
        ]));
    }

    public static function draftDiff(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('PeekDraftDiff', fn () => new ObjectType([
            'name' => 'PeekDraftDiff',
            'fields' => fn () => [
                'draft' => ['type' => Type::nonNull(self::draft())],
                'changedCount' => ['type' => Type::nonNull(Type::int())],
                'fields' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(self::fieldDiff())))],
            ],
        ]));
    }

    public static function releaseEntry(): ObjectType
    {
        return GqlEntityRegistry::getOrCreate('PeekReleaseEntry', fn () => new ObjectType([
            'name' => 'PeekReleaseEntry',
            'fields' => fn () => [
                'canonicalId' => ['type' => Type::nonNull(Type::int())],
                'draftId' => ['type' => Type::int(), 'description' => 'The draft’s element ID; null once it’s applied.'],
                'status' => ['type' => Type::nonNull(Type::string()), 'description' => 'pending, published or failed.'],
            ],
        ]));
    }

    public static function release(): ObjectType
    {
        $date = fn (string $key) => fn (array $r) => $r[$key] !== null ? new \DateTimeImmutable($r[$key]) : null;

        return GqlEntityRegistry::getOrCreate('PeekRelease', fn () => new ObjectType([
            'name' => 'PeekRelease',
            'description' => 'A release: Peek’s own, or Craft’s on Pro and Enterprise.',
            'fields' => fn () => [
                'id' => ['type' => Type::nonNull(Type::int())],
                'source' => ['type' => Type::nonNull(Type::string()), 'description' => '`peek` or `craft`.'],
                'name' => ['type' => Type::nonNull(Type::string())],
                'status' => ['type' => Type::nonNull(Type::string())],
                'siteHandle' => ['type' => Type::string(), 'resolve' => fn (array $r) => $r['site']],
                'entryCount' => ['type' => Type::nonNull(Type::int())],
                'scheduledDate' => ['type' => DateTime::getType(), 'resolve' => $date('scheduledDate')],
                'publishedDate' => ['type' => DateTime::getType(), 'resolve' => $date('publishedDate')],
                'entries' => ['type' => Type::listOf(Type::nonNull(self::releaseEntry())), 'resolve' => fn (array $r) => $r['entries'] ?? []],
            ],
        ]));
    }
}
