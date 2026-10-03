<?php

namespace justinholtweb\peek\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\peek\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DiffController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('peek:viewDiffs');

        return true;
    }

    public function actionView(int $draftId): Response
    {
        [$draft, $canonical] = $this->viewableDraft($draftId);

        $diffService = Plugin::getInstance()->diff;
        $diffs = $diffService->diffEntry($draft, $canonical);
        $previewUrls = $diffService->getPreviewUrls($draft, $canonical);

        $changedCount = count(array_filter($diffs, fn($d) => $d->hasChanges));

        return $this->renderTemplate('peek/diff/_view', [
            'draft' => $draft,
            'canonical' => $canonical,
            'diffs' => $diffs,
            'previewUrls' => $previewUrls,
            'changedCount' => $changedCount,
        ]);
    }

    public function actionPreview(int $draftId): Response
    {
        [$draft, $canonical] = $this->viewableDraft($draftId);

        $previewUrls = Plugin::getInstance()->diff->getPreviewUrls($draft, $canonical);

        return $this->renderTemplate('peek/diff/_preview', [
            'draft' => $draft,
            'canonical' => $canonical,
            'previewUrls' => $previewUrls,
        ]);
    }

    /**
     * The draft and its entry, if the user may read both. A diff is the draft's whole content,
     * so it needs what Craft's editor needs — before 5.0.4 the diff permission alone was enough
     * to read any draft on the site by ID, including other people's in sections the user can't
     * see. Provisional drafts (someone's unsaved edits) are never shown.
     *
     * @return array{0: Entry, 1: Entry}
     */
    private function viewableDraft(int $draftId): array
    {
        $draft = Entry::find()
            ->id($draftId)
            ->drafts(true)
            ->provisionalDrafts(false)
            ->status(null)
            ->one();

        if (!$draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        /** @var Entry|null $canonical */
        $canonical = $draft->getCanonical();
        if (!$canonical || $canonical->id === $draft->id) {
            throw new NotFoundHttpException('Canonical entry not found.');
        }

        $elements = Craft::$app->getElements();
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !$elements->canView($draft, $user) || !$elements->canView($canonical, $user)) {
            throw new ForbiddenHttpException('You can’t view this draft.');
        }

        return [$draft, $canonical];
    }
}
