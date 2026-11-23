<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Http\ViewModels\ReleaseDateGroup;
use CraftCms\Cms\Support\DateTimeHelper;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Hidden;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Contracts\CraftUser;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Plugin;
use JustinHoltWeb\Peek\Services\PendingDrafts;
use JustinHoltWeb\Peek\Services\Releases;
use JustinHoltWeb\Peek\Services\ReleaseSystem;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Peek's own releases. Routes require `peek:manageReleases`; publish, schedule and delete check
 * their own permissions here.
 *
 * On Craft Pro and Enterprise, Craft's releases are the release system, so this screen only shows
 * releases made in Peek before, to finish or move across, and links to Craft's Releases screen.
 */
class ReleasesController
{
    use RespondsWithFlash;

    public function __construct(
        private readonly Releases $releases,
        private readonly ReleaseSystem $system,
        private readonly PendingDrafts $drafts,
        private readonly Settings $settings,
    ) {}

    public function index(): CpScreenResponse
    {
        $usesCraft = $this->system->usesCraft();
        $rows = [];

        foreach ($this->releases->all() as $release) {
            $rows[] = [
                'id' => $release->id,
                'name' => ['label' => $release->name, 'url' => cp_url("peek/releases/{$release->id}")],
                'status' => ['html' => self::statusHtml($release->status)],
                'entries' => (string) $release->getAttribute('entries_count'),
                'site' => (string) Sites::getSiteById($release->siteId, true)?->getName(),
                'scheduled' => $release->scheduledDate ? ['date' => $release->scheduledDate->toIso8601String()] : '—',
                'created' => ['date' => $release->dateCreated->toIso8601String()],
                '_deleteUrl' => cp_url("peek/releases/{$release->id}"),
                '_deletable' => currentUser()?->can(Plugin::PERMISSION_DELETE_RELEASES) ?? false,
                '_deleteConfirmMessage' => t('Delete “{name}”? Its drafts stay as they are.', ['name' => $release->name], category: 'peek'),
            ];
        }

        $table = Table::make('peek-releases')
            ->columns([
                ['key' => 'name', 'label' => t('Name', category: 'peek'), 'width' => '2fr'],
                ['key' => 'status', 'label' => t('Status', category: 'peek')],
                ['key' => 'entries', 'label' => t('Entries', category: 'peek'), 'thin' => true],
                ['key' => 'site', 'label' => t('Site', category: 'peek')],
                ['key' => 'scheduled', 'label' => t('Scheduled', category: 'peek')],
                ['key' => 'created', 'label' => t('Created', category: 'peek')],
            ])
            ->rows($rows)
            ->emptyMessage($usesCraft
                ? t('No releases were made in Peek before. Releases live in Craft’s Releases screen on this edition.', category: 'peek')
                : t('No releases yet.', category: 'peek'))
            ->deletable();

        if (! $usesCraft) {
            $table->createAction(t('New release', category: 'peek'), cp_url('peek/releases/new'))->createActionInPageHeader();
        }

        $nodes = [];

        if ($usesCraft) {
            $nodes[] = Callout::make('peek-craft-releases', t('On this edition of Craft, releases are Craft’s own: create and publish them under [Releases]({url}). Releases below were made in Peek before; finish them here, or move them across with `php artisan peek:releases:move-to-craft --as=<username>`.', ['url' => cp_url('releases')], category: 'peek'));
        }

        $nodes[] = $table;

        return new CpScreenResponse()
            ->title(t('Releases', category: 'peek'))
            ->selectedSubnavItem('releases')
            ->ui(Ui::make($nodes));
    }

    public function create(): CpScreenResponse|Response
    {
        if ($this->system->usesCraft()) {
            return redirect(cp_url('releases'));
        }

        $release = new Release([
            'siteId' => $this->settings->defaultSiteId ?? Sites::getPrimarySite()->id,
            'status' => ReleaseStatus::Draft,
        ]);

        return $this->screen($release);
    }

    public function edit(int $releaseId): CpScreenResponse
    {
        return $this->screen($this->release($releaseId));
    }

    public function save(Request $request): Response
    {
        $user = $this->user();
        $releaseId = $request->integer('releaseId');

        if ($releaseId) {
            $release = $this->release($releaseId);
            abort_unless($release->status->isEditable(), 400, 'A release that is publishing or published can’t be changed.');
        } else {
            abort_if($this->system->usesCraft(), 400, 'New releases are made in Craft’s Releases screen on this edition.');
            $release = new Release(['createdBy' => $user->getCraftUserId()]);
        }

        abort_if($releaseId && $this->system->usesCraft() && $request->filled('addDraftId'), 400, 'Peek’s own releases take no new drafts on this edition.');

        $siteId = $request->integer('siteId');
        $previousStatus = $release->exists ? $release->status : null;
        $previousDate = $release->scheduledDate;

        $status = ReleaseStatus::tryFrom((string) $request->input('status', $release->status->value));
        if ($status === null || ! $status->isChoosable()) {
            throw ValidationException::withMessages(['status' => t('Choose Draft, Ready or Scheduled.', category: 'peek')]);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => t('Name cannot be blank.', category: 'peek')]);
        }

        $scheduledDate = self::postedDate($request->input('scheduledDate'));
        if ($scheduledDate !== null && $status === ReleaseStatus::Draft) {
            $status = ReleaseStatus::Scheduled;
        }

        $site = $siteId ? Sites::getSiteById($siteId, true) : null;

        $release->fill([
            'name' => mb_substr($name, 0, 255),
            'description' => $request->input('description') ?: null,
            'siteId' => $site !== null ? $site->id : Sites::getPrimarySite()->id,
            'status' => $status,
            'scheduledDate' => $scheduledDate,
        ]);

        if ($status === ReleaseStatus::Scheduled) {
            if ($scheduledDate === null) {
                throw ValidationException::withMessages(['scheduledDate' => t('A scheduled release needs a date.', category: 'peek')]);
            }

            // A scheduled release publishes itself, so scheduling is publishing later: it needs
            // the schedule permission, and whoever schedules it answers for the drafts.
            $rescheduled = $previousStatus !== ReleaseStatus::Scheduled
                || $previousDate?->getTimestamp() !== $scheduledDate->getTimestamp();

            if ($rescheduled) {
                abort_unless($user->can(Plugin::PERMISSION_SCHEDULE_RELEASES), 403, 'User not authorized to schedule releases.');

                if ($release->exists && ($denied = $this->releases->unauthorized($release, $user)) !== []) {
                    throw ValidationException::withMessages(['scheduledDate' => $denied]);
                }

                $release->scheduledBy = $user->getCraftUserId();
            }
        } else {
            $release->scheduledBy = null;
        }

        $release->save();

        if ($request->filled('addDraftId')) {
            $draft = $this->releases->draft($request->integer('addDraftId'));

            if ($draft === null) {
                throw ValidationException::withMessages(['addDraftId' => t('Draft not found.', category: 'peek')]);
            }

            if (($error = $this->releases->addDraft($release, $draft, $user)) !== null) {
                throw ValidationException::withMessages(['addDraftId' => $error]);
            }
        }

        return $this->asSuccess(t('Release saved.', category: 'peek'), redirect: cp_url("peek/releases/{$release->id}"));
    }

    public function publish(int $releaseId): Response
    {
        $user = $this->user();
        abort_unless($user->can(Plugin::PERMISSION_PUBLISH_RELEASES), 403, 'User not authorized to publish releases.');

        $release = $this->release($releaseId);
        $problems = $this->releases->publish($release, $user);

        if ($problems !== []) {
            return $this->asFailure(t('The release wasn’t published.', category: 'peek').' '.implode(' ', $problems));
        }

        return $this->asSuccess(t('Release published.', category: 'peek'), redirect: cp_url("peek/releases/{$release->id}"));
    }

    public function destroy(int $releaseId): Response
    {
        abort_unless($this->user()->can(Plugin::PERMISSION_DELETE_RELEASES), 403, 'User not authorized to delete releases.');

        $release = $this->release($releaseId);
        abort_if($release->status === ReleaseStatus::Publishing, 400, 'A release that is publishing can’t be deleted.');
        $release->delete();

        return $this->asSuccess(t('Release deleted.', category: 'peek'), redirect: cp_url('peek/releases'));
    }

    public function removeDraft(int $releaseId, int $draftId): Response
    {
        $release = $this->release($releaseId);

        if (! $this->releases->removeDraft($release, $draftId)) {
            return $this->asFailure(t('Could not remove that draft from the release.', category: 'peek'));
        }

        return $this->asSuccess(t('Draft removed from the release.', category: 'peek'), redirect: cp_url("peek/releases/{$release->id}"));
    }

    private function screen(Release $release): CpScreenResponse
    {
        $user = $this->user();
        $isNew = ! $release->exists;
        $editable = $release->status->isEditable() && ! ($isNew && $this->system->usesCraft());
        $title = $isNew ? t('New release', category: 'peek') : $release->name;

        $nodes = [];

        if ($release->status === ReleaseStatus::Published && $release->publishedDate) {
            $nodes[] = Callout::make('peek-published', t('Published on {date}.', ['date' => $release->publishedDate->setTimezone(Cms::timezone())->format('Y-m-d H:i')], category: 'peek'));
        } elseif ($release->status === ReleaseStatus::Failed) {
            $nodes[] = Callout::make('peek-failed', t('The last publish failed and was rolled back, so every draft is still in place. Fix what’s listed below and publish again.', category: 'peek'))->variant('danger');
        }

        if (! $isNew && ($problems = $release->status->isPublishable() ? $this->releases->problems($release) : []) !== [] && $release->entries()->exists()) {
            $nodes[] = Callout::make('peek-problems', implode("\n\n", $problems))->variant('warning');
        }

        $sites = [];
        foreach (Sites::getAllSites() as $site) {
            $sites[] = ['label' => $site->getName(), 'value' => $site->id];
        }

        $statuses = array_values(array_map(
            fn (ReleaseStatus $status) => ['label' => $status->label(), 'value' => $status->value],
            array_filter(ReleaseStatus::cases(), fn (ReleaseStatus $status) => $status->isChoosable() || $status === $release->status),
        ));

        $nodes = [
            ...$nodes,
            Field::make(null, Hidden::make('releaseId')),
            Field::make(t('Name', category: 'peek'), Text::make('name'))->required(),
            Field::make(t('Description', category: 'peek'), Textarea::make('description')->rows(3)),
            Field::make(t('Site', category: 'peek'), Choice::make('siteId')->options($sites))
                ->visible(Sites::isMultiSite()),
            Field::make(t('Status', category: 'peek'), Choice::make('status')->options($statuses))
                ->instructions(t('Publishing, Published and Failed are set by publishing.', category: 'peek')),
            ReleaseDateGroup::dateTimeField('scheduledDate', t('Scheduled date', category: 'peek'), min: now()->format('Y-m-d'))
                ->instructions(t('Setting a date schedules the release. It publishes itself then, as the person who scheduled it.', category: 'peek')),
        ];

        if (! $isNew) {
            $nodes[] = Heading::make('peek-entries', t('Drafts', category: 'peek'));
            $nodes[] = $this->entriesTable($release, $user, $editable);

            // On Pro and Enterprise, earlier Peek releases take no new drafts: they go into Craft's.
            if ($editable && ! $this->system->usesCraft()) {
                $addable = $this->addableDrafts($release, $user);

                if ($addable !== []) {
                    $nodes[] = Field::make(t('Add a draft', category: 'peek'), Choice::make('addDraftId')->options($addable)->placeholder(t('Choose a draft…', category: 'peek')))
                        ->instructions(t('Drafts you could publish yourself. Saving adds the chosen one.', category: 'peek'));
                }
            }
        }

        $context = new UiContext(
            values: [
                'releaseId' => $release->id,
                'name' => $release->name,
                'description' => $release->description,
                'siteId' => $release->siteId,
                'status' => $release->status->value,
                'scheduledDate' => ReleaseDateGroup::dateTimeValue($release->scheduledDate),
                'addDraftId' => null,
            ],
            mode: $editable ? ControlMode::Editable : ControlMode::ReadOnly,
        );

        $response = new CpScreenResponse()
            ->title($title)
            ->selectedSubnavItem('releases')
            ->crumbs([
                new ActionItem()->label(t('Releases', category: 'peek'))->href(cp_url('peek/releases')),
                new ActionItem()->label($title),
            ])
            ->ui(Ui::make($nodes), $context, $editable ? ['submit' => ['method' => 'post', 'url' => cp_url('peek/releases')]] : []);

        if (! $isNew) {
            if ($release->status->isPublishable() && $release->status !== ReleaseStatus::Publishing && $user->can(Plugin::PERMISSION_PUBLISH_RELEASES)) {
                $response->addAltAction(t('Publish now', category: 'peek'), [
                    'action' => [
                        'type' => 'http',
                        'method' => 'POST',
                        'url' => cp_url("peek/releases/{$release->id}/publish"),
                        'confirm' => t('Publish every draft in “{name}” now?', ['name' => $release->name], category: 'peek'),
                    ],
                ]);
            }

            if ($release->status !== ReleaseStatus::Publishing && $user->can(Plugin::PERMISSION_DELETE_RELEASES)) {
                $response->addAltAction(t('Delete', category: 'peek'), [
                    'variant' => 'danger',
                    'action' => [
                        'type' => 'http',
                        'method' => 'DELETE',
                        'url' => cp_url("peek/releases/{$release->id}"),
                        'confirm' => t('Delete “{name}”? Its drafts stay as they are.', ['name' => $release->name], category: 'peek'),
                    ],
                ]);
            }
        }

        return $response;
    }

    /**
     * The release's drafts. A release is shared, but not every draft in it is everyone's to read:
     * one the viewer can't open is listed by number only.
     */
    private function entriesTable(Release $release, CraftUser $user, bool $editable): Table
    {
        $rows = [];

        foreach ($release->entries()->get() as $entry) {
            $draft = $entry->draftId ? $this->releases->draft($entry->draftId) : null;
            $canonical = Entry::find()->id($entry->canonicalId)->status(null)->site('*')->one();
            $subject = $draft ?? $canonical;
            $canView = $subject instanceof Entry && $user->can('view', $subject);

            $row = [
                'id' => $entry->draftId ?? 'c'.$entry->canonicalId,
                'title' => $canView
                    ? ['label' => (string) ($subject->title ?? "#{$subject->id}"), 'url' => $subject->getCpEditUrl()]
                    : t('Entry #{id}', ['id' => $entry->canonicalId], category: 'peek'),
                'section' => $canView ? (PendingDrafts::sectionName($subject) ?? '—') : '—',
                'status' => ['html' => self::entryStatusHtml($entry->status)],
                'diff' => $canView && $draft !== null && $user->can(Plugin::PERMISSION_VIEW_DIFFS)
                    ? ['label' => t('Diff', category: 'peek'), 'url' => cp_url("peek/diff/{$draft->id}", ['siteId' => $draft->siteId])]
                    : '',
                '_deletable' => $editable && $entry->draftId !== null,
            ];

            if ($entry->draftId !== null) {
                $row['_deleteUrl'] = cp_url("peek/releases/{$release->id}/drafts/{$entry->draftId}");
                $row['_deleteLabel'] = t('Remove from release', category: 'peek');
                $row['_deleteConfirmMessage'] = t('Remove this draft from the release? The draft itself stays.', category: 'peek');
            }

            $rows[] = $row;
        }

        return Table::make('peek-release-drafts')
            ->columns([
                ['key' => 'title', 'label' => t('Entry', category: 'peek'), 'width' => '2fr'],
                ['key' => 'section', 'label' => t('Section', category: 'peek')],
                ['key' => 'status', 'label' => t('Status', category: 'peek')],
                ['key' => 'diff', 'label' => t('Diff', category: 'peek'), 'thin' => true],
            ])
            ->rows($rows)
            ->emptyMessage(t('No drafts in this release yet.', category: 'peek'))
            ->deletable();
    }

    /**
     * Drafts the user could add: saved drafts they could apply in Craft's own editor, not
     * already in the release.
     *
     * @return list<array{label: string, value: int}>
     */
    private function addableDrafts(Release $release, CraftUser $user): array
    {
        $in = $release->entries()->whereNotNull('draftId')->pluck('draftId')->map(fn ($id) => (int) $id)->all();
        $options = [];

        foreach ($this->drafts->all(viewer: $user) as $draft) {
            if (in_array((int) $draft->id, $in, true) || ! $this->releases->canApply($draft, $user)) {
                continue;
            }

            $options[] = [
                'label' => sprintf('%s — %s (%s)', $draft->title ?? "#{$draft->id}", $draft->draftName ?? t('Draft', category: 'peek'), PendingDrafts::sectionName($draft) ?? '—'),
                'value' => (int) $draft->id,
            ];
        }

        return $options;
    }

    private function release(int $releaseId): Release
    {
        return $this->releases->find($releaseId) ?? abort(404, 'Release not found.');
    }

    private function user(): CraftUser
    {
        return currentUser() ?? abort(403);
    }

    private static function postedDate(mixed $value): ?DateTimeInterface
    {
        if (! is_array($value) || ($value['date'] ?? '') === '') {
            return null;
        }

        return DateTimeHelper::toDateTime([
            'datetime' => trim(sprintf('%s %s', $value['date'], $value['time'] ?? '00:00')),
            'timezone' => Cms::timezone(),
        ]) ?: null;
    }

    public static function statusHtml(ReleaseStatus $status): string
    {
        return sprintf('<span class="peek-status peek-status--%s">%s</span>', $status->color(), Html::encode($status->label()));
    }

    private static function entryStatusHtml(string $status): string
    {
        $color = match ($status) {
            'published' => 'green',
            'failed' => 'red',
            default => 'gray',
        };

        $label = match ($status) {
            'published' => t('Published', category: 'peek'),
            'failed' => t('Failed', category: 'peek'),
            default => t('Pending', category: 'peek'),
        };

        return sprintf('<span class="peek-status peek-status--%s">%s</span>', $color, Html::encode($label));
    }
}
