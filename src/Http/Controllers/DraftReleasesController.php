<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Http\Controllers;

use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * "Add to a release…" from a draft's editor sidebar: pick one of the open releases the draft
 * could join, in whichever release system the site uses.
 *
 * The 5.x sidebar posted with `Craft.sendActionRequest()`. Craft 6's editor is an Inertia app with
 * no such helper, and the sidebar sits inside the entry's own form, so this is a screen of its own.
 */
class DraftReleasesController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly ReleaseSystem $releases,
    ) {}

    public function show(Request $request, int $draftId): CpScreenResponse
    {
        $draft = $this->draft($request, $draftId);
        $user = currentUser() ?? abort(403);

        $options = array_map(
            fn (ReleaseSummary $release) => [
                'label' => $release->name.' ('.$release->statusLabel.')',
                'value' => $release->source.':'.$release->id,
            ],
            $this->releases->addableFor($draft, $user),
        );

        $nodes = $options === []
            ? [Callout::make('peek-none', t('There’s no open release this draft can join.', category: 'peek'))]
            : [Field::make(t('Release', category: 'peek'), Choice::make('release')->options($options))->required()];

        return new CpScreenResponse()
            ->title(t('Add “{title}” to a release', ['title' => (string) $draft->title], category: 'peek'))
            ->crumbs([
                new ActionItem()->label((string) $draft->title)->href((string) $draft->getCpEditUrl()),
                new ActionItem()->label(t('Add to a release', category: 'peek')),
            ])
            ->ui(
                Ui::make($nodes),
                new UiContext(values: ['release' => $options[0]['value'] ?? null]),
                $options === [] ? [] : ['submit' => ['method' => 'post', 'url' => cp_url("peek/drafts/{$draft->id}/releases", ['siteId' => $draft->siteId])]],
            );
    }

    public function store(Request $request, int $draftId): Response
    {
        $draft = $this->draft($request, $draftId);
        $user = currentUser() ?? abort(403);

        [$source, $id] = array_pad(explode(':', (string) $request->input('release'), 2), 2, '');

        if (! in_array($source, [ReleaseSummary::SOURCE_PEEK, ReleaseSummary::SOURCE_CRAFT], true) || ! ctype_digit($id)) {
            throw ValidationException::withMessages(['release' => t('Choose a release.', category: 'peek')]);
        }

        // Only a release this screen would have offered: that carries the release-management
        // permission and every other gate, so a hand-made POST can't get past them.
        $offered = array_filter(
            $this->releases->addableFor($draft, $user),
            fn (ReleaseSummary $release) => $release->source === $source && $release->id === (int) $id,
        );

        abort_if($offered === [], 403, 'You can’t add this draft to that release.');

        if (($error = $this->releases->addDraft($source, (int) $id, $draft, $user)) !== null) {
            throw ValidationException::withMessages(['release' => $error]);
        }

        return $this->asSuccess(t('Draft added to the release.', category: 'peek'), redirect: (string) $draft->getCpEditUrl());
    }

    private function draft(Request $request, int $draftId): Entry
    {
        $draft = Entry::find()
            ->id($draftId)
            ->siteId($request->integer('siteId') ?: null)
            ->drafts()
            ->provisionalDrafts(false)
            ->status(null)
            ->one();

        abort_unless($draft instanceof Entry, 404, 'Draft not found.');
        abort_unless(($user = currentUser()) !== null && PendingDrafts::canView($draft, $user), 403, 'You can’t view this draft.');

        return $draft;
    }
}
