<?php

namespace justinholtweb\peek\services;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use yii\base\Component;

class DraftService extends Component
{
    /**
     * Get all saved, non-provisional drafts across the site.
     *
     * @return Entry[]
     */
    public function getAllPendingDrafts(?int $siteId = null, ?User $viewer = null): array
    {
        $query = Entry::find()
            ->drafts(true)
            ->provisionalDrafts(false)
            ->draftOf('*')
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC]);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $this->visibleTo($query->all(), $viewer);
    }

    /**
     * A saved (non-provisional) draft by element ID, in any status, on `$siteId` (or the current site).
     */
    public function getDraftById(int $draftId, ?int $siteId = null): ?Entry
    {
        if ($siteId !== null && !Craft::$app->getSites()->getSiteById($siteId)) {
            $siteId = null;
        }

        return Entry::find()
            ->id($draftId)
            ->siteId($siteId)
            ->drafts(true)
            ->provisionalDrafts(false)
            ->status(null)
            ->one();
    }

    /**
     * Whether `$user` may read a draft's diff. A diff is the draft's whole content, so it needs what
     * Craft's editor needs: view on the draft and on its entry, and on multi-site installs the site.
     */
    public function canViewDiff(Entry $draft, Entry $canonical, User $user): bool
    {
        return $this->canViewEntry($draft, $user) && Craft::$app->getElements()->canView($canonical, $user);
    }

    /**
     * Whether `$user` could open an entry or draft in Craft's editor: view on the element and, on
     * multi-site installs, its site. Everything Peek shows of a draft — dashboard rows, titles in a
     * release, the MCP tools — goes through this.
     */
    public function canViewEntry(Entry $entry, User $user): bool
    {
        if (!Craft::$app->getElements()->canView($entry, $user)) {
            return false;
        }

        // canView() doesn't check site access; Craft's editor does, on multi-site installs
        return $this->canAccessSite($entry->siteId, $user);
    }

    /**
     * Whether `$user` may work in a site: always on a single-site install, else `editSite:{uid}`.
     */
    public function canAccessSite(?int $siteId, User $user): bool
    {
        if (!Craft::$app->getIsMultiSite()) {
            return true;
        }

        $site = $siteId ? Craft::$app->getSites()->getSiteById($siteId, true) : null;

        return $site !== null && $user->can('editSite:' . $site->uid);
    }

    /**
     * The draft and its entry, if `$user` may read the diff; null for "not found" and "not allowed"
     * alike.
     *
     * @return array{0: Entry, 1: Entry}|null
     */
    public function getViewableDraft(int $draftId, ?int $siteId, User $user): ?array
    {
        $draft = $this->getDraftById($draftId, $siteId);
        /** @var Entry|null $canonical */
        $canonical = $draft?->getCanonical();

        if (!$draft || !$canonical || $canonical->id === $draft->id || !$this->canViewDiff($draft, $canonical, $user)) {
            return null;
        }

        return [$draft, $canonical];
    }

    /**
     * Get draft counts grouped by section.
     *
     * @return array<string, int>
     */
    public function getDraftCountsBySection(?int $siteId = null, ?User $viewer = null): array
    {
        $drafts = $this->getAllPendingDrafts($siteId, $viewer);
        $counts = [];

        foreach ($drafts as $draft) {
            $section = $draft->getSection();
            if ($section) {
                $name = $section->name;
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        arsort($counts);
        return $counts;
    }

    /**
     * Get drafts that haven't been updated in the given number of days.
     *
     * @return Entry[]
     */
    public function getStaleDrafts(int $days, ?int $siteId = null, ?User $viewer = null): array
    {
        $cutoff = (new \DateTime())->modify("-{$days} days");

        $query = Entry::find()
            ->drafts(true)
            ->provisionalDrafts(false)
            ->draftOf('*')
            ->status(null)
            ->dateUpdated('< ' . $cutoff->format('Y-m-d H:i:s'))
            ->orderBy(['dateUpdated' => SORT_ASC]);

        if ($siteId !== null) {
            $query->siteId($siteId);
        }

        return $this->visibleTo($query->all(), $viewer);
    }

    /**
     * With a viewer, only the drafts they could open in Craft's editor (canViewEntry()). The dashboard lists other
     * people's drafts by title and section, which is itself content.
     *
     * @param Entry[] $drafts
     * @return Entry[]
     */
    private function visibleTo(array $drafts, ?User $viewer): array
    {
        return array_values(array_filter($drafts, function(Entry $draft) use ($viewer) {
            // A draft whose section has gone (soft-deleted, or removed by a project config apply)
            // throws from getSection(), and one such draft took the whole dashboard down.
            try {
                $draft->getSection();
            } catch (\yii\base\InvalidConfigException) {
                return false;
            }

            return $viewer === null || $this->canViewEntry($draft, $viewer);
        }));
    }
}
