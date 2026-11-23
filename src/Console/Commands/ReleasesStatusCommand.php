<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Console\Commands\Concerns\ResolvesReleases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

/**
 * One release, its drafts, and anything that would stop it publishing.
 *
 * Exits 0 for a release that's fine, 1 for one that failed or can't be published as it stands.
 */
class ReleasesStatusCommand extends Command
{
    use CraftCommand;
    use ResolvesReleases;

    #[\Override]
    protected $signature = 'peek:releases:status
        {id : The release ID}
        {--source= : peek or craft (default: the site’s release system)}
        {--as= : Check as this user (username or email), with their permissions}
        {--json : Print JSON}';

    #[\Override]
    protected $description = 'Shows a release and anything that would stop it publishing.';

    #[\Override]
    protected $aliases = ['peek/releases/status'];

    public function handle(ReleaseSystem $system): int
    {
        $source = $this->source($system);
        if ($source === null) {
            return self::INVALID;
        }

        $user = $this->actingUser($source);
        if ($user === false) {
            return self::INVALID;
        }

        $id = (int) $this->argument('id');
        $release = $system->find($source, $id, withEntries: true);

        if ($release === null) {
            $this->error("Release #{$id} not found.");

            return self::INVALID;
        }

        // A published release's drafts are gone by design: only a pending one has problems.
        $problems = $release->status === 'published' ? [] : $system->problems($source, $id, $user);
        $healthy = $release->status !== 'failed' && $problems === [];

        if ($this->option('json')) {
            $this->json($release->toArray(withEntries: true) + ['problems' => $problems]);

            return $healthy ? self::SUCCESS : self::FAILURE;
        }

        $this->line("<options=bold>Release #{$release->id}: {$release->name}</> ({$release->source})");
        $this->line("  Status:     {$release->status}");
        $this->line('  Site:       '.($release->site ?? '—'));
        $this->line('  Scheduled:  '.self::utc($release->scheduledAt).' UTC');
        $this->line('  Published:  '.self::utc($release->publishedAt).' UTC');
        $this->line("  Entries:    {$release->entryCount}");

        if ($release->entries !== []) {
            $this->table(
                ['Entry', 'Draft', 'Status'],
                array_map(fn (array $e) => [(string) $e['canonicalId'], $e['draftId'] !== null ? (string) $e['draftId'] : '—', $e['status']], $release->entries),
            );
        }

        if ($release->lastError !== null) {
            $this->warn("  Last error: {$release->lastError}");
        }

        foreach ($problems as $problem) {
            $this->warn("  ! {$problem}");
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
