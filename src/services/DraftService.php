<?php

namespace justinholtweb\peek\services;

use Craft;
use craft\elements\Entry;
use yii\base\Component;

class DraftService extends Component
{
    /**
     * Get all saved, non-provisional drafts across the site.
     *
     * @return Entry[]
     */
    public function getAllPendingDrafts(?int $siteId = null): array
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

        return $query->all();
    }

    /**
     * Get draft counts grouped by section.
     *
     * @return array<string, int>
     */
    public function getDraftCountsBySection(?int $siteId = null): array
    {
        $drafts = $this->getAllPendingDrafts($siteId);
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
    public function getStaleDrafts(int $days, ?int $siteId = null): array
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

        return $query->all();
    }
}
