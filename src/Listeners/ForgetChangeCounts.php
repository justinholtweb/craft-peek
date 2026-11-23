<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Peek\Services\Diff;

/**
 * Any save of an entry or one of its drafts makes the cached "Fields changed" counts for that
 * entry stale. Nested entries (Matrix blocks) count as their owner's.
 */
class ForgetChangeCounts
{
    public function handle(ElementSaved $event): void
    {
        $element = $event->element;

        if (! $element instanceof Entry) {
            return;
        }

        $root = $element;
        for ($depth = 0; $depth < 10 && $root->getOwnerId() !== null; $depth++) {
            $owner = $root->getOwner();
            if (! $owner instanceof Entry) {
                break;
            }
            $root = $owner;
        }

        Diff::bumpVersion((int) $root->getCanonicalId());
    }
}
