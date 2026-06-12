<?php

namespace justinholtweb\peek\controllers;

use craft\web\Controller;
use justinholtweb\peek\Plugin;
use yii\web\Response;

class DashboardController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('peek:accessPlugin');

        return true;
    }

    public function actionIndex(): Response
    {
        $settings = Plugin::getInstance()->getSettings();
        $draftService = Plugin::getInstance()->drafts;
        $releasesService = Plugin::getInstance()->releases;

        $drafts = $draftService->getAllPendingDrafts();
        $staleDrafts = $draftService->getStaleDrafts($settings->staleDraftDays);
        $draftCountsBySection = $draftService->getDraftCountsBySection();
        $releases = $releasesService->getAllReleases();

        $activeReleases = array_filter($releases, fn($r) => !in_array($r->status->value, ['published', 'failed']));

        return $this->renderTemplate('peek/dashboard/_index', [
            'drafts' => $drafts,
            'staleDrafts' => $staleDrafts,
            'draftCountsBySection' => $draftCountsBySection,
            'activeReleases' => $activeReleases,
            'settings' => $settings,
        ]);
    }
}
