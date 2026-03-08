<?php

namespace justinholtweb\peek\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReleasesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('peek:manageReleases');

        return true;
    }

    public function actionIndex(): Response
    {
        $releases = Plugin::getInstance()->releases->getAllReleases();

        return $this->renderTemplate('peek/releases/_index', [
            'releases' => $releases,
        ]);
    }

    public function actionEdit(?int $releaseId = null): Response
    {
        $releasesService = Plugin::getInstance()->releases;

        if ($releaseId) {
            $release = $releasesService->getReleaseById($releaseId);
            if (!$release) {
                throw new NotFoundHttpException('Release not found.');
            }
            $title = $release->name;
        } else {
            $release = new Release();
            $release->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $release->createdBy = Craft::$app->getUser()->getId();
            $title = Craft::t('peek', 'New Release');
        }

        // Resolve entry details for display
        $resolvedEntries = [];
        if ($releaseId) {
            foreach ($release->getEntries() as $re) {
                $canonical = Entry::find()->id($re->canonicalId)->status(null)->one();
                $draft = $re->draftId
                    ? Entry::find()->id($re->draftId)->drafts(true)->status(null)->one()
                    : null;

                $resolvedEntries[] = [
                    'releaseEntry' => $re,
                    'canonical' => $canonical,
                    'draft' => $draft,
                    'title' => $draft->title ?? $canonical->title ?? "Entry #{$re->canonicalId}",
                    'section' => $canonical?->getSection()?->name ?? '—',
                ];
            }
        }

        return $this->renderTemplate('peek/releases/_edit', [
            'release' => $release,
            'title' => $title,
            'resolvedEntries' => $resolvedEntries,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releasesService = Plugin::getInstance()->releases;

        $releaseId = $request->getBodyParam('releaseId');

        if ($releaseId) {
            $release = $releasesService->getReleaseById($releaseId);
            if (!$release) {
                throw new NotFoundHttpException('Release not found.');
            }
        } else {
            $release = new Release();
            $release->createdBy = Craft::$app->getUser()->getId();
        }

        $release->siteId = $request->getBodyParam('siteId', Craft::$app->getSites()->getCurrentSite()->id);
        $release->name = $request->getBodyParam('name');
        $release->description = $request->getBodyParam('description');

        $statusValue = $request->getBodyParam('status');
        if ($statusValue) {
            $release->status = ReleaseStatus::from($statusValue);
        }

        $scheduledDate = $request->getBodyParam('scheduledDate');
        if ($scheduledDate) {
            $release->scheduledDate = new \DateTime($scheduledDate);
            if ($release->status === ReleaseStatus::Draft) {
                $release->status = ReleaseStatus::Scheduled;
            }
        }

        if (!$releasesService->saveRelease($release)) {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not save release.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'release' => $release,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release saved.'));

        return $this->redirectToPostedUrl($release);
    }

    public function actionPublish(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('peek:publishReleases');

        $releaseId = Craft::$app->getRequest()->getRequiredBodyParam('releaseId');
        $releasesService = Plugin::getInstance()->releases;

        $release = $releasesService->getReleaseById($releaseId);
        if (!$release) {
            throw new NotFoundHttpException('Release not found.');
        }

        if ($releasesService->publishRelease($release)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release published successfully.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Failed to publish release.'));
        }

        return $this->redirect("peek/releases/{$release->id}");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('peek:deleteReleases');

        $releaseId = Craft::$app->getRequest()->getRequiredBodyParam('releaseId');

        if (Plugin::getInstance()->releases->deleteRelease($releaseId)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not delete release.'));
        }

        return $this->redirect('peek/releases');
    }

    public function actionAddEntry(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releaseId = $request->getRequiredBodyParam('releaseId');
        $canonicalId = $request->getRequiredBodyParam('canonicalId');
        $draftId = $request->getRequiredBodyParam('draftId');

        if (Plugin::getInstance()->releases->addEntryToRelease($releaseId, $canonicalId, $draftId)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Entry added to release.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not add entry to release.'));
        }

        return $this->redirect("peek/releases/{$releaseId}");
    }

    public function actionRemoveEntry(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releaseId = $request->getRequiredBodyParam('releaseId');
        $draftId = $request->getRequiredBodyParam('draftId');

        if (Plugin::getInstance()->releases->removeEntryFromRelease($releaseId, $draftId)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Entry removed from release.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not remove entry from release.'));
        }

        return $this->redirect("peek/releases/{$releaseId}");
    }
}
