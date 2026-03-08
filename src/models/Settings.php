<?php

namespace justinholtweb\peek\models;

use craft\base\Model;

class Settings extends Model
{
    /** How many days before a draft is considered "stale" */
    public int $staleDraftDays = 14;

    /** Default site ID for releases (null = primary site) */
    public ?int $defaultSiteId = null;

    /** Enable visual preview iframe comparison */
    public bool $enableVisualPreview = true;

    /** Max entries allowed per release */
    public int $maxEntriesPerRelease = 50;

    public function defineRules(): array
    {
        return [
            [['staleDraftDays'], 'integer', 'min' => 1],
            [['defaultSiteId'], 'integer', 'skipOnEmpty' => true],
            [['enableVisualPreview'], 'boolean'],
            [['maxEntriesPerRelease'], 'integer', 'min' => 1, 'max' => 500],
        ];
    }
}
