<?php

namespace justinholtweb\peek;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\events\DeleteElementEvent;
use craft\events\DraftEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\services\Drafts;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\models\Settings;
use justinholtweb\peek\services\DiffService;
use justinholtweb\peek\services\DraftService;
use justinholtweb\peek\services\Releases;
use yii\base\Event;

/**
 * Peek — Content Staging & Visual Diff for Craft CMS
 *
 * @property Releases $releases
 * @property DiffService $diff
 * @property DraftService $drafts
 * @property Settings $settings
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.1';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'releases' => Releases::class,
                'diff' => DiffService::class,
                'drafts' => DraftService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerCpRoutes();
        $this->registerPermissions();
        $this->registerEventListeners();
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        if ($nav === null) {
            return null;
        }
        $nav['label'] = 'Peek';

        $nav['subnav'] = [];

        if (Craft::$app->getUser()->checkPermission('peek:accessPlugin')) {
            $nav['subnav']['dashboard'] = [
                'label' => Craft::t('peek', 'Dashboard'),
                'url' => 'peek',
            ];

            $nav['subnav']['releases'] = [
                'label' => Craft::t('peek', 'Releases'),
                'url' => 'peek/releases',
            ];
        }

        if (Craft::$app->getUser()->getIsAdmin() ||
            Craft::$app->getUser()->checkPermission('peek:manageSettings')) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('peek', 'Settings'),
                'url' => 'peek/settings',
            ];
        }

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('peek/settings/_index', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Dashboard
                $event->rules['peek'] = 'peek/dashboard/index';

                // Releases
                $event->rules['peek/releases'] = 'peek/releases/index';
                $event->rules['peek/releases/new'] = 'peek/releases/edit';
                $event->rules['peek/releases/<releaseId:\d+>'] = 'peek/releases/edit';

                // Diff
                $event->rules['peek/diff/<draftId:\d+>'] = 'peek/diff/view';
                $event->rules['peek/diff/<draftId:\d+>/preview'] = 'peek/diff/preview';

                // Settings
                $event->rules['peek/settings'] = 'peek/settings/index';
            }
        );
    }

    /** @var int[] Draft IDs currently being applied (to prevent delete handler from removing them) */
    private array $_applyingDraftIds = [];

    private function registerEventListeners(): void
    {
        // Before a draft is applied: mark it published in releases and track it
        // (must happen before apply because the FK SET NULL clears draftId during delete)
        Event::on(
            Drafts::class,
            Drafts::EVENT_BEFORE_APPLY_DRAFT,
            function(DraftEvent $event) {
                if ($event->draft) {
                    $this->_applyingDraftIds[] = $event->draft->id;
                    $this->releases->markEntryPublishedByDraftId($event->draft->id);
                }
            }
        );

        // After apply: clean up the tracking list
        Event::on(
            Drafts::class,
            Drafts::EVENT_AFTER_APPLY_DRAFT,
            function(DraftEvent $event) {
                if ($event->draft) {
                    $this->_applyingDraftIds = array_diff($this->_applyingDraftIds, [$event->draft->id]);
                }
            }
        );

        // When a draft is truly deleted (not applied), remove it from releases
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function(DeleteElementEvent $event) {
                $element = $event->element;
                if ($element instanceof Entry && $element->getIsDraft()) {
                    // Skip if this draft is being applied (not truly deleted)
                    if (in_array($element->id, $this->_applyingDraftIds)) {
                        return;
                    }
                    $this->releases->removeEntryByDraftId($element->id);
                }
            }
        );

        // Inject Peek sidebar panel on draft entries in the CP
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                Entry::class,
                Element::EVENT_DEFINE_SIDEBAR_HTML,
                function(DefineHtmlEvent $event) {
                    /** @var Entry $entry */
                    $entry = $event->sender;

                    if (!$entry->getIsDraft() || $entry->isProvisionalDraft) {
                        return;
                    }

                    $event->html .= $this->_renderPeekSidebar($entry);
                }
            );
        }
    }

    private function _renderPeekSidebar(Entry $draft): string
    {
        /** @var Entry $canonical */
        $canonical = $draft->getCanonical();
        if ($canonical->id === $draft->id) {
            return '';
        }

        $user = Craft::$app->getUser()->getIdentity();
        if ($user === null || !$user->can('peek:accessPlugin')) {
            return '';
        }

        $meta = [];

        if ($user->can('peek:viewDiffs')) {
            $changedCount = 0;
            foreach ($this->diff->diffEntry($draft, $canonical) as $diff) {
                if ($diff->hasChanges) {
                    $changedCount++;
                }
            }

            $meta[Craft::t('peek', 'Fields Changed')] = (string)$changedCount;
            $meta[Craft::t('peek', 'Diff')] = Html::a(Craft::t('peek', 'View Diff'), UrlHelper::cpUrl("peek/diff/{$draft->id}"), ['class' => 'go']);
        }

        if ($user->can('peek:manageReleases')) {
            $releases = $this->releases->getReleasesForDraft($draft->id);
            $releasesHtml = '';

            foreach ($releases as $release) {
                $releasesHtml .= Html::a(Html::encode($release->name), UrlHelper::cpUrl("peek/releases/{$release->id}"))
                    . ' <span class="status ' . $release->status->color() . '"></span><br>';
            }

            if ($releasesHtml === '') {
                $releasesHtml = '<span class="light">' . Craft::t('peek', 'Not in any release') . '</span>';
            }

            $meta[Craft::t('peek', 'Releases')] = $releasesHtml . $this->_addToReleaseHtml($draft, $releases, $user);
        }

        if ($meta === []) {
            return '';
        }

        return Html::tag('fieldset',
            Html::tag('legend', 'Peek', ['class' => 'h6']) .
            Cp::metadataHtml($meta)
        );
    }

    /**
     * A picker for the open releases this draft isn't in yet. The sidebar sits inside the entry's
     * own form, so it posts with `Craft.sendActionRequest()` rather than a form of its own.
     *
     * @param Release[] $current
     */
    private function _addToReleaseHtml(Entry $draft, array $current, User $user): string
    {
        $elements = Craft::$app->getElements();
        if (!$elements->canSave($draft, $user) || !$elements->canSaveCanonical($draft, $user)) {
            return '';
        }

        $currentIds = array_map(fn(Release $release) => $release->id, $current);
        $options = [];

        foreach ($this->releases->getAllReleases() as $release) {
            if ($this->releases->isEditable($release) && !in_array($release->id, $currentIds, true)) {
                $options[] = Html::tag('option', Html::encode($release->name), ['value' => $release->id]);
            }
        }

        if ($options === []) {
            return '';
        }

        $id = 'peek-add-' . $draft->id;
        $view = Craft::$app->getView();
        $view->registerJsWithVars(fn($id, $draftId, $added) => <<<JS
(() => {
    const wrap = document.getElementById($id);
    if (!wrap) return;
    wrap.querySelector('button').addEventListener('click', () => {
        const releaseId = wrap.querySelector('select').value;
        if (!releaseId) return;
        Craft.sendActionRequest('POST', 'peek/releases/add-entry', {data: {releaseId, draftId: $draftId}})
            .then(() => { Craft.cp.displaySuccess($added); window.location.reload(); })
            .catch(({response}) => Craft.cp.displayError(response?.data?.message));
    });
})();
JS, [$id, $draft->id, Craft::t('peek', 'Entry added to release.')]);

        return Html::tag('div',
            Html::tag('div', Html::tag('select', implode('', $options)), ['class' => 'select small']) .
            Html::button(Craft::t('peek', 'Add'), ['type' => 'button', 'class' => 'btn small']),
            ['id' => $id, 'class' => 'flex mt-xs']
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('peek', 'Peek'),
                    'permissions' => [
                        'peek:accessPlugin' => [
                            'label' => Craft::t('peek', 'Access Peek'),
                            'nested' => [
                                'peek:viewDiffs' => [
                                    'label' => Craft::t('peek', 'View diffs'),
                                ],
                                'peek:manageReleases' => [
                                    'label' => Craft::t('peek', 'Manage releases'),
                                    'nested' => [
                                        'peek:publishReleases' => [
                                            'label' => Craft::t('peek', 'Publish releases'),
                                        ],
                                        'peek:scheduleReleases' => [
                                            'label' => Craft::t('peek', 'Schedule releases'),
                                        ],
                                        'peek:deleteReleases' => [
                                            'label' => Craft::t('peek', 'Delete releases'),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'peek:manageSettings' => [
                            'label' => Craft::t('peek', 'Manage Peek settings'),
                        ],
                    ],
                ];
            }
        );
    }
}
