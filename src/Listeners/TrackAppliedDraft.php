<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Element\Events\DraftApplied;
use CraftCms\Cms\Element\Events\DraftApplying;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Peek\Services\Releases;

/**
 * Marks a draft's rows in Peek's releases published *before* it's applied (applying deletes the
 * draft element, and the foreign key then nulls the rows' draftId), and remembers it's being
 * applied so the delete that follows isn't mistaken for a discard.
 *
 * Fires for every apply: Peek's own publish, Craft's Apply draft button, and Craft's releases.
 */
readonly class TrackAppliedDraft
{
    public function __construct(
        private Releases $releases,
    ) {}

    public function handle(DraftApplying|DraftApplied $event): void
    {
        $draft = $event->draft;

        if (! $draft instanceof Entry || ! $draft->id || $event->provisional) {
            return;
        }

        if ($event instanceof DraftApplying) {
            $this->releases->draftApplying((int) $draft->id);
        } else {
            $this->releases->draftApplied((int) $draft->id);
        }
    }
}
