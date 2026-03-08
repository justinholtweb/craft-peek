<?php

namespace justinholtweb\peek\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $siteId
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property string|null $scheduledDate
 * @property string|null $publishedDate
 * @property int|null $publishedBy
 * @property int $createdBy
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ReleaseRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%peek_releases}}';
    }
}
