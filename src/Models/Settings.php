<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Models;

use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;
use DOMDocument;
use Override;
use Throwable;

use function CraftCms\Cms\t;

/**
 * Peek's settings. The 5.x keys are unchanged, so an upgraded site keeps what it had in project
 * config, and `config/craft/peek.php` overrides them as `config/peek.php` did.
 *
 * Text settings are nullable: Laravel turns a blank field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /** The largest pasted SVG icon accepted, in bytes. */
    public const int MAX_SVG_BYTES = 20000;

    /** How many days without a save before a draft counts as stale on the dashboard. */
    public int $staleDraftDays = 14;

    /** The site new releases default to. Null is the primary site. */
    public ?int $defaultSiteId = null;

    /** Show the Visual Preview tab (live page and draft side by side) on the diff screen. */
    public bool $enableVisualPreview = true;

    /** The most drafts one of Peek's own releases can hold. */
    public int $maxEntriesPerRelease = 50;

    /** The CP sidebar label. Empty uses "Peek". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: an icon name from Craft's icon picker. Empty uses Peek's own. */
    public ?string $navIcon = null;

    /** Raw SVG for the CP sidebar icon. Wins over `navIcon`. Sanitised every time it's drawn. */
    public ?string $navIconSvg = null;

    /**
     * Tell people when one of Peek's scheduled releases fails: the person who scheduled it, plus
     * `failureEmails` and `failureSlackWebhookUrl`.
     */
    public bool $notifyOnFailure = true;

    /** More addresses for failure notices, comma-separated. `$ENV_VAR` allowed. */
    public ?string $failureEmails = null;

    /** A Slack incoming-webhook URL for failure notices. `$ENV_VAR` allowed. */
    public ?string $failureSlackWebhookUrl = null;

    /**
     * POSTed a JSON summary whenever a release publishes (Peek's or Craft's): for CDN purges,
     * static rebuilds or deploy hooks. `$ENV_VAR` allowed.
     */
    public ?string $publishWebhookUrl = null;

    /** Signs webhook bodies (`X-Peek-Signature: sha256=…`, HMAC-SHA256). `$ENV_VAR` allowed. */
    public ?string $publishWebhookSecret = null;

    /** How often the dashboard refreshes itself, in seconds, when broadcasting isn't set up. 0 turns it off. */
    public int $dashboardRefreshSeconds = 60;

    public function getRules(): array
    {
        return [
            'staleDraftDays' => ['required', 'integer', 'min:1'],
            'defaultSiteId' => ['nullable', 'integer'],
            'enableVisualPreview' => ['boolean'],
            'maxEntriesPerRelease' => ['required', 'integer', 'min:1', 'max:500'],
            'navLabel' => ['nullable', 'string', 'max:64'],
            'navIcon' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9\-]+(:[a-z0-9\-]+)?$/'],
            'navIconSvg' => ['nullable', 'string', 'max:'.self::MAX_SVG_BYTES],
            'notifyOnFailure' => ['boolean'],
            'failureEmails' => ['nullable', 'string', 'max:1000'],
            'failureSlackWebhookUrl' => ['nullable', 'string', 'max:500'],
            'publishWebhookUrl' => ['nullable', 'string', 'max:500'],
            'publishWebhookSecret' => ['nullable', 'string', 'max:500'],
            'dashboardRefreshSeconds' => ['required', 'integer', 'min:0', 'max:3600'],
        ];
    }

    public function getMessages(): array
    {
        return [
            'navIcon.regex' => t('Choose an icon from the icon picker.', category: 'peek'),
        ];
    }

    /**
     * The checks a rule string can't express: the icon must exist, the SVG must be one `<svg>`,
     * URLs (once `$ENV_VAR`s are resolved) must be http(s), and email addresses must be addresses.
     */
    #[Override]
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        foreach (['navLabel', 'navIcon', 'navIconSvg', 'failureEmails', 'failureSlackWebhookUrl', 'publishWebhookUrl', 'publishWebhookSecret'] as $attribute) {
            $value = $this->$attribute;
            $this->$attribute = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        parent::validate($attributeNames, $clearErrors, $throw);
        $errors = $this->errors();

        if ($this->navIcon !== null && ! $errors->has('navIcon') && ! self::iconExists($this->navIcon)) {
            $errors->add('navIcon', t('Choose an icon from the icon picker.', category: 'peek'));
        }

        if ($this->navIconSvg !== null && ! $errors->has('navIconSvg') && ! self::isSvgMarkup($this->navIconSvg)) {
            $errors->add('navIconSvg', t('Paste a single <svg> element.', category: 'peek'));
        }

        foreach (['failureSlackWebhookUrl', 'publishWebhookUrl'] as $attribute) {
            $url = $this->$attribute;

            if ($url !== null && ! $errors->has($attribute) && ! str_starts_with($url, '$') && ! self::isHttpUrl($url)) {
                $errors->add($attribute, t('Enter an http(s) URL, or an environment variable.', category: 'peek'));
            }
        }

        if ($this->failureEmails !== null && ! $errors->has('failureEmails') && ! str_starts_with($this->failureEmails, '$')) {
            foreach (self::splitEmails($this->failureEmails) as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors->add('failureEmails', t('“{email}” isn’t an email address.', ['email' => $email], category: 'peek'));
                    break;
                }
            }
        }

        return $errors->isEmpty();
    }

    /** @return list<string> */
    public function failureEmailList(): array
    {
        return array_values(array_filter(
            self::splitEmails((string) Env::parse($this->failureEmails)),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        ));
    }

    public function slackWebhookUrl(): ?string
    {
        $url = Env::parse($this->failureSlackWebhookUrl);

        return $url !== null && self::isHttpUrl($url) ? $url : null;
    }

    public function publishWebhook(): ?string
    {
        $url = Env::parse($this->publishWebhookUrl);

        return $url !== null && self::isHttpUrl($url) ? $url : null;
    }

    public function publishWebhookSecretValue(): ?string
    {
        $secret = Env::parse($this->publishWebhookSecret);

        return $secret !== null && $secret !== '' ? $secret : null;
    }

    public static function iconExists(string $icon): bool
    {
        if (str_contains($icon, ':')) {
            // A custom icon set's `set:name`: checked when drawn.
            return true;
        }

        try {
            return is_file(Icons::resolveIconPath($icon));
        } catch (Throwable) {
            return false;
        }
    }

    /** Whether the text is one `<svg>` element (optionally after an XML declaration or comments). */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (! preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*<svg[\s>]/is', $svg) || ! preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;

        try {
            // No network, no entity expansion.
            $loaded = $doc->loadXML($svg, LIBXML_NONET);
        } catch (Throwable) {
            $loaded = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }

    private static function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    /** @return list<string> */
    private static function splitEmails(string $emails): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $emails) ?: [])));
    }
}
