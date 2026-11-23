<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Console\Commands\Concerns;

use Carbon\Carbon;
use CraftCms\Cms\Support\Facades\Users;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Console\Command;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

/**
 * @mixin Command
 */
trait ResolvesReleases
{
    /**
     * `--source`, defaulting to the site's release system: Peek's own on Craft Solo and Team,
     * Craft's on Pro and Enterprise.
     */
    protected function source(ReleaseSystem $system): ?string
    {
        $source = $this->option('source');

        if ($source === null || $source === '') {
            return $system->primarySource();
        }

        if (! in_array($source, [ReleaseSummary::SOURCE_PEEK, ReleaseSummary::SOURCE_CRAFT], true)) {
            $this->error('--source must be "peek" or "craft".');

            return null;
        }

        if ($source === ReleaseSummary::SOURCE_CRAFT && ! $system->usesCraft()) {
            $this->error('Craft’s own releases need Craft Pro or Enterprise.');

            return null;
        }

        return $source;
    }

    /**
     * The `--as` user: active, and allowed to publish in the release system being used.
     */
    protected function actingUser(string $source): User|false|null
    {
        $as = $this->option('as');

        if ($as === null || $as === '') {
            return null;
        }

        $user = Users::getUserByUsernameOrEmail((string) $as);
        $permission = $source === ReleaseSummary::SOURCE_CRAFT ? 'publishReleases' : 'peek:publishReleases';

        if (! $user instanceof User || $user->getStatus() !== User::STATUS_ACTIVE || ! $user->can($permission)) {
            $this->error("“{$as}” isn’t an active user who can publish releases.");

            return false;
        }

        return $user;
    }

    protected function json(mixed $data): void
    {
        $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected static function utc(?\DateTimeInterface $date): string
    {
        return $date ? Carbon::instance($date)->utc()->format('Y-m-d H:i') : '—';
    }
}
