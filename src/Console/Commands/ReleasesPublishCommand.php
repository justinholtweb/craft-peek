<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Console\Commands\Concerns\ResolvesReleases;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

/**
 * Publishes a release now: every draft in it is applied, or none is. For deploy pipelines.
 *
 * A Peek release runs as the system unless `--as` names a user: anyone who can run artisan can
 * already change anything on the site. With `--as` it's held to that user's rights, as the CP's
 * Publish button would be. Craft's own releases (Pro and Enterprise) always publish as a user, so
 * they need `--as`.
 */
class ReleasesPublishCommand extends Command
{
    use CraftCommand;
    use ResolvesReleases;

    #[\Override]
    protected $signature = 'peek:releases:publish
        {id : The release ID}
        {--source= : peek or craft (default: the site’s release system)}
        {--as= : Publish as this user (username or email), with their permissions}
        {--dry-run : Check the release could be published, without publishing it}
        {--json : Print JSON}';

    #[\Override]
    protected $description = 'Publishes a release now: every draft, or none.';

    #[\Override]
    protected $aliases = ['peek/releases/publish'];

    public function handle(ReleaseSystem $system): int
    {
        $dryRun = (bool) $this->option('dry-run') || $this->legacyDryRun();

        $source = $this->source($system);
        if ($source === null) {
            return self::INVALID;
        }

        $user = $this->actingUser($source);
        if ($user === false) {
            return self::INVALID;
        }

        $id = (int) $this->argument('id');
        $release = $system->find($source, $id);

        if ($release === null) {
            $this->error("Release #{$id} not found.");

            return self::INVALID;
        }

        if ($release->status === 'published') {
            return $this->result($release, false, ['The release is already published.']);
        }

        // The scheduler sets Publishing before it queues the job; publishing here too would race it.
        if ($release->status === 'publishing') {
            return $this->result($release, false, ['The release is already being published.']);
        }

        if ($source === ReleaseSummary::SOURCE_CRAFT && $user === null) {
            return $this->result($release, false, ['Craft’s releases publish as a user. Pass --as=<username>.']);
        }

        $problems = $system->problems($source, $id, $user);

        if ($dryRun) {
            return $this->result($release, $problems === [], $problems, dryRun: true);
        }

        if ($problems !== []) {
            return $this->result($release, false, $problems);
        }

        if ($this->input->isInteractive() && ! $this->option('json')
            && ! $this->confirm("Publish release #{$release->id} “{$release->name}” ({$release->entryCount} entries) now?", true)) {
            $this->line('Not published.');

            return self::SUCCESS;
        }

        $problems = $system->publish($source, $id, $user);

        return $this->result($system->find($source, $id) ?? $release, $problems === [], $problems);
    }

    /**
     * In Craft 5, `-n` was this command's `--dry-run`. In Laravel it's `--no-interaction`, so a 5.x
     * script that checked a release with `php craft peek/releases/publish 5 -n` would publish it.
     * Under the Craft 5 name, `-n` keeps its old meaning.
     */
    private function legacyDryRun(): bool
    {
        $argv = $this->rawArgv();
        $legacyName = in_array('peek/releases/publish', $argv, true) || in_array('craft:peek/releases/publish', $argv, true);

        if (! $legacyName || ! in_array('-n', $argv, true)) {
            return false;
        }

        $this->warn('-n is a dry run under the Craft 5 name. Use peek:releases:publish --dry-run (or --no-interaction to publish).');

        return true;
    }

    /** @return list<string> */
    protected function rawArgv(): array
    {
        return array_values(array_map('strval', (array) ($_SERVER['argv'] ?? [])));
    }

    /**
     * @param  list<string>  $problems
     */
    private function result(ReleaseSummary $release, bool $ok, array $problems, bool $dryRun = false): int
    {
        if ($this->option('json')) {
            $this->json($release->toArray() + [
                'dryRun' => $dryRun,
                'published' => $ok && ! $dryRun,
                'problems' => $problems,
            ]);
        } elseif ($ok) {
            $this->info($dryRun
                ? "Release #{$release->id} can be published."
                : "Published release #{$release->id}: {$release->name}");
        } else {
            $this->error($dryRun ? "Release #{$release->id} can’t be published:" : "Release #{$release->id} was not published:");

            foreach ($problems as $problem) {
                $this->line("  - {$problem}");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
