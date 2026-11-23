<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Something on Peek's dashboard changed: a draft was saved or deleted, or a release changed.
 *
 * Broadcast on the private `peek.dashboard` channel when the site has Laravel broadcasting set up
 * (Reverb, Pusher, Ably). It carries no content, only that something changed: an open dashboard
 * then fetches its own update, with the viewer's permissions.
 */
final class DashboardChanged implements ShouldBroadcastNow
{
    public function __construct(
        public string $reason,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('peek.dashboard');
    }

    public function broadcastAs(): string
    {
        return 'peek.dashboard.changed';
    }

    /** @return array{reason: string} */
    public function broadcastWith(): array
    {
        return ['reason' => $this->reason];
    }
}
