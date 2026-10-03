<?php

namespace justinholtweb\peek\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
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
        // UTC, like every other date Craft stores — the scheduler compares against UTC now.
        $record->scheduledDate = Db::prepareDateForDb($release->scheduledDate);
        $record->publishedDate = Db::prepareDateForDb($release->publishedDate);
        $record->publishedBy = $release->publishedBy;
        $record->createdBy = $release->createdBy;
        $record->scheduledBy = $release->scheduledBy;

        if (!$record->save()) {
            $release->addErrors($record->getErrors());
            return false;
        }

        $release->id = $record->id;
        $release->uid = $record->uid;
        $release->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $release->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;

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
        // The draft decides which entry it belongs to. Before 5.0.4 the canonical ID was taken as
        // given, so a release could record one entry while applying a draft of another, and a
        // provisional draft (someone's unsaved edits) could be published from under them.
        $draft = Entry::find()->id($draftId)->drafts(true)->provisionalDrafts(null)->status(null)->site('*')->one();

        if ($draft === null || $draft->isProvisionalDraft || $draft->getCanonicalId() !== $canonicalId) {
            return false;
        }

        $release = $this->getReleaseById($releaseId);

        if ($release === null || !$this->isEditable($release)) {
            return false;
        }

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
        $release = $this->getReleaseById($releaseId);

        if ($release === null || !$this->isEditable($release)) {
            return false;
        }

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
     * Whether a release's contents and settings can still change. Once it is publishing or
     * published, its entries are history.
     */
    public function isEditable(Release $release): bool
    {
        return !in_array($release->status, [ReleaseStatus::Publishing, ReleaseStatus::Published], true);
    }

    /**
     * The drafts in a release that `$user` could not publish through Craft's own editor —
     * the same two checks Craft's "apply draft" action makes: save the draft, and save the
     * entry it belongs to.
     *
     * @return string[] One message per draft the user may not apply; empty if they may apply them all.
     */
    public function unauthorizedEntries(Release $release, User $user): array
    {
        $elements = Craft::$app->getElements();
        $errors = [];

        foreach ($release->id ? $this->_getEntriesForRelease($release->id) : [] as $entry) {
            if (!$entry->draftId) {
                continue;
            }

            $draft = Entry::find()->id($entry->draftId)->drafts(true)->provisionalDrafts(null)->status(null)->site('*')->one();

            if ($draft === null) {
                continue;
            }

            if (!$elements->canSave($draft, $user) || !$elements->canSaveCanonical($draft, $user)) {
                $errors[] = Craft::t('peek', '{user} isn’t allowed to publish “{title}”.', [
                    'user' => $user->getName(),
                    'title' => $draft->title ?? "#{$draft->id}",
                ]);
            }
        }

        return $errors;
    }

    /**
     * Publish all entries in a release atomically.
     * All drafts are applied within a DB transaction — all succeed or none do.
     *
     * With `$user`, every draft must be one that user could apply in Craft's own editor, or
     * nothing is published and the reasons are added to the release's `entries` errors. The
     * controller passes the signed-in user and the queue job the user who scheduled the release.
     * Without one, the caller is trusted — code calling the service directly.
     */
    public function publishRelease(Release $release, ?User $user = null): bool
    {
        // Publishing is terminal. Re-running it would have nothing left to apply,
        // since applying a draft removes it.
        if (!$release->status->isPublishable()) {
            return false;
        }

        $errors = $this->validateRelease($release);

        if (empty($errors) && $user !== null) {
            $errors = $this->unauthorizedEntries($release, $user);
        }

        if (!empty($errors)) {
            Craft::warning("Release #{$release->id} was not published: " . implode(' ', $errors), 'peek');

            // The scheduler sets Publishing before queueing the job. Left there, a refused
            // release could never be edited, rescheduled or published again.
            if ($release->status === ReleaseStatus::Publishing) {
                $release->status = ReleaseStatus::Failed;
                $this->saveRelease($release);
            }

            // After the save: validate() clears a model's errors.
            $release->addErrors(['entries' => $errors]);

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
            $release->publishedBy = $user->id ?? Craft::$app->getUser()->getId();
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
        // Stored as UTC; toDateTime() reads a bare string as UTC.
        $release->scheduledDate = DateTimeHelper::toDateTime($record->scheduledDate) ?: null;
        $release->publishedDate = DateTimeHelper::toDateTime($record->publishedDate) ?: null;
        $release->publishedBy = $record->publishedBy;
        $release->createdBy = $record->createdBy;
        $release->scheduledBy = $record->scheduledBy;
        $release->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $release->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;
        $release->uid = $record->uid;

        $release->setEntries($this->_getEntriesForRelease($release->id));

        return $release;
    }
}
