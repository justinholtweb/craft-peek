<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use JustinHoltWeb\Peek\Plugin;

// Who may listen for dashboard changes. Requires the app's broadcasting routes
// (`Broadcast::routes()`), which a site sets up when it adds Reverb, Pusher or Ably.
Broadcast::channel('peek.dashboard', fn ($user) => method_exists($user, 'can') && $user->can(Plugin::PERMISSION_ACCESS));
