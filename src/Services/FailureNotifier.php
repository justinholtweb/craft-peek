<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Services;

use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use JustinHoltWeb\Peek\Mail\ReleaseFailedMail;
use JustinHoltWeb\Peek\Models\Release;
use JustinHoltWeb\Peek\Models\Settings;
use JustinHoltWeb\Peek\Notifications\ReleaseFailed;
use Throwable;

use function CraftCms\Cms\cp_url;

/**
 * Tells people when one of Peek's scheduled releases fails: the person who scheduled it (in the
 * CP's notifications and by email), any extra addresses, and a Slack webhook. Craft notifies about
 * its own releases.
 *
 * Never throws: a broken mailer or webhook mustn't hide the failure it's reporting.
 */
class FailureNotifier
{
    public function __construct(
        private readonly Settings $settings,
    ) {}

    public function releaseFailed(Release $release, string $error): void
    {
        if (! $this->settings->notifyOnFailure) {
            return;
        }

        $userId = $release->scheduledBy ?? $release->createdBy;
        $user = $userId ? User::find()->id($userId)->status(null)->one() : null;

        if ($user instanceof User) {
            $this->attempt(fn () => Notification::send($user, new ReleaseFailed($release, $error)));
        }

        $url = cp_url("peek/releases/{$release->id}");

        foreach ($this->settings->failureEmailList() as $email) {
            if ($user instanceof User && strcasecmp((string) $user->email, $email) === 0) {
                continue;
            }

            // A fresh mailable per recipient: to() appends.
            $this->attempt(fn () => Mail::to($email)->send(new ReleaseFailedMail($release->name, $error, $url)));
        }

        if (($slack = $this->settings->slackWebhookUrl()) !== null) {
            $this->attempt(fn () => Http::timeout(10)->post($slack, [
                'text' => sprintf(':warning: Release “%s” wasn’t published: %s <%s|Open it>', $release->name, $error, $url),
            ])->throw());
        }
    }

    private function attempt(callable $send): void
    {
        try {
            $send();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
