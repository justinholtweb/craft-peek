<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Services;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use CraftCms\Cms\Support\Query;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Events\PeekReleasePublished;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\ReleaseEntry;
use JustinHoltWeb\Peek\Models\Settings;
use RuntimeException;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Peek's own releases: the release system on Craft Solo and Team, which have none of their own.
 *
 * On Pro and Enterprise, Craft's releases take over (see {@see ReleaseSystem}); releases already
 * made here keep working until they're published or moved across.
 *
 * Every publish acts as a person: each draft must be one the user could apply in Craft's own
 * editor (the `save` and `saveCanonical` abilities), checked when it's added and again at publish.
 * Passing no user is for trusted code: the console's system publish and tests.
 */
class Releases
{
    /** Draft element IDs being applied right now, so the delete listener can tell apply from delete. */
    /** @var array<int, true> */
    private array $applyingDraftIds = [];

    public function __construct(
        private readonly Settings $settings,
    ) {}

    public function find(int $id): ?Release
    {
        return Release::query()->find($id);
    }

    /**
     * @return Collection<int, Release>
     */
    public function all(?int $siteId = null, ?ReleaseStatus $status = null): Collection
    {
        return Release::query()
            ->withCount('entries')
            ->when($siteId !== null, fn ($q) => $q->where('siteId', $siteId))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('dateCreated')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Releases that still matter to a draft (not published), for its editor sidebar.
     *
     * @return Collection<int, Release>
     */
    public function forDraft(int $draftElementId): Collection
    {
        return Release::query()
            ->whereHas('entries', fn ($q) => $q->where('draftId', $draftElementId))
            ->where('status', '!=', ReleaseStatus::Published)
            ->orderBy('name')
            ->get();
    }

    /**
     * Adds a draft. The entry is the draft's own: before 5.0.4 the canonical ID was taken from the
     * request, so a release could record one entry while applying a draft of another.
     *
     * @return string|null Why it couldn't be added, or null when it was.
     */
    public function addDraft(Release $release, Entry $draft, ?CraftUser $user): ?string
    {
        if (! $draft->getIsDraft() || $draft->isProvisionalDraft) {
            return t('Only saved drafts can be added to a release.', category: 'peek');
        }

        if (! $release->status->isEditable()) {
            return t('A release that is publishing or published can’t be changed.', category: 'peek');
        }

        if ($user !== null && ! $this->canApply($draft, $user)) {
            return t('You can’t publish this draft, so you can’t add it to a release.', category: 'peek');
        }

        return DB::transaction(function () use ($release, $draft): ?string {
            $entries = $release->entries()->lockForUpdate()->get();

            if ($entries->contains('draftId', $draft->id)) {
                return t('That draft is already in this release.', category: 'peek');
            }

            if ($entries->count() >= $this->settings->maxEntriesPerRelease) {
                return t('A release can hold at most {max} drafts.', ['max' => $this->settings->maxEntriesPerRelease], category: 'peek');
            }

            $release->entries()->create([
                'canonicalId' => $draft->getCanonicalId(),
                'draftId' => $draft->id,
                'sortOrder' => ((int) $entries->max('sortOrder')) + 1,
            ]);

            return null;
        });
    }

    public function removeDraft(Release $release, int $draftElementId): bool
    {
        if (! $release->status->isEditable()) {
            return false;
        }

        return $release->entries()->where('draftId', $draftElementId)->delete() > 0;
    }

    /**
     * What would stop the release publishing: missing drafts or entries. Empty when it's fine.
     *
     * @return list<string>
     */
    public function problems(Release $release): array
    {
        $entries = $release->entries()->get();

        if ($entries->isEmpty()) {
            return [t('Release has no entries.', category: 'peek')];
        }

        $problems = [];

        foreach ($entries as $entry) {
            // A null draftId is what an applied (or externally deleted) draft looks like. Never
            // pass it to an element query: `id(null)` drops the filter and matches any draft.
            if (! $entry->draftId) {
                $problems[] = t('Entry #{id} no longer has a draft to publish.', ['id' => $entry->canonicalId], category: 'peek');

                continue;
            }

            if ($this->draft($entry->draftId) === null) {
                $problems[] = t('Draft #{id} no longer exists.', ['id' => $entry->draftId], category: 'peek');

                continue;
            }

            if (! Entry::find()->id($entry->canonicalId)->status(null)->site('*')->exists()) {
                $problems[] = t('Canonical entry #{id} no longer exists.', ['id' => $entry->canonicalId], category: 'peek');
            }
        }

        return $problems;
    }

    /**
     * The drafts in the release `$user` couldn't apply in Craft's own editor.
     *
     * @return list<string>
     */
    public function unauthorized(Release $release, CraftUser $user): array
    {
        $errors = [];

        foreach ($release->entries()->whereNotNull('draftId')->get() as $entry) {
            $draft = $this->draft((int) $entry->draftId);

            if ($draft !== null && ! $this->canApply($draft, $user)) {
                $errors[] = t('{user} isn’t allowed to publish “{title}”.', [
                    'user' => $user->asElement()->getName(),
                    // Only a title they could read anyway.
                    'title' => PendingDrafts::canView($draft, $user) ? ($draft->title ?? "#{$draft->id}") : "#{$draft->id}",
                ], category: 'peek');
            }
        }

        return $errors;
    }

    /** The pair of checks Craft's own "Apply draft" makes. */
    public function canApply(Entry $draft, CraftUser $user): bool
    {
        return $user->can('save', $draft) && $user->can('saveCanonical', $draft);
    }

    /**
     * Applies every draft in the release, or none: one transaction around them all.
     *
     * @return list<string> Why it wasn't published; empty when it was.
     */
    public function publish(Release $release, ?CraftUser $user = null): array
    {
        $release->refresh();

        if (! $release->status->isPublishable()) {
            return [t('The release is already published.', category: 'peek')];
        }

        $problems = $this->problems($release);

        if ($problems === [] && $user !== null) {
            $problems = $this->unauthorized($release, $user);
        }

        if ($problems !== []) {
            Log::warning("Peek release #{$release->id} was not published: ".implode(' ', $problems));

            // The scheduler sets Publishing before queueing the job. Left there, a refused release
            // could never be edited, rescheduled or published again.
            if ($release->status === ReleaseStatus::Publishing) {
                $release->update(['status' => ReleaseStatus::Failed]);
            }

            return $problems;
        }

        $release->update(['status' => ReleaseStatus::Publishing]);

        try {
            DB::transaction(function () use ($release, $user): void {
                foreach ($release->entries()->get() as $entry) {
                    $draft = $entry->draftId ? $this->draft($entry->draftId) : null;

                    if ($draft === null) {
                        throw new RuntimeException("Draft #{$entry->draftId} not found");
                    }

                    // The apply listener marks the row published before the draft element (and so
                    // the row's draftId) goes.
                    Drafts::applyDraft($draft);
                }

                $release->update([
                    'status' => ReleaseStatus::Published,
                    'publishedDate' => now(),
                    'publishedBy' => $user?->getCraftUserId(),
                ]);
            });
        } catch (Throwable $e) {
            $release->update(['status' => ReleaseStatus::Failed]);
            Log::error("Peek couldn't publish release #{$release->id}: {$e->getMessage()}");

            return [t('Applying a draft failed, so nothing was published: {error}', ['error' => $e->getMessage()], category: 'peek')];
        }

        event(new PeekReleasePublished($release->refresh(), $user));

        return [];
    }

    /**
     * Marks the release rows for a draft that's about to be applied. Has to happen *before* the
     * apply: it deletes the draft element, and the foreign key then nulls the rows' draftId.
     */
    public function draftApplying(int $draftElementId): void
    {
        $this->applyingDraftIds[$draftElementId] = true;

        ReleaseEntry::query()
            ->where('draftId', $draftElementId)
            ->update(['status' => ReleaseEntry::STATUS_PUBLISHED]);
    }

    public function draftApplied(int $draftElementId): void
    {
        unset($this->applyingDraftIds[$draftElementId]);
    }

    /**
     * A draft that's deleted, not applied, comes out of every release it was in.
     */
    public function draftDeleting(int $draftElementId): void
    {
        if (isset($this->applyingDraftIds[$draftElementId])) {
            return;
        }

        ReleaseEntry::query()
            ->where('draftId', $draftElementId)
            ->whereHas('release', fn ($q) => $q->where('status', '!=', ReleaseStatus::Published))
            ->delete();
    }

    /**
     * Scheduled releases whose time has come.
     *
     * @return Collection<int, Release>
     */
    public function due(): Collection
    {
        return Release::query()
            ->where('status', ReleaseStatus::Scheduled)
            ->whereNotNull('scheduledDate')
            // Stored as UTC; the query builder doesn't convert like the model does.
            ->where('scheduledDate', '<=', Query::prepareDateForDb(now()))
            ->orderBy('scheduledDate')
            ->get();
    }

    /** A saved draft by element ID, in any site, or null. */
    public function draft(int $draftElementId): ?Entry
    {
        $draft = Entry::find()
            ->id($draftElementId)
            ->drafts()
            ->provisionalDrafts(null)
            ->status(null)
            ->site('*')
            ->one();

        return $draft instanceof Entry && ! $draft->isProvisionalDraft ? $draft : null;
    }
}
