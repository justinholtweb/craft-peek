<?php

namespace justinholtweb\peek\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\peek\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Match the CP nav: admins, or users granted the manage-settings permission.
        if (!Craft::$app->getUser()->getIsAdmin()) {
            $this->requirePermission('peek:manageSettings');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('peek/settings/_index', [
            'settings' => $plugin->getSettings(),
            'plugin' => $plugin,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $settings->staleDraftDays = Craft::$app->getRequest()->getBodyParam('staleDraftDays', $settings->staleDraftDays);
        $settings->enableVisualPreview = (bool)Craft::$app->getRequest()->getBodyParam('enableVisualPreview', $settings->enableVisualPreview);
        $settings->maxEntriesPerRelease = (int)Craft::$app->getRequest()->getBodyParam('maxEntriesPerRelease', $settings->maxEntriesPerRelease);

        if (!$settings->validate()) {
            Craft::$app->getSession()->setError(Craft::t('peek', 'Could not save settings.'));
            return null;
        }

        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());
        Craft::$app->getSession()->setNotice(Craft::t('peek', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
