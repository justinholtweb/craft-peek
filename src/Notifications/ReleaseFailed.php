<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Notifications;

use CraftCms\Cms\Cp\Notifications\CpNotification;
use CraftCms\Cms\User\Contracts\CraftUser;
use JustinHoltWeb\Peek\Mail\ReleaseFailedMail;
use JustinHoltWeb\Peek\Models\Release;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * One of Peek's scheduled releases failed: in the control panel's notifications, and by email.
 */
class ReleaseFailed extends CpNotification
{
    private string $releaseName;

    private string $error;

    private string $releaseUrl;

    public function __construct(Release $release, string $error)
    {
        parent::__construct(t('The release “{name}” wasn’t published. {error}', ['name' => $release->name, 'error' => $error], category: 'peek'));

        $this->releaseName = $release->name;
        $this->error = $error;
        $this->releaseUrl = cp_url("peek/releases/{$release->id}");

        $this
            ->kind('peek-release-failed')
            ->title(t('Release didn’t publish', category: 'peek'))
            ->url($this->releaseUrl);
    }

    /** @return list<string> */
    public function via(CraftUser $notifiable): array
    {
        $channels = parent::via($notifiable);

        if ((string) $notifiable->asElement()->email !== '') {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(CraftUser $notifiable): ReleaseFailedMail
    {
        return new ReleaseFailedMail($this->releaseName, $this->error, $this->releaseUrl)
            ->to((string) $notifiable->asElement()->email);
    }
}
