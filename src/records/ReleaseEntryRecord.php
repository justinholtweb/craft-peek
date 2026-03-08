<?php

namespace justinholtweb\peek\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $releaseId
 * @property int $canonicalId
 * @property int $draftId
 * @property int $sortOrder
 * @property string $status
 * @property string|null $errorMessage
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReleaseEntryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%peek_release_entries}}';
    }
}
