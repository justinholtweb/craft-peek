<?php

namespace justinholtweb\peek\controllers;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ReleasesController extends Controller
{
    /**
     * Statuses a person can choose. Publishing, Published and Failed are only ever set by the
     * publish itself.
     */
    private const EDITABLE_STATUSES = [ReleaseStatus::Draft, ReleaseStatus::Ready, ReleaseStatus::Scheduled];

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

    public function actionEdit(?int $releaseId = null, ?Release $release = null): Response
    {
        $releasesService = Plugin::getInstance()->releases;
        $user = $this->signedInUser();

        if ($release === null && $releaseId) {
            $release = $releasesService->getReleaseById($releaseId);
            if (!$release) {
                throw new NotFoundHttpException('Release not found.');
            }
        } elseif ($release === null) {
            $release = new Release();
            $release->siteId = Craft::$app->getSites()->getCurrentSite()->id;
            $release->createdBy = $user->id;
        }

        $title = $release->id ? $release->name : Craft::t('peek', 'New Release');
        $elements = Craft::$app->getElements();

        // Resolve entry details for display. A release is shared, but the drafts in it are not
        // all everyone's to read: one the viewer can't see is listed by number only.
        $resolvedEntries = [];
        $inRelease = [];
        if ($release->id) {
            foreach ($releasesService->getReleaseById($release->id)?->getEntries() ?? [] as $re) {
                $canonical = Entry::find()->id($re->canonicalId)->status(null)->one();
                $draft = $re->draftId
                    ? Entry::find()->id($re->draftId)->drafts(true)->provisionalDrafts(null)->status(null)->one()
                    : null;

                $canView = ($draft ?? $canonical) !== null && $elements->canView($draft ?? $canonical, $user);

                if ($re->draftId) {
                    $inRelease[] = $re->draftId;
                }

                $resolvedEntries[] = [
                    'releaseEntry' => $re,
                    'canonical' => $canView ? $canonical : null,
                    'draft' => $canView ? $draft : null,
                    'canView' => $canView,
                    'title' => $canView
                        ? ($draft->title ?? $canonical->title ?? "Entry #{$re->canonicalId}")
                        : Craft::t('peek', 'Entry #{id}', ['id' => $re->canonicalId]),
                    'section' => $canView ? $this->sectionName($canonical) : '—',
                ];
            }
        }

        // Drafts this user could add: ones they could publish in Craft's own editor.
        $addableDrafts = [];
        if ($release->id && $releasesService->isEditable($release)) {
            foreach (Plugin::getInstance()->drafts->getAllPendingDrafts() as $draft) {
                if (!in_array($draft->id, $inRelease, true)
                    && $elements->canSave($draft, $user)
                    && $elements->canSaveCanonical($draft, $user)
                ) {
                    $addableDrafts[] = $draft;
                }
            }
        }

        return $this->renderTemplate('peek/releases/_edit', [
            'release' => $release,
            'title' => $title,
            'resolvedEntries' => $resolvedEntries,
            'addableDrafts' => $addableDrafts,
            'editable' => $releasesService->isEditable($release),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releasesService = Plugin::getInstance()->releases;
        $user = $this->signedInUser();

        $releaseId = $request->getBodyParam('releaseId');

        if ($releaseId) {
            $release = $releasesService->getReleaseById((int)$releaseId);
            if (!$release) {
                throw new NotFoundHttpException('Release not found.');
            }
            if (!$releasesService->isEditable($release)) {
                throw new BadRequestHttpException('A release that is publishing or published can’t be changed.');
            }
        } else {
            $release = new Release();
            $release->createdBy = $user->id;
        }

        $siteId = (int)$request->getBodyParam('siteId');
        $release->siteId = Craft::$app->getSites()->getSiteById($siteId)->id ?? Craft::$app->getSites()->getCurrentSite()->id;
        $release->name = $request->getBodyParam('name');
        $release->description = $request->getBodyParam('description');

        $previousStatus = $release->status;
        $previousDate = $release->scheduledDate;

        $statusValue = (string)$request->getBodyParam('status', '');
        if ($statusValue !== '') {
            $status = ReleaseStatus::tryFrom($statusValue);
            if ($status === null || !in_array($status, self::EDITABLE_STATUSES, true)) {
                throw new BadRequestHttpException("Invalid release status: $statusValue");
            }
            $release->status = $status;
        }

        // Craft's date-time field posts {date, time, timezone}; new \DateTime() threw on it.
        $release->scheduledDate = DateTimeHelper::toDateTime($request->getBodyParam('scheduledDate')) ?: null;
        if ($release->scheduledDate && $release->status === ReleaseStatus::Draft) {
            $release->status = ReleaseStatus::Scheduled;
        }

        if ($release->status === ReleaseStatus::Scheduled) {
            if (!$release->scheduledDate) {
                $release->addError('scheduledDate', Craft::t('peek', 'A scheduled release needs a date.'));

                return $this->failSave($release);
            }

            // A scheduled release publishes itself, so scheduling is publishing later: it needs
            // the schedule permission, and the scheduler takes responsibility for the drafts.
            $rescheduled = $previousStatus !== ReleaseStatus::Scheduled
                || $previousDate?->getTimestamp() !== $release->scheduledDate->getTimestamp();

            if ($rescheduled) {
                $this->requirePermission('peek:scheduleReleases');

                $denied = $releasesService->unauthorizedEntries($release, $user);
                if ($denied !== []) {
                    $release->addErrors(['entries' => $denied]);

                    return $this->failSave($release);
                }

                $release->scheduledBy = $user->id;
            }
        } else {
            $release->scheduledBy = null;
        }

        if (!$releasesService->saveRelease($release)) {
            return $this->failSave($release);
        }

        Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release saved.'));

        return $this->redirectToPostedUrl($release);
    }

    public function actionPublish(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('peek:publishReleases');

        $releaseId = (int)Craft::$app->getRequest()->getRequiredBodyParam('releaseId');
        $releasesService = Plugin::getInstance()->releases;

        $release = $releasesService->getReleaseById($releaseId);
        if (!$release) {
            throw new NotFoundHttpException('Release not found.');
        }

        // Every draft is checked against the publisher's own rights, as Craft's editor would.
        if ($releasesService->publishRelease($release, $this->signedInUser())) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release published successfully.'));
        } else {
            $reasons = $release->getErrors('entries');
            Craft::$app->getSession()->setError($reasons !== []
                ? Craft::t('peek', 'The release wasn’t published.') . ' ' . implode(' ', $reasons)
                : Craft::t('peek', 'Failed to publish release.'));
        }

        return $this->redirect("peek/releases/{$release->id}");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('peek:deleteReleases');

        $releaseId = (int)Craft::$app->getRequest()->getRequiredBodyParam('releaseId');

        if (Plugin::getInstance()->releases->deleteRelease($releaseId)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Release deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not delete release.'));
        }

        return $this->redirect('peek/releases');
    }

    /**
     * Add a draft to a release. Only `releaseId` and the draft are taken from the request; the
     * entry is the draft's own canonical, and the draft must be one the user could publish in
     * Craft's editor.
     */
    public function actionAddEntry(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releaseId = (int)$request->getRequiredBodyParam('releaseId');
        // `addDraftId` is the release screen's picker; `draftId` the entry sidebar and the API.
        $draftId = (int)($request->getBodyParam('addDraftId') ?: $request->getRequiredBodyParam('draftId'));

        $draft = Entry::find()->id($draftId)->drafts(true)->provisionalDrafts(null)->status(null)->one();
        if ($draft === null || $draft->isProvisionalDraft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        $elements = Craft::$app->getElements();
        $user = $this->signedInUser();
        if (!$elements->canSave($draft, $user) || !$elements->canSaveCanonical($draft, $user)) {
            throw new ForbiddenHttpException('You can’t publish this draft, so you can’t add it to a release.');
        }

        if (Plugin::getInstance()->releases->addEntryToRelease($releaseId, $draft->getCanonicalId(), $draft->id)) {
            return $this->asSuccess(Craft::t('peek', 'Entry added to release.'), redirect: "peek/releases/{$releaseId}");
        }

        return $this->asFailure(Craft::t('peek', 'Could not add entry to release.'));
    }

    public function actionRemoveEntry(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $releaseId = (int)$request->getRequiredBodyParam('releaseId');
        $draftId = (int)$request->getRequiredBodyParam('draftId');

        if (Plugin::getInstance()->releases->removeEntryFromRelease($releaseId, $draftId)) {
            Craft::$app->getSession()->setNotice(Craft::t('peek', 'Entry removed from release.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not remove entry from release.'));
        }

        return $this->redirect("peek/releases/{$releaseId}");
    }

    private function sectionName(?Entry $entry): string
    {
        try {
            return $entry?->getSection()->name ?? '—';
        } catch (\yii\base\InvalidConfigException) {
            return '—';
        }
    }

    private function failSave(Release $release): null
    {
        $errors = $release->getErrors('entries') ?: $release->getErrors('scheduledDate');
        Craft::$app->getSession()->setError(Craft::t('peek', 'Could not save release.') . ($errors !== [] ? ' ' . implode(' ', $errors) : ''));

        Craft::$app->getUrlManager()->setRouteParams([
            'release' => $release,
        ]);

        return null;
    }

    private function signedInUser(): User
    {
        /** @var User $user */
        $user = Craft::$app->getUser()->getIdentity();

        return $user;
    }
}
