<?php

namespace justinholtweb\peek\models;

use craft\base\Model;
use DateTime;
use justinholtweb\peek\enums\ReleaseStatus;

class Release extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public ?string $name = null;
    public ?string $description = null;
    public ReleaseStatus $status = ReleaseStatus::Draft;
    public ?DateTime $scheduledDate = null;
    public ?DateTime $publishedDate = null;
    public ?int $publishedBy = null;
    public ?int $createdBy = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    /** @var ReleaseEntry[] */
    private array $_entries = [];

    public function defineRules(): array
    {
        return [
            [['name', 'siteId'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['siteId', 'publishedBy', 'createdBy'], 'integer'],
        ];
    }

    /**
     * @return ReleaseEntry[]
     */
    public function getEntries(): array
    {
        return $this->_entries;
    }

    /**
     * @param ReleaseEntry[] $entries
     */
    public function setEntries(array $entries): void
    {
        $this->_entries = $entries;
    }

    public function getEntryCount(): int
    {
        return count($this->_entries);
    }
}
