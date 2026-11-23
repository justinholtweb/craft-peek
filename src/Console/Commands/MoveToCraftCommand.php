<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Release\Releases as CraftReleases;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Console\Commands\Concerns\ResolvesReleases;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Throwable;

/**
 * Moves Peek's unpublished releases into Craft's own (Craft Pro and Enterprise).
 *
 * Each becomes a Craft release with the same name, description and drafts, made and attached as
 * `--as` (Craft checks that user's rights on every draft). A scheduled one is scheduled again for
 * the same time if it's still in the future. A Peek release is deleted once all its drafts have
 * moved; one that couldn't move completely keeps the drafts that stayed, and says why.
 *
 * Craft lets a draft be in only one release, so a draft in two Peek releases moves with the first.
 */
class MoveToCraftCommand extends Command
{
    use CraftCommand;
    use ResolvesReleases;

    #[\Override]
    protected $signature = 'peek:releases:move-to-craft
        {--as= : The user who makes the Craft releases (username or email); needs Craft’s Manage releases permission}
        {--id=* : Only these Peek release IDs}
        {--dry-run : List what would move, without moving anything}';

    #[\Override]
    protected $description = 'Moves Peek’s unpublished releases into Craft’s own releases (Craft Pro and Enterprise).';

    public function handle(ReleaseSystem $system, Releases $peek, CraftReleases $craft): int
    {
        if (! $system->usesCraft()) {
            $this->error('Craft’s own releases need Craft Pro or Enterprise. On Solo and Team, Peek’s releases are the release system.');

            return self::INVALID;
        }

        $query = Release::query()
            ->whereNotIn('status', [ReleaseStatus::Published, ReleaseStatus::Publishing])
            ->orderBy('id');

        if (($ids = array_filter(array_map('intval', (array) $this->option('id')))) !== []) {
            $query->whereIn('id', $ids);
        }

        $releases = $query->get();

        if ($releases->isEmpty()) {
            $this->line('No unpublished Peek releases to move.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Name', 'Status', 'Drafts', 'Scheduled (UTC)'],
                $releases->map(fn (Release $r) => [(string) $r->id, $r->name, $r->status->value, (string) $r->entries()->whereNotNull('draftId')->count(), self::utc($r->scheduledDate)])->all(),
            );

            return self::SUCCESS;
        }

        $user = $this->actingUser(ReleaseSummary::SOURCE_CRAFT);

        if ($user === null) {
            $this->error('Pass --as=<username>: Craft makes releases as a user, and checks their rights on each draft.');

            return self::INVALID;
        }

        if ($user === false || ! $user->can('manageReleases')) {
            if ($user !== false) {
                $this->error('That user needs Craft’s Manage releases permission.');
            }

            return self::INVALID;
        }

        $failed = false;

        foreach ($releases as $release) {
            try {
                $target = $craft->create($release->name, null, $user, $release->description);
            } catch (Throwable $e) {
                $this->error("#{$release->id} “{$release->name}”: couldn’t make the Craft release: {$e->getMessage()}");
                $failed = true;

                continue;
            }

            $moved = 0;
            $problems = [];

            foreach ($release->entries()->whereNotNull('draftId')->get() as $entry) {
                $draft = $peek->draft((int) $entry->draftId);

                if ($draft === null) {
                    $problems[] = "draft #{$entry->draftId} no longer exists";

                    continue;
                }

                try {
                    $craft->attach($target, $draft, $user);
                    $entry->delete();
                    $moved++;
                } catch (Throwable $e) {
                    $problems[] = "“{$draft->title}” (draft #{$draft->id}): {$e->getMessage()}";
                }
            }

            if ($release->status === ReleaseStatus::Scheduled && $release->scheduledDate?->isFuture()) {
                try {
                    $craft->schedule($target, $release->scheduledDate, $user);
                } catch (Throwable $e) {
                    $problems[] = "not scheduled: {$e->getMessage()}";
                }
            }

            $this->info("#{$release->id} “{$release->name}” → Craft release #{$target->id} ({$moved} drafts)");

            foreach ($problems as $problem) {
                $this->warn("  ! {$problem}");
            }

            if ($problems === [] || $release->entries()->whereNotNull('draftId')->doesntExist()) {
                $release->delete();
            } else {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
