<?php

namespace justinholtweb\peek\controllers;

use craft\elements\Entry;
use craft\web\Controller;
use justinholtweb\peek\Plugin;
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
        $draft = Entry::find()
            ->id($draftId)
            ->drafts(true)
            ->status(null)
            ->one();

        if (!$draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        /** @var Entry|null $canonical */
        $canonical = $draft->getCanonical();
        if (!$canonical) {
            throw new NotFoundHttpException('Canonical entry not found.');
        }

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
        $draft = Entry::find()
            ->id($draftId)
            ->drafts(true)
            ->status(null)
            ->one();

        if (!$draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        /** @var Entry|null $canonical */
        $canonical = $draft->getCanonical();
        if (!$canonical) {
            throw new NotFoundHttpException('Canonical entry not found.');
        }

        $previewUrls = Plugin::getInstance()->diff->getPreviewUrls($draft, $canonical);

        return $this->renderTemplate('peek/diff/_preview', [
            'draft' => $draft,
            'canonical' => $canonical,
            'previewUrls' => $previewUrls,
        ]);
    }
}
