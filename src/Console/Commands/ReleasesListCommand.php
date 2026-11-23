<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Console\Commands\Concerns\ResolvesReleases;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

/**
 * Lists releases, newest first.
 */
class ReleasesListCommand extends Command
{
    use CraftCommand;
    use ResolvesReleases;

    #[\Override]
    protected $signature = 'peek:releases:list
        {--status= : Only releases in this status}
        {--site= : Only Peek releases for this site handle}
        {--source= : peek or craft (default: the site’s release system)}
        {--all : Both sources, on Craft Pro and Enterprise}
        {--json : Print JSON instead of a table}';

    #[\Override]
    protected $description = 'Lists releases, newest first.';

    /** The Craft 5 command and its default action. */
    #[\Override]
    protected $aliases = ['peek/releases/list', 'peek/releases'];

    public function handle(ReleaseSystem $system): int
    {
        $source = $this->option('all') ? null : $this->source($system);

        if ($source === null && ! $this->option('all')) {
            return self::INVALID;
        }

        $siteId = null;
        if (($handle = $this->option('site')) !== null) {
            $site = Sites::getSiteByHandle((string) $handle, true);

            if ($site === null) {
                $this->error("No site with the handle “{$handle}”.");

                return self::INVALID;
            }

            $siteId = (int) $site->id;
        }

        $releases = $system->all($source, $this->option('status') ?: null, $siteId);

        if ($this->option('json')) {
            $this->json(array_map(fn (ReleaseSummary $r) => $r->toArray(), $releases));

            return self::SUCCESS;
        }

        if ($releases === []) {
            $this->line('No releases.');

            return self::SUCCESS;
        }

        $this->table(
            ['Source', 'ID', 'Name', 'Site', 'Status', 'Entries', 'Scheduled (UTC)', 'Published (UTC)'],
            array_map(fn (ReleaseSummary $r) => [
                $r->source,
                (string) $r->id,
                $r->name,
                $r->site ?? '—',
                $r->status,
                (string) $r->entryCount,
                self::utc($r->scheduledAt),
                self::utc($r->publishedAt),
            ], $releases),
        );

        return self::SUCCESS;
    }
}
