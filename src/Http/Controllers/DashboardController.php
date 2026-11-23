<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\ReleaseSystem;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Every saved draft the viewer could open, how stale they are, and how many releases are open.
 */
class DashboardController
{
    public function __invoke(PendingDrafts $drafts, ReleaseSystem $releases, Settings $settings): CpScreenResponse
    {
        $user = currentUser() ?? abort(403);

        $all = $drafts->all(viewer: $user);
        $stale = $drafts->stale($settings->staleDraftDays, viewer: $user);
        $canManage = $user->can(Plugin::PERMISSION_MANAGE_RELEASES) || $user->can('manageReleases');

        return new CpScreenResponse()
            ->title(t('Peek', category: 'peek'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('peek/dashboard/_content', [
                'drafts' => $all,
                'staleCount' => count($stale),
                'staleDraftDays' => $settings->staleDraftDays,
                'countsBySection' => $drafts->countsBySection($all),
                'activeReleases' => $canManage ? $releases->activeCount() : null,
                'releasesUrl' => $releases->indexUrl(),
                'canViewDiffs' => $user->can(Plugin::PERMISSION_VIEW_DIFFS),
                'diffBaseUrl' => cp_url('peek/diff'),
            ]);
    }
}
