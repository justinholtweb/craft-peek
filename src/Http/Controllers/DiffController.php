<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\Peek\Data\FieldDiff;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\Diff;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * The diff screen: Field Diff (word-level, side by side) and Visual Preview (live page and draft).
 *
 * The route requires `peek:viewDiffs`. A diff is the draft's whole content, so it also needs
 * what Craft's editor needs: the right to view the draft and its entry, and (on a multi-site
 * install) to edit the draft's site. Provisional drafts (someone's unsaved edits) are never shown.
 */
class DiffController
{
    public function __construct(
        private readonly Diff $diff,
        private readonly Settings $settings,
    ) {}

    public function show(Request $request, int $draftId): CpScreenResponse
    {
        [$draft, $canonical] = $this->viewableDraft($request, $draftId);

        $diffs = $this->diff->diffEntry($draft, $canonical);
        // Each draft URL mints a preview token, so don't make them for a tab that isn't shown.
        $previewUrls = $this->settings->enableVisualPreview
            ? $this->diff->previewUrls($draft, $canonical)
            : ['canonical' => null, 'draft' => null];

        $changed = count(array_filter($diffs, fn (FieldDiff $d) => $d->hasChanges));

        return new CpScreenResponse()
            ->title(t('Diff: {title}', ['title' => (string) $draft->title], category: 'peek'))
            ->selectedSubnavItem('dashboard')
            ->crumbs([
                new ActionItem()->label(t('Peek', category: 'peek'))->href(cp_url('peek')),
                new ActionItem()->label((string) $draft->title),
            ])
            ->contentTemplate('peek/diff/_content', [
                'draft' => $draft,
                'canonical' => $canonical,
                'diffs' => $diffs,
                'changedCount' => $changed,
                'previewUrls' => $previewUrls,
                // Craft 6's own Compare screen shows each field's preview, side by side.
                'compareUrl' => cp_url("compare/{$draft->id}", ['site' => $draft->getSite()->handle]),
            ]);
    }

    public function preview(Request $request, int $draftId): CpScreenResponse
    {
        abort_unless($this->settings->enableVisualPreview, 404, 'Visual preview is turned off.');

        [$draft, $canonical] = $this->viewableDraft($request, $draftId);

        return new CpScreenResponse()
            ->title(t('Preview: {title}', ['title' => (string) $draft->title], category: 'peek'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('peek/diff/_preview', [
                'draft' => $draft,
                'previewUrls' => $this->diff->previewUrls($draft, $canonical),
            ]);
    }

    /**
     * @return array{0: Entry, 1: Entry}
     */
    private function viewableDraft(Request $request, int $draftId): array
    {
        // The site the draft was opened in, so the sidebar's count and this screen agree.
        $siteId = $request->integer('siteId') ?: null;
        if ($siteId !== null && Sites::getSiteById($siteId, true) === null) {
            $siteId = null;
        }

        $draft = Entry::find()
            ->id($draftId)
            ->siteId($siteId)
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->one();

        abort_unless($draft instanceof Entry, 404, 'Draft not found.');

        $canonical = $draft->getCanonical();
        abort_if(! $canonical instanceof Entry || $canonical->id === $draft->id, 404, 'This draft has no entry to compare with.');

        $user = currentUser() ?? abort(403);

        abort_unless($user->can('view', $draft) && $user->can('view', $canonical), 403, 'You can’t view this draft.');

        // view doesn't check site access; Craft's editor does, on multi-site installs (5.1.2).
        abort_if(Sites::isMultiSite() && ! $user->can('editSite:'.$draft->getSite()->uid), 403, 'You can’t view this draft.');

        return [$draft, $canonical];
    }
}
