<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\View\TemplateMode;
use Illuminate\Http\JsonResponse;
use JustinHoltWeb\Peek\Listeners\BroadcastDashboardChange;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Every saved draft the viewer could open, how stale they are, and how many releases are open.
 */
class DashboardController
{
    // GET peek (the screen) and GET peek/dashboard/fragment (its content, for live refresh).

    public function __invoke(PendingDrafts $drafts, ReleaseSystem $releases, Settings $settings): CpScreenResponse
    {
        return new CpScreenResponse()
            ->title(t('Peek', category: 'peek'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('peek/dashboard/_live', [
                'content' => template('peek/dashboard/_content', $this->variables($drafts, $releases, $settings), TemplateMode::Cp),
                'fragmentUrl' => cp_url('peek/dashboard/fragment'),
                'refreshSeconds' => $settings->dashboardRefreshSeconds,
                'live' => BroadcastDashboardChange::enabled(),
            ]);
    }

    /**
     * The dashboard's content alone, for an open dashboard to refresh itself.
     */
    public function fragment(PendingDrafts $drafts, ReleaseSystem $releases, Settings $settings): JsonResponse
    {
        return response()->json([
            'html' => template('peek/dashboard/_content', $this->variables($drafts, $releases, $settings), TemplateMode::Cp),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function variables(PendingDrafts $drafts, ReleaseSystem $releases, Settings $settings): array
    {
        $user = currentUser() ?? abort(403);

        $all = $drafts->all(viewer: $user);
        $stale = $drafts->stale($settings->staleDraftDays, viewer: $user);
        $canManage = $user->can(Plugin::PERMISSION_MANAGE_RELEASES) || $user->can('manageReleases');

        return [
            'drafts' => $all,
            'staleCount' => count($stale),
            'staleDraftDays' => $settings->staleDraftDays,
            'countsBySection' => $drafts->countsBySection($all),
            'activeReleases' => $canManage ? $releases->activeCount() : null,
            'releasesUrl' => $releases->indexUrl(),
            'canViewDiffs' => $user->can(Plugin::PERMISSION_VIEW_DIFFS),
            'diffBaseUrl' => cp_url('peek/diff'),
        ];
    }
}
