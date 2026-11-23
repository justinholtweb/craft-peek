<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Element\Events\DraftApplied;
use CraftCms\Cms\Element\Events\DraftApplying;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use Illuminate\Console\Scheduling\Schedule;
use JustinHoltWeb\Peek\Console\Commands\MoveToCraftCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesListCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesPublishCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesStatusCommand;
use JustinHoltWeb\Peek\Console\Commands\SchedulerCheckCommand;
use JustinHoltWeb\Peek\Listeners\AddEditorMetadata;
use JustinHoltWeb\Peek\Listeners\RemoveDiscardedDraft;
use JustinHoltWeb\Peek\Listeners\TrackAppliedDraft;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Override;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Peek: content staging, visual diff and coordinated releases.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const string PERMISSION_ACCESS = 'peek:accessPlugin';

    public const string PERMISSION_VIEW_DIFFS = 'peek:viewDiffs';

    public const string PERMISSION_MANAGE_RELEASES = 'peek:manageReleases';

    public const string PERMISSION_PUBLISH_RELEASES = 'peek:publishReleases';

    public const string PERMISSION_SCHEDULE_RELEASES = 'peek:scheduleReleases';

    public const string PERMISSION_DELETE_RELEASES = 'peek:deleteReleases';

    public const string PERMISSION_MANAGE_SETTINGS = 'peek:manageSettings';

    /** Unchanged from 5.x: the tables are the same. */
    public string $schemaVersion = '1.0.1';

    /**
     * Sites upgrade from the last Peek 5 release, so every 5.x migration has run and
     * `database/migrations/Install.php` can describe the final schema.
     */
    public string $minVersionRequired = '5.1.2';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    protected array $styles = [
        __DIR__.'/../resources/css/peek.css' => 'css/peek.css',
    ];

    protected array $scripts = [
        __DIR__.'/../resources/js/peek.js' => 'js/peek.js',
    ];

    protected array $commands = [
        ReleasesListCommand::class,
        ReleasesStatusCommand::class,
        ReleasesPublishCommand::class,
        SchedulerCheckCommand::class,
        MoveToCraftCommand::class,
    ];

    protected array $events = [
        DraftApplying::class => TrackAppliedDraft::class,
        DraftApplied::class => TrackAppliedDraft::class,
        ElementDeleting::class => RemoveDiscardedDraft::class,
        ElementMetadataResolving::class => AddEditorMetadata::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        // Releases remembers which drafts are mid-apply, so the listeners must share one instance.
        foreach ([Releases::class, ReleaseSystem::class, Diff::class, PendingDrafts::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());
    }

    /**
     * Peek's scheduled releases publish themselves. On a site that runs Laravel's scheduler
     * (`php artisan schedule:work`, or `schedule:run` from cron) there's nothing else to set up;
     * otherwise run `peek:scheduler:check` from cron every minute, as on 5.x.
     */
    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(SchedulerCheckCommand::class)
            ->everyMinute()
            ->withoutOverlapping(5)
            ->name('peek:scheduler:check');
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_ACCESS,
                label: t('Access Peek', category: 'peek'),
                nested: collect([
                    new Permission(
                        key: self::PERMISSION_VIEW_DIFFS,
                        label: t('View diffs', category: 'peek'),
                    ),
                    new Permission(
                        key: self::PERMISSION_MANAGE_RELEASES,
                        label: t('Manage releases', category: 'peek'),
                        info: t('Peek’s own releases, on Craft Solo and Team. Craft Pro and Enterprise use Craft’s release permissions.', category: 'peek'),
                        nested: collect([
                            new Permission(key: self::PERMISSION_PUBLISH_RELEASES, label: t('Publish releases', category: 'peek')),
                            new Permission(key: self::PERMISSION_SCHEDULE_RELEASES, label: t('Schedule releases', category: 'peek')),
                            new Permission(key: self::PERMISSION_DELETE_RELEASES, label: t('Delete releases', category: 'peek')),
                        ]),
                    ),
                ]),
            ),
            new Permission(
                key: self::PERMISSION_MANAGE_SETTINGS,
                label: t('Manage Peek settings', category: 'peek'),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();
        $user = currentUser();

        if (! $item instanceof NavItem || $user === null || ! $user->can(self::PERMISSION_ACCESS)) {
            return null;
        }

        $subnav = [
            new NavItem()->label(t('Dashboard', category: 'peek'))->href('peek'),
        ];

        $system = $this->app->make(ReleaseSystem::class);

        if ($system->usesCraft()) {
            // Craft's own Releases screen; Peek's list stays reachable for releases made before.
            if ($user->can('manageReleases') || $user->can('publishReleases')) {
                $subnav[] = new NavItem()->label(t('Releases', category: 'peek'))->href('releases');
            }

            // Releases made in Peek before (an upgrade, or a Team site that moved up), until
            // they're published or moved across.
            if ($user->can(self::PERMISSION_MANAGE_RELEASES) && $system->hasUnfinishedPeekReleases()) {
                $subnav[] = new NavItem()->label(t('Earlier releases', category: 'peek'))->href('peek/releases');
            }
        } elseif ($user->can(self::PERMISSION_MANAGE_RELEASES)) {
            $subnav[] = new NavItem()->label(t('Releases', category: 'peek'))->href('peek/releases');
        }

        if ($user->isAdmin() || $user->can(self::PERMISSION_MANAGE_SETTINGS)) {
            $subnav[] = new NavItem()->label(t('Settings', category: 'peek'))->href('settings/plugins/peek');
        }

        return $item->subnav($subnav);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $sites = [['label' => t('Primary site', category: 'peek'), 'value' => '']];

        foreach (Sites::getAllSites() as $site) {
            $sites[] = ['label' => $site->getName(), 'value' => (string) $site->id];
        }

        return Ui::make([
            Field::make(t('Stale draft threshold', category: 'peek'), Number::make('staleDraftDays')->min(1)->suffix(t('days', category: 'peek')))
                ->instructions(t('Drafts with no save in this many days are flagged on the dashboard.', category: 'peek'))
                ->required(),
            Field::make(t('Default site for releases', category: 'peek'), Choice::make('defaultSiteId')->options($sites))
                ->instructions(t('The site new releases start on. Applies to Peek’s own releases (Craft Solo and Team).', category: 'peek')),
            Field::make(t('Visual preview', category: 'peek'), Lightswitch::make('enableVisualPreview'))
                ->instructions(t('Show the live page and the draft side by side on the diff screen.', category: 'peek')),
            Field::make(t('Most drafts per release', category: 'peek'), Number::make('maxEntriesPerRelease')->min(1)->max(500))
                ->instructions(t('Applies to Peek’s own releases.', category: 'peek'))
                ->required(),
        ]);
    }
}
