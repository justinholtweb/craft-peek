<?php

namespace justinholtweb\peek\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use craft\helpers\Json;
use justinholtweb\peek\enums\ReleaseStatus;
use justinholtweb\peek\models\Release;
use justinholtweb\peek\Plugin;
use yii\console\ExitCode;

/**
 * Lists, inspects and publishes releases from the command line, for deploy pipelines.
 *
 * The console has no signed-in user, so `publish` runs as the system and applies every draft in
 * the release without a permission check — anyone who can run `php craft` can already change
 * anything on the site. Pass `--as=<username or email>` to hold the publish to one user's rights
 * instead, exactly as the control panel's Publish button would.
 */
class ReleasesController extends Controller
{
    public $defaultAction = 'list';

    /**
     * @var bool Print JSON instead of a table, for scripts.
     */
    public bool $json = false;

    /**
     * @var string|null Only releases in this status (draft, ready, scheduled, publishing, published, failed).
     */
    public ?string $status = null;

    /**
     * @var string|null Only releases for this site handle.
     */
    public ?string $site = null;

    /**
     * @var string|null Publish as this user (username or email), with their permissions.
     */
    public ?string $as = null;

    /**
     * @var bool Check that the release could be published, without publishing it.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'list':
                array_push($options, 'json', 'status', 'site');
                break;
            case 'status':
                $options[] = 'json';
                break;
            case 'publish':
                array_push($options, 'json', 'as', 'dryRun');
                break;
        }

        return $options;
    }

    public function optionAliases(): array
    {
        return parent::optionAliases() + ['n' => 'dryRun'];
    }

    /**
     * Lists releases, newest first.
     */
    public function actionList(): int
    {
        $siteId = null;

        if ($this->site !== null) {
            $site = Craft::$app->getSites()->getSiteByHandle($this->site, true);
            if ($site === null) {
                $this->stderr("No site with the handle “{$this->site}”.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $siteId = $site->id;
        }

        $status = null;
        if ($this->status !== null) {
            $status = ReleaseStatus::tryFrom($this->status);
            if ($status === null) {
                $this->stderr("Unknown status “{$this->status}”. Use one of: " . implode(', ', array_column(ReleaseStatus::cases(), 'value')) . ".\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
        }

        $releases = array_values(array_filter(
            Plugin::getInstance()->releases->getAllReleases($siteId),
            fn(Release $release) => $status === null || $release->status === $status,
        ));

        if ($this->json) {
            $this->stdout(Json::encode(array_map(fn(Release $r) => $this->summary($r), $releases), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            return ExitCode::OK;
        }

        if ($releases === []) {
            $this->stdout("No releases.\n");
            return ExitCode::OK;
        }

        $this->table(
            ['ID', 'Name', 'Site', 'Status', 'Entries', 'Scheduled (UTC)', 'Published (UTC)'],
            array_map(fn(Release $r) => [
                (string)$r->id,
                (string)$r->name,
                $this->siteHandle($r),
                $r->status->value,
                (string)$r->getEntryCount(),
                $this->utc($r->scheduledDate) ?? '—',
                $this->utc($r->publishedDate) ?? '—',
            ], $releases),
        );

        return ExitCode::OK;
    }

    /**
     * Shows one release, its entries, and anything that would stop it publishing.
     *
     * Exits 0 for a release that is fine, 1 for one that failed or can't be published as it stands.
     */
    public function actionStatus(int $id): int
    {
        $releases = Plugin::getInstance()->releases;
        $release = $releases->getReleaseById($id);

        if ($release === null) {
            $this->stderr("Release #$id not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        // A published release's drafts are gone by design; only a pending one has problems to report.
        $problems = $release->status === ReleaseStatus::Published ? [] : $releases->validateRelease($release);
        $healthy = $release->status !== ReleaseStatus::Failed && $problems === [];

        if ($this->json) {
            $data = $this->summary($release) + [
                'entries' => array_map(fn($e) => [
                    'canonicalId' => $e->canonicalId,
                    'draftId' => $e->draftId,
                    'status' => $e->status,
                    'errorMessage' => $e->errorMessage,
                ], $release->getEntries()),
                'problems' => $problems,
            ];
            $this->stdout(Json::encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

            return $healthy ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Release #{$release->id}: {$release->name}\n", Console::BOLD);
        $this->stdout("  Status:     {$release->status->value}\n");
        $this->stdout('  Site:       ' . $this->siteHandle($release) . "\n");
        $this->stdout('  Scheduled:  ' . ($this->utc($release->scheduledDate) ?? '—') . " UTC\n");
        $this->stdout('  Published:  ' . ($this->utc($release->publishedDate) ?? '—') . " UTC\n");
        $this->stdout("  Entries:    {$release->getEntryCount()}\n");

        if ($release->getEntries() !== []) {
            $this->table(
                ['Entry', 'Draft', 'Status'],
                array_map(fn($e) => [(string)$e->canonicalId, $e->draftId ? (string)$e->draftId : '—', $e->status], $release->getEntries()),
            );
        }

        foreach ($problems as $problem) {
            $this->stdout("  ! $problem\n", Console::FG_YELLOW);
        }

        return $healthy ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Publishes a release now: every draft in it is applied, or none is.
     *
     * Runs as the system unless `--as` names a user. `--dry-run` reports what would stop it without
     * publishing.
     */
    public function actionPublish(int $id): int
    {
        $releases = Plugin::getInstance()->releases;
        $release = $releases->getReleaseById($id);

        if ($release === null) {
            $this->stderr("Release #$id not found.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($release->status === ReleaseStatus::Published) {
            return $this->publishResult($release, false, ['The release is already published.']);
        }

        // The scheduler sets Publishing before it queues the job; publishing it here too would race it.
        if ($release->status === ReleaseStatus::Publishing) {
            return $this->publishResult($release, false, ['The release is already being published by the queue.']);
        }

        $user = null;
        if ($this->as !== null) {
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->as);

            if ($user === null || $user->getStatus() !== User::STATUS_ACTIVE || !$user->can('peek:publishReleases')) {
                $this->stderr("“{$this->as}” isn’t an active user with the Publish releases permission.\n", Console::FG_RED);
                return ExitCode::NOPERM;
            }
        }

        $problems = $releases->validateRelease($release);
        if ($problems === [] && $user !== null) {
            $problems = $releases->unauthorizedEntries($release, $user);
        }

        if ($this->dryRun) {
            return $this->publishResult($release, $problems === [], $problems, true);
        }

        if ($problems !== []) {
            return $this->publishResult($release, false, $problems);
        }

        if ($this->interactive && !$this->json && !$this->confirm("Publish release #{$release->id} “{$release->name}” ({$release->getEntryCount()} entries) now?")) {
            $this->stdout("Not published.\n");
            return ExitCode::OK;
        }

        $published = $releases->publishRelease($release, $user);

        return $this->publishResult(
            $release,
            $published,
            $published ? [] : ($release->getErrors('entries') ?: ['Applying a draft failed, so nothing was published. Check the peek log.']),
        );
    }

    /**
     * @param string[] $problems
     */
    private function publishResult(Release $release, bool $ok, array $problems, bool $dryRun = false): int
    {
        if ($this->json) {
            $this->stdout(Json::encode($this->summary($release) + [
                'dryRun' => $dryRun,
                'published' => $ok && !$dryRun,
                'problems' => $problems,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        } elseif ($ok) {
            $this->stdout($dryRun
                ? "Release #{$release->id} can be published.\n"
                : "Published release #{$release->id}: {$release->name}\n", Console::FG_GREEN);
        } else {
            $this->stderr(($dryRun ? "Release #{$release->id} can’t be published:\n" : "Release #{$release->id} was not published:\n"), Console::FG_RED);
            foreach ($problems as $problem) {
                $this->stderr("  - $problem\n");
            }
        }

        return $ok ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    private function summary(Release $release): array
    {
        return [
            'id' => $release->id,
            'name' => $release->name,
            'site' => $this->siteHandle($release),
            'status' => $release->status->value,
            'entryCount' => $release->getEntryCount(),
            'scheduledDate' => $release->scheduledDate?->format(DATE_ATOM),
            'publishedDate' => $release->publishedDate?->format(DATE_ATOM),
        ];
    }

    private function siteHandle(Release $release): string
    {
        return $release->siteId ? (Craft::$app->getSites()->getSiteById($release->siteId, true)->handle ?? '—') : '—';
    }

    private function utc(?\DateTime $date): ?string
    {
        return $date ? (clone $date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i') : null;
    }
}
