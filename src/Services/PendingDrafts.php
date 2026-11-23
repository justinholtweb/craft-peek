<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Services;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\User\Contracts\CraftUser;
use DateTimeImmutable;
use Throwable;

/**
 * Saved drafts across the site: what the dashboard lists and releases are filled from.
 *
 * Provisional drafts (someone's unsaved edits) are never included.
 */
class PendingDrafts
{
    /**
     * Saved, non-provisional drafts, most recently saved first. With a viewer, only the drafts
     * they could open in Craft's editor: the dashboard lists drafts by title, which is content.
     *
     * @return list<Entry>
     */
    public function all(?int $siteId = null, ?CraftUser $viewer = null): array
    {
        $query = Entry::find()
            ->drafts()
            ->provisionalDrafts(false)
            ->draftOf('*')
            ->status(null)
            ->orderBy('dateUpdated', 'desc');

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $this->visibleTo($query->all(), $viewer);
    }

    /**
     * Drafts with no save in `$days` days, oldest first.
     *
     * Opening an outdated draft in Craft's editor merges the entry's newer changes into it and
     * saves it, so a draft someone merely looked at stops being stale.
     *
     * @return list<Entry>
     */
    public function stale(int $days, ?int $siteId = null, ?CraftUser $viewer = null): array
    {
        $cutoff = new DateTimeImmutable("-{$days} days");

        $query = Entry::find()
            ->drafts()
            ->provisionalDrafts(false)
            ->draftOf('*')
            ->status(null)
            ->dateUpdated('< '.Query::prepareDateForDb($cutoff))
            ->orderBy('dateUpdated', 'asc');

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $this->visibleTo($query->all(), $viewer);
    }

    /**
     * Draft counts by section name, largest first.
     *
     * @param  list<Entry>  $drafts
     * @return array<string, int>
     */
    public function countsBySection(array $drafts): array
    {
        $counts = [];

        foreach ($drafts as $draft) {
            $name = self::sectionName($draft);
            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * A draft's section name, or null when the section is gone (soft-deleted, or removed by a
     * project config apply). One such draft took the whole 5.x dashboard down.
     */
    public static function sectionName(Entry $entry): ?string
    {
        try {
            return $entry->getSection()?->name;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $drafts
     * @return list<Entry>
     */
    private function visibleTo(array $drafts, ?CraftUser $viewer): array
    {
        return array_values(array_filter($drafts, function (mixed $draft) use ($viewer): bool {
            if (! $draft instanceof Entry || self::sectionName($draft) === null) {
                return false;
            }

            return $viewer === null || $viewer->can('view', $draft);
        }));
    }
}
