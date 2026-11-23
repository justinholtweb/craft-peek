<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Jobs\PublishRelease;
use JustinHoltWeb\Peek\Services\Releases;

/**
 * Queues every one of Peek's scheduled releases that's due.
 *
 * Runs every minute on Laravel's scheduler (Peek registers it). Sites that don't run the scheduler
 * call it from cron, as on 5.x. Craft's own releases (Pro and Enterprise) are published by Craft's
 * scheduler, not this.
 */
class SchedulerCheckCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'peek:scheduler:check';

    #[\Override]
    protected $description = 'Queues Peek’s scheduled releases that are due.';

    #[\Override]
    protected $aliases = ['peek/scheduler/check', 'peek/scheduler'];

    public function handle(Releases $releases): int
    {
        $due = $releases->due();

        if ($due->isEmpty()) {
            $this->line('No scheduled releases due.');

            return self::SUCCESS;
        }

        foreach ($due as $release) {
            // Claim it first, so an overlapping run can't queue it twice.
            $claimed = $release->newQuery()
                ->whereKey($release->id)
                ->where('status', ReleaseStatus::Scheduled)
                ->update(['status' => ReleaseStatus::Publishing]);

            if ($claimed === 0) {
                continue;
            }

            PublishRelease::dispatch($release->id);
            $this->line("Queued release #{$release->id}: {$release->name}");
        }

        return self::SUCCESS;
    }
}
