<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Peek\Services\Releases;

/**
 * A draft that's deleted (discarded, not applied) leaves every unpublished Peek release it was in.
 */
readonly class RemoveDiscardedDraft
{
    public function __construct(
        private Releases $releases,
    ) {}

    public function handle(ElementDeleting $event): void
    {
        $element = $event->element;

        if ($element instanceof Entry && $element->getIsDraft() && ! $element->isProvisionalDraft && $element->id) {
            $this->releases->draftDeleting((int) $element->id);
        }
    }
}
