<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Widgets;

use CraftCms\Cms\Dashboard\Widgets\Widget;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\View\TemplateMode;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Override;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * "Drafts waiting" on Craft's dashboard: how many drafts there are, how many are stale, how many
 * releases are open, and the latest (or stalest) drafts with links to their diffs. Only drafts the
 * viewer could open, as on Peek's own dashboard.
 */
class DraftsWidget extends Widget
{
    public int $limit = 5;

    /** `recent` (most recently saved first) or `stale` (longest untouched first). */
    public string $show = 'recent';

    #[Override]
    public static function displayName(): string
    {
        return t('Drafts waiting', category: 'peek');
    }

    #[Override]
    public static function icon(): string
    {
        return 'magnifying-glass';
    }

    #[Override]
    public static function isSelectable(): bool
    {
        return (bool) currentUser()?->can(Plugin::PERMISSION_ACCESS);
    }

    #[Override]
    public function getRules(): array
    {
        return [
            'limit' => ['required', 'integer', 'min:1', 'max:20'],
            'show' => ['required', 'in:recent,stale'],
        ];
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        return Ui::make([
            Field::make(t('Show', category: 'peek'))->control(Choice::make('show')->value($this->show)->options([
                ['label' => t('Recently saved drafts', category: 'peek'), 'value' => 'recent'],
                ['label' => t('Stale drafts', category: 'peek'), 'value' => 'stale'],
            ])),
            Field::make(t('Limit', category: 'peek'))->required()->control(Number::make('limit')->value($this->limit)->min(1)->max(20)->size(2)),
        ]);
    }

    public function component(): ?string
    {
        return 'craft:html-widget';
    }

    /** @return array{html: string} */
    public function props(): array
    {
        $user = currentUser();

        if ($user === null || ! $user->can(Plugin::PERMISSION_ACCESS)) {
            return ['html' => ''];
        }

        $settings = app(Settings::class);
        $service = app(PendingDrafts::class);
        $all = $service->all(viewer: $user);
        $stale = $service->stale($settings->staleDraftDays, viewer: $user);
        $releases = app(ReleaseSystem::class);
        $canManage = $user->can(Plugin::PERMISSION_MANAGE_RELEASES) || $user->can('manageReleases');

        return ['html' => template('peek/widgets/_drafts', [
            'pendingCount' => count($all),
            'staleCount' => count($stale),
            'staleDraftDays' => $settings->staleDraftDays,
            'activeReleases' => $canManage ? $releases->activeCount() : null,
            'releasesUrl' => $releases->indexUrl(),
            'drafts' => array_map(fn (Entry $draft) => [
                'title' => (string) ($draft->title ?? "#{$draft->id}"),
                'draftName' => $draft->draftName,
                'section' => PendingDrafts::sectionName($draft),
                'editUrl' => $draft->getCpEditUrl(),
                'diffUrl' => $user->can(Plugin::PERMISSION_VIEW_DIFFS) ? cp_url("peek/diff/{$draft->id}", ['siteId' => $draft->siteId]) : null,
                'dateUpdated' => $draft->dateUpdated,
            ], array_slice($this->show === 'stale' ? $stale : $all, 0, $this->limit)),
            'dashboardUrl' => cp_url('peek'),
        ], TemplateMode::Cp)];
    }
}
