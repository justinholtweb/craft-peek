<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Jobs;

use CraftCms\Cms\Queue\Job;
use Illuminate\Support\Facades\Http;
use JustinHoltWeb\Peek\Models\Settings;

use function CraftCms\Cms\t;

/**
 * POSTs a release's summary to the publish webhook. Queued, so a slow or failing endpoint never
 * holds up publishing; retried with backoff.
 *
 * The body is JSON. With a secret, `X-Peek-Signature: sha256=<hex HMAC-SHA256 of the raw body>`.
 */
class SendPublishWebhook extends Job
{
    public int $tries = 5;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(Settings $settings): void
    {
        $url = $settings->publishWebhook();

        if ($url === null) {
            return;
        }

        $body = (string) json_encode($this->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Peek-Webhook/1'];

        if (($secret = $settings->publishWebhookSecretValue()) !== null) {
            $headers['X-Peek-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        Http::timeout(15)->withHeaders($headers)->withBody($body, 'application/json')->post($url)->throw();
    }

    protected function defaultDescription(): string
    {
        return t('Sending Peek’s publish webhook', category: 'peek');
    }
}
