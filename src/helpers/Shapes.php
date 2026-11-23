<?php

namespace justinholtweb\peek\helpers;

use Craft;
use craft\behaviors\DraftBehavior;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use justinholtweb\peek\models\FieldDiff;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;

/**
 * The plain-array shapes Peek's MCP tools return (and GraphQL builds on). They're shared with the
 * Craft 6 version, so keys and value types are fixed: change them in both or neither.
 *
 * Nothing here checks permissions. Callers pass only what the viewer may see, except where a
 * method takes the viewer itself.
 */
class Shapes
{
    /**
     * A date as ISO 8601 in UTC, e.g. `2026-11-23T14:05:00+00:00`.
     */
    public static function iso(?\DateTimeInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(DATE_ATOM);
    }

    public static function sectionHandle(Entry $entry): ?string
    {
        try {
            return $entry->getSection()?->handle;
        } catch (\yii\base\InvalidConfigException) {
            return null;
        }
    }

    public static function siteHandle(?int $siteId): ?string
    {
        return $siteId ? Craft::$app->getSites()->getSiteById($siteId, true)?->handle : null;
    }

    public static function draftBehavior(Entry $draft): ?DraftBehavior
    {
        $behavior = $draft->getBehavior('draft');

        return $behavior instanceof DraftBehavior ? $behavior : null;
    }

    /**
     * Whether a draft hasn't been updated in `$days` days (the dashboard's "stale").
     */
    public static function isStale(Entry $draft, int $days): bool
    {
        return $draft->dateUpdated !== null && $draft->dateUpdated < (new \DateTime())->modify("-{$days} days");
    }

    /**
     * @return array{id: int, draftId: int|null, canonicalId: int, title: string|null, draftName: string|null, section: string|null, site: string|null, creator: string|null, dateUpdated: string|null, stale: bool, cpEditUrl: string|null, diffUrl: string}
     */
    public static function draft(Entry $draft, int $staleDays): array
    {
        $behavior = self::draftBehavior($draft);

        return [
            'id' => (int)$draft->id,
            'draftId' => $draft->draftId,
            'canonicalId' => $draft->getCanonicalId(),
            'title' => $draft->title,
            'draftName' => $behavior?->draftName,
            'section' => self::sectionHandle($draft),
            'site' => self::siteHandle($draft->siteId),
            'creator' => $behavior?->getCreator()?->getFriendlyName(),
            'dateUpdated' => self::iso($draft->dateUpdated),
            'stale' => self::isStale($draft, $staleDays),
            'cpEditUrl' => $draft->getCpEditUrl(),
            'diffUrl' => UrlHelper::cpUrl("peek/diff/{$draft->id}", ['siteId' => $draft->siteId]),
        ];
    }

    /**
     * A field's comparison, as Peek's diff serializes the values: text with no HTML, except the
     * `enabled` attribute, which is a boolean.
     *
     * @return array{handle: string|null, label: string|null, type: string|null, changed: bool, old: string|bool, new: string|bool, error: string|null}
     */
    public static function fieldDiff(FieldDiff $diff): array
    {
        return [
            'handle' => $diff->handle,
            'label' => $diff->label,
            'type' => $diff->type,
            'changed' => $diff->hasChanges,
            'old' => self::diffValue($diff->oldValue),
            'new' => self::diffValue($diff->newValue),
            'error' => $diff->error,
        ];
    }

    private static function diffValue(mixed $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * A release's summary: no entries or problems.
     *
     * @return array{source: string, id: int, name: string|null, status: string, site: string|null, entryCount: int, scheduledDate: string|null, publishedDate: string|null, cpUrl: string}
     */
    public static function release(Release $release): array
    {
        return [
            'source' => 'peek',
            'id' => (int)$release->id,
            'name' => $release->name,
            'status' => $release->status->value,
            'site' => self::siteHandle($release->siteId),
            'entryCount' => $release->getEntryCount(),
            'scheduledDate' => self::iso($release->scheduledDate),
            'publishedDate' => self::iso($release->publishedDate),
            'cpUrl' => UrlHelper::cpUrl("peek/releases/{$release->id}"),
        ];
    }

    /**
     * A release's entries as `$viewer` may see them: a draft's title only when they could open it
     * (or, once it's applied, its entry) — view rights and, on multi-site, the site.
     *
     * @return list<array{canonicalId: int|null, draftId: int|null, title: string|null, status: string}>
     */
    public static function releaseEntries(Release $release, User $viewer): array
    {
        $drafts = Plugin::getInstance()->drafts;
        $rows = [];

        foreach ($release->getEntries() as $entry) {
            $draft = $entry->draftId
                ? Entry::find()->id($entry->draftId)->drafts(true)->provisionalDrafts(null)->status(null)->site('*')->one()
                : null;
            $canonical = $draft === null && $entry->canonicalId
                ? Entry::find()->id($entry->canonicalId)->status(null)->site('*')->one()
                : null;
            $element = $draft ?? $canonical;

            $rows[] = [
                'canonicalId' => $entry->canonicalId,
                'draftId' => $entry->draftId,
                'title' => $element !== null && $drafts->canViewEntry($element, $viewer) ? $element->title : null,
                'status' => $entry->status,
            ];
        }

        return $rows;
    }
}
