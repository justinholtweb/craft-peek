<?php

namespace justinholtweb\peek\models;

use craft\base\Model;
use DateTime;

class ReleaseEntry extends Model
{
    public ?int $id = null;
    public ?int $releaseId = null;
    public ?int $canonicalId = null;
    public ?int $draftId = null;
    public int $sortOrder = 0;
    public string $status = 'pending';
    public ?string $errorMessage = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public function defineRules(): array
    {
        return [
            [['releaseId', 'canonicalId'], 'required'],
            [['releaseId', 'canonicalId', 'draftId', 'sortOrder'], 'integer'],
            [['status'], 'in', 'range' => ['pending', 'published', 'failed']],
            [['errorMessage'], 'string'],
        ];
    }
}
