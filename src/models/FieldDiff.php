<?php

namespace justinholtweb\peek\models;

use craft\base\Model;

class FieldDiff extends Model
{
    public ?string $handle = null;
    public ?string $label = null;
    public ?string $type = null;
    public mixed $oldValue = null;
    public mixed $newValue = null;
    public ?string $diffHtml = null;
    public bool $hasChanges = false;

    /** Set when the field's value couldn't be read or compared. */
    public ?string $error = null;

    public function defineRules(): array
    {
        return [
            [['handle', 'label'], 'required'],
            [['handle', 'label', 'type'], 'string'],
            [['hasChanges'], 'boolean'],
        ];
    }
}
