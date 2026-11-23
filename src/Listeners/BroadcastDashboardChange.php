<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\Peek\Events\DashboardChanged;
use JustinHoltWeb\Peek\Events\PeekReleasePublished;
use Throwable;

/**
 * Tells open dashboards that a draft or release changed, when the site broadcasts at all.
 */
class BroadcastDashboardChange
{
    /** Drivers that don't reach a browser. */
    private const array SILENT_DRIVERS = ['null', 'log'];

    public static function enabled(): bool
    {
        $driver = config('broadcasting.default');

        return is_string($driver) && $driver !== '' && ! in_array($driver, self::SILENT_DRIVERS, true);
    }

    public function handle(ElementSaved|ElementDeleted|PeekReleasePublished $event): void
    {
        if (! self::enabled()) {
            return;
        }

        if ($event instanceof PeekReleasePublished) {
            $reason = 'release';
        } elseif ($event->element instanceof Entry && $event->element->getIsDraft() && ! $event->element->isProvisionalDraft) {
            $reason = 'draft';
        } else {
            return;
        }

        try {
            broadcast(new DashboardChanged($reason));
        } catch (Throwable $e) {
            // A broadcaster that's down mustn't break saving an entry.
            report($e);
        }
    }
}
