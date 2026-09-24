<?php

namespace justinholtweb\peek;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\DeleteElementEvent;
use craft\events\DraftEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\services\Drafts;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
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
    public string $schemaVersion = '1.0.0';
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
        /** @var Entry|null $canonical */
        $canonical = $draft->getCanonical();
        if (!$canonical || $canonical->id === $draft->id) {
            return '';
        }

        // Count changed fields
        $diffs = $this->diff->diffEntry($draft, $canonical);
        $changedCount = 0;
        foreach ($diffs as $diff) {
            if ($diff->hasChanges) {
                $changedCount++;
            }
        }

        // Check release membership
        $releases = $this->releases->getReleasesForDraft($draft->id);

        $cpTrigger = Craft::$app->getConfig()->getGeneral()->cpTrigger;
        $diffUrl = "/{$cpTrigger}/peek/diff/{$draft->id}";

        if (!empty($releases)) {
            $releasesHtml = '';
            foreach ($releases as $release) {
                $releaseUrl = "/{$cpTrigger}/peek/releases/{$release->id}";
                $releasesHtml .= '<a href="' . $releaseUrl . '">' . Html::encode($release->name) . '</a>';
                $releasesHtml .= ' <span class="status ' . $release->status->color() . '"></span>';
                $releasesHtml .= '<br>';
            }
        } else {
            $releasesHtml = '<span class="light">' . Craft::t('peek', 'Not in any release') . '</span>';
        }

        return Html::tag('fieldset',
            Html::tag('legend', 'Peek', ['class' => 'h6']) .
            Cp::metadataHtml([
                Craft::t('peek', 'Fields Changed') => (string)$changedCount,
                Craft::t('peek', 'Diff') => '<a href="' . $diffUrl . '" class="go">' . Craft::t('peek', 'View Diff') . '</a>',
                Craft::t('peek', 'Releases') => $releasesHtml,
            ])
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
