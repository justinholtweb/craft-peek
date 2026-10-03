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
     * With a viewer, only the drafts they could open in Craft's editor. The dashboard lists other
     * people's drafts by title and section, which is itself content.
     *
     * @param Entry[] $drafts
     * @return Entry[]
     */
    private function visibleTo(array $drafts, ?User $viewer): array
    {
        $elements = Craft::$app->getElements();

        return array_values(array_filter($drafts, function(Entry $draft) use ($elements, $viewer) {
            // A draft whose section has gone (soft-deleted, or removed by a project config apply)
            // throws from getSection(), and one such draft took the whole dashboard down.
            try {
                $draft->getSection();
            } catch (\yii\base\InvalidConfigException) {
                return false;
            }

            return $viewer === null || $elements->canView($draft, $viewer);
        }));
    }
}
