<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Cp\Icons as CpIcons;
use CraftCms\Cms\Cp\IconSets;
use CraftCms\Cms\Element\Events\DraftApplied;
use CraftCms\Cms\Element\Events\DraftApplying;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Element\Events\ElementMetadataResolving;
use CraftCms\Cms\Gql\Events\GqlSchemaComponentsResolving;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\IconPicker;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Peek\Console\Commands\MoveToCraftCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesListCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesPublishCommand;
use JustinHoltWeb\Peek\Console\Commands\ReleasesStatusCommand;
use JustinHoltWeb\Peek\Console\Commands\SchedulerCheckCommand;
use JustinHoltWeb\Peek\Gql\GqlAccess;
use JustinHoltWeb\Peek\Gql\Queries\PeekQueries;
use JustinHoltWeb\Peek\Listeners\AddEditorMetadata;
use JustinHoltWeb\Peek\Listeners\RemoveDiscardedDraft;
use JustinHoltWeb\Peek\Listeners\TrackAppliedDraft;
use JustinHoltWeb\Peek\Mcp\PeekTools;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Services\Diff;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Override;
use Throwable;

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

    protected array $gqlQueries = [
        PeekQueries::class,
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

    public function boot(CapabilityRegistry $capabilities): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());

        // MCP: the tools are on Craft's own server (`php artisan craft:mcp:serve`, or HTTP).
        $capabilities->register(PeekTools::class);

        Event::listen(GqlSchemaComponentsResolving::class, function (GqlSchemaComponentsResolving $event): void {
            $event->queries[t('Peek', category: 'peek')] = [
                GqlAccess::SCOPE_DRAFTS.':read' => ['label' => t('Query drafts and their diffs (peekDrafts, peekDraftDiff). Also needs “Query for element drafts”.', category: 'peek')],
                GqlAccess::SCOPE_RELEASES.':read' => ['label' => t('Query releases (peekReleases, peekRelease)', category: 'peek')],
            ];
        });
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

        return $this->customiseNavItem($item->subnav($subnav), $this->getSettings());
    }

    /**
     * The sidebar label and icon from settings: label, then pasted SVG (sanitised every time it's
     * drawn), then an icon name. Craft's own Settings → Control Panel Navigation overrides win,
     * because Craft applies them afterwards.
     */
    public function customiseNavItem(NavItem $item, Settings $settings): NavItem
    {
        $label = trim((string) $settings->navLabel);

        if ($label !== '') {
            $item->label($label);
        }

        $svg = trim((string) $settings->navIconSvg);
        $icon = trim((string) $settings->navIcon);

        try {
            if ($svg !== '' && Settings::isSvgMarkup($svg)) {
                $sanitised = Html::svg($svg, sanitize: true, namespace: true);

                if ($sanitised !== '') {
                    return $item->iconSvg($sanitised)->icon(null);
                }
            }

            if ($icon !== '' && IconSets::parse($icon) !== null) {
                // A custom set's icon (`set:name`) is drawn server-side.
                return $item->iconSvg(CpIcons::svg($icon))->icon(null);
            }

            if ($icon !== '' && Settings::iconExists($icon)) {
                return $item->icon($icon)->iconSvg(null);
            }
        } catch (Throwable $e) {
            // A bad icon setting must never take the nav down.
            Log::warning('Peek couldn’t draw the custom nav icon: '.$e->getMessage());
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $sites = [['label' => t('Primary site', category: 'peek'), 'value' => '']];

        foreach (Sites::getAllSites() as $site) {
            $sites[] = ['label' => $site->getName(), 'value' => (string) $site->id];
        }

        return Ui::make([
            Heading::make('peek-nav', t('Control panel', category: 'peek'))
                ->description(t('How Peek appears in the control panel’s sidebar. An override set in Settings → Control Panel Navigation takes precedence.', category: 'peek')),
            Field::make(t('Sidebar label', category: 'peek'), Text::make('navLabel')->placeholder('Peek'))
                ->instructions(t('Leave empty for “Peek”.', category: 'peek')),
            Field::make(t('Sidebar icon', category: 'peek'), IconPicker::make('navIcon'))
                ->instructions(t('Leave empty for Peek’s own icon.', category: 'peek')),
            Field::make(t('Custom SVG icon', category: 'peek'), Textarea::make('navIconSvg')->rows(4)->monospace())
                ->instructions(t('Optional. Paste one `<svg>` element to use instead of the icon above. It’s sanitised every time it’s drawn; a single-colour icon using `currentColor` matches the rest of the sidebar.', category: 'peek')),

            Heading::make('peek-drafts', t('Drafts and diffs', category: 'peek')),
            Field::make(t('Stale draft threshold', category: 'peek'), Number::make('staleDraftDays')->min(1)->suffix(t('days', category: 'peek')))
                ->instructions(t('Drafts with no save in this many days are flagged on the dashboard.', category: 'peek'))
                ->required(),
            Field::make(t('Default site for releases', category: 'peek'), Choice::make('defaultSiteId')->options($sites))
                ->instructions(t('The site new releases start on. Applies to Peek’s own releases (Craft Solo and Team).', category: 'peek')),
            Field::make(t('Visual preview', category: 'peek'), Lightswitch::make('enableVisualPreview'))
                ->instructions(t('Show the live page and the draft side by side on the diff screen.', category: 'peek')),
            Field::make(t('Dashboard refresh', category: 'peek'), Number::make('dashboardRefreshSeconds')->min(0)->max(3600)->suffix(t('seconds', category: 'peek')))
                ->instructions(t('How often an open dashboard updates itself. With Laravel broadcasting set up (and Echo on the page) it updates live instead. 0 turns this off.', category: 'peek')),

            Heading::make('peek-releases', t('Releases', category: 'peek')),
            Field::make(t('Most drafts per release', category: 'peek'), Number::make('maxEntriesPerRelease')->min(1)->max(500))
                ->instructions(t('Applies to Peek’s own releases.', category: 'peek'))
                ->required(),
            Field::make(t('Tell people when a scheduled release fails', category: 'peek'), Lightswitch::make('notifyOnFailure'))
                ->instructions(t('Emails the person who scheduled it, and the addresses and Slack webhook below. Applies to Peek’s own releases; Craft notifies about its own.', category: 'peek')),
            Field::make(t('Also email', category: 'peek'), Text::make('failureEmails')->placeholder('ops@example.com, editor@example.com'))
                ->instructions(t('Comma-separated, or an environment variable like `$PEEK_FAILURE_EMAILS`.', category: 'peek')),
            Field::make(t('Slack webhook URL', category: 'peek'), Text::make('failureSlackWebhookUrl')->placeholder('$PEEK_SLACK_WEBHOOK'))
                ->instructions(t('A Slack incoming-webhook URL, or an environment variable. Keep the URL itself in `.env`.', category: 'peek')),
            Field::make(t('Publish webhook URL', category: 'peek'), Text::make('publishWebhookUrl')->placeholder('$PEEK_PUBLISH_WEBHOOK'))
                ->instructions(t('Peek POSTs a JSON summary here whenever a release publishes, Peek’s or Craft’s: for a CDN purge, a static rebuild or a deploy hook. Sent from the queue, retried on failure.', category: 'peek')),
            Field::make(t('Publish webhook secret', category: 'peek'), Text::make('publishWebhookSecret')->placeholder('$PEEK_WEBHOOK_SECRET'))
                ->instructions(t('Optional. Signs each body as `X-Peek-Signature: sha256=<HMAC>`. Use an environment variable.', category: 'peek')),
        ]);
    }
}
