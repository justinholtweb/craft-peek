<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Events;

use CraftCms\Cms\User\Contracts\CraftUser;
use JustinHoltWeb\Peek\Models\Release;

/**
 * Dispatched after one of Peek's own releases publishes: every draft in it has been applied.
 *
 * `Event::listen(PeekReleasePublished::class, fn ($e) => …)`. Craft's own releases (Pro and
 * Enterprise) dispatch `CraftCms\Cms\Release\Events\ReleasePublished` instead.
 */
final readonly class PeekReleasePublished
{
    public function __construct(
        public Release $release,
        /** Who it was published as; null for the console's system publish. */
        public ?CraftUser $user,
    ) {}
}
