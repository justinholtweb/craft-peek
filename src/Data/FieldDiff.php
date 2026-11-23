<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Data;

/**
 * One field (or native attribute) compared between a draft and its entry.
 */
final class FieldDiff
{
    public function __construct(
        public string $handle,
        public string $label,
        /** `native`, or the field's class. */
        public string $type,
        public mixed $oldValue = null,
        public mixed $newValue = null,
        public bool $hasChanges = false,
        public ?string $diffHtml = null,
        /** Set when the field couldn't be read, instead of taking down the whole diff. */
        public ?string $error = null,
    ) {}
}
