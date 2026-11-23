<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Services;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Release\Enums\ReleaseStatus as CraftReleaseStatus;
use CraftCms\Cms\Release\Exceptions\ReleaseException;
use CraftCms\Cms\Release\Models\Release as CraftRelease;
use CraftCms\Cms\Release\Releases as CraftReleases;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\ReleaseEntry;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * One release system per site.
 *
 * Craft 6 Pro and Enterprise ship releases of their own (`CraftCms\Cms\Release`); Solo and Team
 * don't. So on Pro and up, Peek works with Craft's releases, and on Solo and Team it keeps its
 * own. Running both side by side would mean two systems applying the same drafts, and Craft locks
 * a draft that's in one of its releases.
 *
 * Releases already made in Peek's tables on a Pro site (an upgrade, or a Team site moving up) are
 * "Peek releases": they stay readable and publishable, nothing new can be added to them, and
 * `peek:releases:move-to-craft` moves them across.
 */
class ReleaseSystem
{
    public function __construct(
        private readonly Releases $peek,
        private readonly CraftReleases $craft,
    ) {}

    /** Whether Craft's own releases are the release system on this install. */
    public function usesCraft(): bool
    {
        return Edition::get()->supportsReleases();
    }

    /** The source new releases and newly added drafts go to. */
    public function primarySource(): string
    {
        return $this->usesCraft() ? ReleaseSummary::SOURCE_CRAFT : ReleaseSummary::SOURCE_PEEK;
    }

    /** Where the release list lives in the CP. */
    public function indexUrl(): string
    {
        return $this->usesCraft() ? cp_url('releases') : cp_url('peek/releases');
    }

    /**
     * @return list<ReleaseSummary>
     */
    public function all(?string $source = null, ?string $status = null, ?int $siteId = null): array
    {
        $summaries = [];
        $wantPeek = $source === null || $source === ReleaseSummary::SOURCE_PEEK;
        // Craft's releases aren't tied to a site, so a site filter leaves them out.
        $wantCraft = $this->usesCraft() && $siteId === null && ($source === null || $source === ReleaseSummary::SOURCE_CRAFT);

        if ($wantPeek) {
            $peekStatus = $status !== null ? ReleaseStatus::tryFrom($status) : null;

            if ($status === null || $peekStatus !== null) {
                foreach ($this->peek->all($siteId, $peekStatus) as $release) {
                    $summaries[] = $this->fromPeek($release);
                }
            }
        }

        if ($wantCraft) {
            $craftStatus = $status !== null ? CraftReleaseStatus::tryFrom($status) : null;

            if ($status === null || $craftStatus !== null) {
                $query = CraftRelease::query()->withCount('items')->orderByDesc('dateCreated');

                if ($craftStatus !== null) {
                    $query->where('status', $craftStatus);
                }

                foreach ($query->get() as $release) {
                    $summaries[] = $this->fromCraft($release);
                }
            }
        }

        return $summaries;
    }

    public function find(string $source, int $id, bool $withEntries = false): ?ReleaseSummary
    {
        if ($source === ReleaseSummary::SOURCE_CRAFT) {
            if (! $this->usesCraft()) {
                return null;
            }

            $release = CraftRelease::query()->withCount('items')->find($id);

            return $release instanceof CraftRelease ? $this->fromCraft($release, $withEntries) : null;
        }

        $release = $this->peek->find($id);

        return $release !== null ? $this->fromPeek($release, $withEntries) : null;
    }

    /** Whether any of Peek's own releases are still to be published (or moved to Craft's). */
    public function hasUnfinishedPeekReleases(): bool
    {
        return Release::query()->whereNotIn('status', [ReleaseStatus::Published])->exists();
    }

    /** How many releases are still in progress, across both sources. */
    public function activeCount(): int
    {
        $count = Release::query()->whereNotIn('status', [ReleaseStatus::Published, ReleaseStatus::Failed])->count();

        if ($this->usesCraft()) {
            $count += CraftRelease::query()->whereNotIn('status', [CraftReleaseStatus::Published, CraftReleaseStatus::Failed])->count();
        }

        return $count;
    }

    /**
     * The unpublished releases a draft is in.
     *
     * @return list<ReleaseSummary>
     */
    public function forDraft(Entry $draft): array
    {
        $summaries = [];

        foreach ($this->peek->forDraft((int) $draft->id) as $release) {
            $summaries[] = $this->fromPeek($release);
        }

        if ($this->usesCraft() && $draft->draftId) {
            $release = $this->craft->releaseForDraft((int) $draft->draftId);

            if ($release !== null) {
                $summaries[] = $this->fromCraft($release);
            }
        }

        return $summaries;
    }

    /**
     * Releases the draft could be added to right now.
     *
     * @return list<ReleaseSummary>
     */
    public function addableFor(Entry $draft, CraftUser $user): array
    {
        if ($this->usesCraft()) {
            if (! $draft->draftId || ! $user->can('manageReleases') || $this->craft->releaseForDraft((int) $draft->draftId) !== null) {
                return [];
            }

            return array_values(array_map(
                fn (CraftRelease $release) => $this->fromCraft($release),
                CraftRelease::query()
                    ->withCount('items')
                    ->whereIn('status', [CraftReleaseStatus::Draft, CraftReleaseStatus::Scheduled, CraftReleaseStatus::Failed])
                    ->whereDoesntHave('items', fn ($q) => $q->where('canonicalId', $draft->getCanonicalId()))
                    ->orderBy('name')
                    ->get()
                    ->all(),
            ));
        }

        if (! $user->can('peek:manageReleases') || ! $this->peek->canApply($draft, $user)) {
            return [];
        }

        $in = $this->peek->forDraft((int) $draft->id)->pluck('id')->all();

        return array_values(array_map(
            fn (Release $release) => $this->fromPeek($release),
            array_filter(
                $this->peek->all()->all(),
                fn (Release $release) => $release->status->isEditable() && ! in_array($release->id, $in, true),
            ),
        ));
    }

    /**
     * @return string|null Why it couldn't be added, or null when it was.
     */
    public function addDraft(string $source, int $releaseId, Entry $draft, CraftUser $user): ?string
    {
        if ($source === ReleaseSummary::SOURCE_CRAFT) {
            $release = $this->usesCraft() ? CraftRelease::query()->find($releaseId) : null;

            if (! $release instanceof CraftRelease) {
                return t('Release not found.', category: 'peek');
            }

            try {
                $this->craft->attach($release, $draft, $user);
            } catch (ReleaseException $e) {
                return $e->getMessage();
            }

            return null;
        }

        if ($this->usesCraft()) {
            return t('New drafts go into Craft’s releases on this edition.', category: 'peek');
        }

        $release = $this->peek->find($releaseId);

        return $release === null
            ? t('Release not found.', category: 'peek')
            : $this->peek->addDraft($release, $draft, $user);
    }

    /**
     * What would stop a release publishing, as `$user` when one is given.
     *
     * @return list<string>
     */
    public function problems(string $source, int $id, ?CraftUser $user = null): array
    {
        if ($source === ReleaseSummary::SOURCE_CRAFT) {
            $release = $this->usesCraft() ? CraftRelease::query()->find($id) : null;

            if (! $release instanceof CraftRelease) {
                return [t('Release not found.', category: 'peek')];
            }

            if ($user === null) {
                return $release->items()->exists() ? [] : [t('Release has no entries.', category: 'peek')];
            }

            try {
                $readiness = $this->craft->checkReadiness($release, $user);
            } catch (ReleaseException $e) {
                return [$e->getMessage()];
            }

            return [...$readiness->errors, ...array_merge(...array_values($readiness->itemErrors) ?: [[]])];
        }

        $release = $this->peek->find($id);

        if ($release === null) {
            return [t('Release not found.', category: 'peek')];
        }

        $problems = $release->status === ReleaseStatus::Published ? [] : $this->peek->problems($release);

        if ($problems === [] && $user !== null && $release->status !== ReleaseStatus::Published) {
            $problems = $this->peek->unauthorized($release, $user);
        }

        return $problems;
    }

    /**
     * Publishes now.
     *
     * Craft's releases always publish as a person, so a Craft release needs `$user`. A Peek
     * release without one is the trusted system publish (the console without `--as`).
     *
     * @return list<string> Why it wasn't published; empty when it was.
     */
    public function publish(string $source, int $id, ?CraftUser $user): array
    {
        if ($source === ReleaseSummary::SOURCE_CRAFT) {
            $release = $this->usesCraft() ? CraftRelease::query()->find($id) : null;

            if (! $release instanceof CraftRelease) {
                return [t('Release not found.', category: 'peek')];
            }

            if ($user === null) {
                return [t('Craft’s releases publish as a user. Pass --as=<username>.', category: 'peek')];
            }

            try {
                $release = $this->craft->publish($release, $user);
            } catch (Throwable $e) {
                return [$e->getMessage()];
            }

            return $release->status === CraftReleaseStatus::Published
                ? []
                : [$release->lastError ?? t('The release wasn’t published.', category: 'peek')];
        }

        $release = $this->peek->find($id);

        return $release === null ? [t('Release not found.', category: 'peek')] : $this->peek->publish($release, $user);
    }

    public function fromPeek(Release $release, bool $withEntries = false): ReleaseSummary
    {
        $entries = [];

        if ($withEntries) {
            foreach ($release->entries()->get() as $entry) {
                /** @var ReleaseEntry $entry */
                $entries[] = [
                    'canonicalId' => $entry->canonicalId,
                    'draftId' => $entry->draftId,
                    'status' => $entry->status,
                ];
            }
        }

        $count = $release->getAttribute('entries_count');

        return new ReleaseSummary(
            source: ReleaseSummary::SOURCE_PEEK,
            id: $release->id,
            name: $release->name,
            status: $release->status->value,
            statusLabel: $release->status->label(),
            color: $release->status->color(),
            site: Sites::getSiteById($release->siteId, true)?->handle,
            entryCount: is_numeric($count) ? (int) $count : $release->entries()->count(),
            scheduledAt: $release->scheduledDate,
            publishedAt: $release->publishedDate,
            cpUrl: cp_url("peek/releases/{$release->id}"),
            // On Pro, Peek's own releases only finish what they started.
            editable: $release->status->isEditable() && ! $this->usesCraft(),
            active: $release->status->isActive(),
            entries: $entries,
        );
    }

    public function fromCraft(CraftRelease $release, bool $withEntries = false): ReleaseSummary
    {
        $entries = [];

        if ($withEntries) {
            foreach ($release->items()->get() as $item) {
                $draftElementId = $item->draftId
                    ? Entry::find()->draftId($item->draftId)->provisionalDrafts(null)->status(null)->site('*')->ids()[0] ?? null
                    : null;

                $entries[] = [
                    'canonicalId' => $item->canonicalId,
                    'draftId' => $draftElementId !== null ? (int) $draftElementId : null,
                    'status' => $item->publishedAt !== null ? ReleaseEntry::STATUS_PUBLISHED : ReleaseEntry::STATUS_PENDING,
                ];
            }
        }

        $count = $release->getAttribute('items_count');

        return new ReleaseSummary(
            source: ReleaseSummary::SOURCE_CRAFT,
            id: $release->id,
            name: $release->name,
            status: $release->status->value,
            statusLabel: $release->status->label(),
            color: $release->status->color()->value,
            site: null,
            entryCount: is_numeric($count) ? (int) $count : $release->items()->count(),
            scheduledAt: $release->status === CraftReleaseStatus::Scheduled ? $release->publishedAt : null,
            publishedAt: $release->status === CraftReleaseStatus::Published ? $release->publishedAt : null,
            cpUrl: cp_url("releases/{$release->id}"),
            editable: $release->status->isEditable(),
            active: ! in_array($release->status, [CraftReleaseStatus::Published, CraftReleaseStatus::Failed], true),
            entries: $entries,
            lastError: $release->lastError,
        );
    }
}
