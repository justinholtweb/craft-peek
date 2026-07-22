<?php

namespace justinholtweb\peek\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\models\ReleaseEntry;
use justinholtweb\peek\Plugin;
use justinholtweb\peek\records\ReleaseEntryRecord;
use justinholtweb\peek\records\ReleaseRecord;
use yii\base\Component;
use yii\base\InvalidArgumentException;

class Releases extends Component
{
    public function getReleaseById(int $id): ?Release
    {
        $record = ReleaseRecord::findOne($id);

        if (!$record) {
            return null;
        }

        return $this->_populateRelease($record);
    }

    /**
     * @return Release[]
     */
    public function getAllReleases(?int $siteId = null): array
    {
        $query = ReleaseRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($siteId !== null) {
            $query->where(['siteId' => $siteId]);
        }

        $releases = [];
        /** @var ReleaseRecord $record */
        foreach ($query->all() as $record) {
            $releases[] = $this->_populateRelease($record);
        }

        return $releases;
    }

    public function saveRelease(Release $release): bool
    {
        if (!$release->validate()) {
            return false;
        }

        if ($release->id) {
            $record = ReleaseRecord::findOne($release->id);
            if (!$record) {
                throw new InvalidArgumentException("Release not found: {$release->id}");
            }
        } else {
            $record = new ReleaseRecord();
        }

        $record->siteId = $release->siteId;
        $record->name = $release->name;
        $record->description = $release->description;
        $record->status = $release->status->value;
        $record->scheduledDate = $release->scheduledDate?->format('Y-m-d H:i:s');
        $record->publishedDate = $release->publishedDate?->format('Y-m-d H:i:s');
        $record->publishedBy = $release->publishedBy;
        $record->createdBy = $release->createdBy;

        if (!$record->save()) {
            $release->addErrors($record->getErrors());
            return false;
        }

        $release->id = $record->id;
        $release->uid = $record->uid;
        $release->dateCreated = $record->dateCreated ? new \DateTime($record->dateCreated) : null;
        $release->dateUpdated = $record->dateUpdated ? new \DateTime($record->dateUpdated) : null;

        return true;
    }

    public function deleteRelease(int $id): bool
    {
        $record = ReleaseRecord::findOne($id);

        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    public function addEntryToRelease(int $releaseId, int $canonicalId, int $draftId): bool
    {
        $entry = new ReleaseEntry();

        // Check for duplicate
        $exists = ReleaseEntryRecord::findOne([
            'releaseId' => $releaseId,
            'draftId' => $draftId,
        ]);
        if ($exists) {
            return false;
        }

        // Check max entries limit
        $maxEntries = Plugin::getInstance()->getSettings()->maxEntriesPerRelease;
        $currentCount = (int)(new Query())
            ->from(ReleaseEntryRecord::tableName())
            ->where(['releaseId' => $releaseId])
            ->count();
        if ($currentCount >= $maxEntries) {
            return false;
        }

        $entry->releaseId = $releaseId;
        $entry->canonicalId = $canonicalId;
        $entry->draftId = $draftId;
        $entry->sortOrder = (int)(new Query())
            ->from(ReleaseEntryRecord::tableName())
            ->where(['releaseId' => $releaseId])
            ->max('sortOrder') + 1;

        if (!$entry->validate()) {
            return false;
        }

        $record = new ReleaseEntryRecord();
        $record->releaseId = $entry->releaseId;
        $record->canonicalId = $entry->canonicalId;
        $record->draftId = $entry->draftId;
        $record->sortOrder = $entry->sortOrder;
        $record->status = $entry->status;

        return $record->save();
    }

    public function removeEntryFromRelease(int $releaseId, int $draftId): bool
    {
        $record = ReleaseEntryRecord::findOne([
            'releaseId' => $releaseId,
            'draftId' => $draftId,
        ]);

        if (!$record) {
            return false;
        }

        return (bool)$record->delete();
    }

    /**
     * Validate that all drafts in a release are still valid and can be applied.
     *
     * @return string[] Array of error messages, empty if valid
     */
    public function validateRelease(Release $release): array
    {
        $errors = [];
        $entries = $release->id ? $this->_getEntriesForRelease($release->id) : [];

        if (empty($entries)) {
            $errors[] = Craft::t('peek', 'Release has no entries.');
            return $errors;
        }

        foreach ($entries as $entry) {
            // A null draftId means the draft is gone — the FK is SET NULL, so
            // this is what an already-published (or externally deleted) entry
            // looks like. Never pass it to an element query: `id(null)` drops
            // the filter and would match an unrelated draft.
            if (!$entry->draftId) {
                $errors[] = Craft::t('peek', 'Entry #{id} no longer has a draft to publish.', ['id' => $entry->canonicalId]);
                continue;
            }

            $draft = Entry::find()->id($entry->draftId)->drafts(true)->status(null)->one();
            if (!$draft) {
                $errors[] = Craft::t('peek', 'Draft #{id} no longer exists.', ['id' => $entry->draftId]);
                continue;
            }

            $canonical = Entry::find()->id($entry->canonicalId)->status(null)->one();
            if (!$canonical) {
                $errors[] = Craft::t('peek', 'Canonical entry #{id} no longer exists.', ['id' => $entry->canonicalId]);
            }
        }

        return $errors;
    }

    /**
     * Publish all entries in a release atomically.
     * All drafts are applied within a DB transaction — all succeed or none do.
     */
    public function publishRelease(Release $release): bool
    {
        // Publishing is terminal. Re-running it would have nothing left to apply,
        // since applying a draft removes it.
        if (!$release->status->isPublishable()) {
            return false;
        }

        $errors = $this->validateRelease($release);
        if (!empty($errors)) {
            return false;
        }

        $entries = $this->_getEntriesForRelease($release->id);
        $transaction = Craft::$app->getDb()->beginTransaction();

        // Update release status to publishing
        $release->status = ReleaseStatus::Publishing;
        $this->saveRelease($release);

        try {
            foreach ($entries as $entry) {
                if (!$entry->draftId) {
                    throw new \RuntimeException("Release entry #{$entry->id} has no draft to apply");
                }

                $draft = Entry::find()
                    ->id($entry->draftId)
                    ->drafts(true)
                    ->status(null)
                    ->one();

                if (!$draft) {
                    throw new \RuntimeException("Draft #{$entry->draftId} not found");
                }

                Craft::$app->getDrafts()->applyDraft($draft);

                // Mark entry as published
                $entryRecord = ReleaseEntryRecord::findOne($entry->id);
                if ($entryRecord) {
                    $entryRecord->status = 'published';
                    $entryRecord->save(false);
                }
            }

            // Mark release as published
            $release->status = ReleaseStatus::Published;
            $release->publishedDate = new \DateTime();
            $release->publishedBy = Craft::$app->getUser()->getId();
            $this->saveRelease($release);

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();

            // Mark release as failed
            $release->status = ReleaseStatus::Failed;
            $this->saveRelease($release);

            Craft::error("Failed to publish release #{$release->id}: {$e->getMessage()}", 'peek');
            return false;
        }
    }

    /**
     * Find all release entries referencing a given draft ID.
     *
     * @return ReleaseEntryRecord[]
     */
    public function getReleaseEntryRecordsByDraftId(int $draftId): array
    {
        /** @var ReleaseEntryRecord[] $records */
        $records = ReleaseEntryRecord::find()
            ->where(['draftId' => $draftId])
            ->all();

        return $records;
    }

    /**
     * Mark a release entry as published by draft ID.
     */
    public function markEntryPublishedByDraftId(int $draftId): void
    {
        $records = $this->getReleaseEntryRecordsByDraftId($draftId);
        foreach ($records as $record) {
            $record->status = 'published';
            $record->save(false);
        }
    }

    /**
     * Remove all release entries referencing a given draft ID.
     */
    public function removeEntryByDraftId(int $draftId): void
    {
        $records = $this->getReleaseEntryRecordsByDraftId($draftId);
        foreach ($records as $record) {
            $record->delete();
        }
    }

    /**
     * Get releases that contain a given draft ID.
     *
     * @return Release[]
     */
    public function getReleasesForDraft(int $draftId): array
    {
        $releaseIds = (new Query())
            ->select('releaseId')
            ->from(ReleaseEntryRecord::tableName())
            ->where(['draftId' => $draftId])
            ->column();

        if (empty($releaseIds)) {
            return [];
        }

        $releases = [];
        foreach ($releaseIds as $releaseId) {
            $release = $this->getReleaseById($releaseId);
            if ($release) {
                $releases[] = $release;
            }
        }

        return $releases;
    }

    /**
     * @return ReleaseEntry[]
     */
    private function _getEntriesForRelease(int $releaseId): array
    {
        /** @var ReleaseEntryRecord[] $records */
        $records = ReleaseEntryRecord::find()
            ->where(['releaseId' => $releaseId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        $entries = [];
        foreach ($records as $record) {
            $entry = new ReleaseEntry();
            $entry->id = $record->id;
            $entry->releaseId = $record->releaseId;
            $entry->canonicalId = $record->canonicalId;
            $entry->draftId = $record->draftId;
            $entry->sortOrder = $record->sortOrder;
            $entry->status = $record->status;
            $entry->errorMessage = $record->errorMessage;
            $entries[] = $entry;
        }

        return $entries;
    }

    private function _populateRelease(ReleaseRecord $record): Release
    {
        $release = new Release();
        $release->id = $record->id;
        $release->siteId = $record->siteId;
        $release->name = $record->name;
        $release->description = $record->description;
        $release->status = ReleaseStatus::tryFrom($record->status) ?? ReleaseStatus::Draft;
        $release->scheduledDate = $record->scheduledDate ? new \DateTime($record->scheduledDate) : null;
        $release->publishedDate = $record->publishedDate ? new \DateTime($record->publishedDate) : null;
        $release->publishedBy = $record->publishedBy;
        $release->createdBy = $record->createdBy;
        $release->dateCreated = $record->dateCreated ? new \DateTime($record->dateCreated) : null;
        $release->dateUpdated = $record->dateUpdated ? new \DateTime($record->dateUpdated) : null;
        $release->uid = $record->uid;

        $release->setEntries($this->_getEntriesForRelease($release->id));

        return $release;
    }
}
