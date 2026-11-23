<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Listeners;

use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Html;
use JustinHoltWeb\Peek\Data\ReleaseSummary;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Peek's rows in a draft's editor sidebar: fields changed, a link to the diff, and the releases the
 * draft is in. Craft 5's sidebar HTML hook has no Craft 6 equivalent in the Inertia editor; the
 * metadata rows are what both editors show.
 *
 * Every value is a closure, so nothing is computed unless the editor actually draws it.
 */
readonly class AddEditorMetadata
{
    public function __construct(
        private Diff $diff,
        private ReleaseSystem $releases,
    ) {}

    public function handle(ElementMetadataResolving $event): void
    {
        $draft = $event->element;

        if (! $draft instanceof Entry || ! $draft->getIsDraft() || $draft->isProvisionalDraft || $draft->getIsUnpublishedDraft()) {
            return;
        }

        $user = currentUser();

        if ($user === null || ! $user->can('peek:accessPlugin')) {
            return;
        }

        if ($user->can('peek:viewDiffs')) {
            $event->metadata[t('Fields changed', category: 'peek')] = function () use ($draft): string|false {
                // Count the saved draft, as the diff screen loads it. The editor's own element can
                // hold values the diff screen never sees (eager-loaded relations, preloaded Matrix
                // blocks), and in 5.1.1 the two counts disagreed (issue #3).
                try {
                    $saved = Entry::find()->id($draft->id)->siteId($draft->siteId)->drafts()->provisionalDrafts(false)->status(null)->one();
                    $saved = $saved instanceof Entry ? $saved : $draft;
                    $canonical = $saved->getCanonical();

                    return $canonical instanceof Entry && $canonical->id !== $saved->id
                        ? Html::encode((string) $this->diff->changedCount($saved, $canonical))
                        : false;
                } catch (Throwable) {
                    return false;
                }
            };

            $event->metadata[t('Diff', category: 'peek')] = fn (): string => Html::a(
                Html::encode(t('View diff', category: 'peek')),
                cp_url("peek/diff/{$draft->id}", ['siteId' => $draft->siteId]),
            );
        }

        if ($user->can('peek:manageReleases') || $user->can('manageReleases')) {
            $event->metadata[t('Releases', category: 'peek')] = function () use ($draft, $user): string {
                $links = array_map(
                    fn (ReleaseSummary $release): string => Html::a(Html::encode($release->name), $release->cpUrl)
                        .' <span class="light">('.Html::encode($release->statusLabel).')</span>',
                    $this->releases->forDraft($draft),
                );

                $html = $links !== [] ? implode('<br>', $links) : '<span class="light">'.Html::encode(t('Not in any release', category: 'peek')).'</span>';

                if ($this->releases->addableFor($draft, $user) !== []) {
                    $html .= '<br>'.Html::a(
                        Html::encode(t('Add to a release…', category: 'peek')),
                        cp_url("peek/drafts/{$draft->id}/releases", ['siteId' => $draft->siteId]),
                    );
                }

                return $html;
            };
        }
    }
}
