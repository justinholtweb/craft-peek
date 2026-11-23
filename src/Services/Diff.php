<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Services;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Assets as AssetsField;
use CraftCms\Cms\Field\Color as ColorField;
use CraftCms\Cms\Field\Contracts\ElementContainerFieldInterface;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Contracts\RelationalFieldInterface;
use CraftCms\Cms\Field\Date as DateField;
use CraftCms\Cms\Field\Email as EmailField;
use CraftCms\Cms\Field\Lightswitch as LightswitchField;
use CraftCms\Cms\Field\Link as LinkField;
use CraftCms\Cms\Http\Routing\ActionRoute;
use CraftCms\Cms\RouteToken\RouteTokens;
use CraftCms\Cms\Support\Url;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jfcherng\Diff\DiffHelper;
use JsonSerializable;
use JustinHoltWeb\Peek\Data\FieldDiff;
use Stringable;
use Throwable;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Compares a draft with its entry, field by field, as text.
 *
 * Craft 6's own Compare screen shows each field's preview; this is the word-level text diff, which
 * shows exactly which words changed.
 */
class Diff
{
    /** How deep nested entries (Matrix in Matrix…) are spelled out before falling back to IDs. */
    private const int MAX_DEPTH = 4;

    /**
     * Set for GraphQL: a schema can't be shown elements outside its scopes, so related elements are
     * shown only as a count. Whether a field changed is still decided on the full values.
     */
    private bool $redactRelations = false;

    /** Serializing for display rather than for comparison. */
    private bool $displaying = false;

    public function __construct(
        private readonly RouteTokens $tokens,
    ) {}

    /**
     * @return list<FieldDiff>
     */
    public function diffEntry(Entry $draft, Entry $canonical, bool $redactRelations = false): array
    {
        $this->redactRelations = $redactRelations;

        try {
            return $this->diffEntryInternal($draft, $canonical);
        } finally {
            $this->redactRelations = false;
        }
    }

    /**
     * @return list<FieldDiff>
     */
    private function diffEntryInternal(Entry $draft, Entry $canonical): array
    {
        $diffs = [];

        foreach (['title' => t('Title', category: 'peek'), 'slug' => t('Slug', category: 'peek')] as $handle => $label) {
            $old = (string) ($canonical->$handle ?? '');
            $new = (string) ($draft->$handle ?? '');

            $diffs[] = new FieldDiff(
                handle: $handle,
                label: $label,
                type: 'native',
                oldValue: $old,
                newValue: $new,
                hasChanges: $old !== $new,
                diffHtml: $old !== $new ? $this->renderTextDiff($old, $new) : null,
            );
        }

        $oldEnabled = (bool) $canonical->enabled;
        $newEnabled = (bool) $draft->enabled;
        $diffs[] = new FieldDiff(
            handle: 'enabled',
            label: t('Enabled', category: 'peek'),
            type: 'native',
            oldValue: $oldEnabled,
            newValue: $newEnabled,
            hasChanges: $oldEnabled !== $newEnabled,
            diffHtml: $oldEnabled !== $newEnabled ? $this->renderBooleanDiff($oldEnabled, $newEnabled) : null,
        );

        foreach ($canonical->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            try {
                $diffs[] = $this->diffField($field, $draft, $canonical);
            } catch (Throwable $e) {
                // A third-party field we can't serialize mustn't take down the whole diff.
                Log::warning("Peek couldn't diff field \"{$field->handle}\": {$e->getMessage()}");
                $diffs[] = new FieldDiff(
                    handle: (string) $field->handle,
                    label: (string) $field->name,
                    type: $field::class,
                    oldValue: '',
                    newValue: '',
                    error: t('This field can’t be compared.', category: 'peek'),
                );
            }
        }

        return $diffs;
    }

    /**
     * How many fields differ. What the editor sidebar and the dashboard show.
     */
    public function changedCount(Entry $draft, Entry $canonical): int
    {
        return count(array_filter($this->diffEntry($draft, $canonical), fn (FieldDiff $diff) => $diff->hasChanges));
    }

    /**
     * {@see changedCount()}, cached until the draft or its entry is saved again: the editor sidebar
     * asks every time a draft is opened, and diffing a long Matrix entry isn't cheap.
     */
    public function cachedChangedCount(Entry $draft, Entry $canonical): int
    {
        $key = sprintf('peek:changed:%d:%d:%d', $draft->id, $draft->siteId, self::version((int) $canonical->id));

        return (int) Cache::remember($key, now()->addDay(), fn () => $this->changedCount($draft, $canonical));
    }

    /**
     * Bumped whenever an entry or any of its drafts is saved (see `ForgetChangeCounts`). Dates
     * alone weren't enough: two saves in the same second keep the same `dateUpdated`.
     */
    public static function version(int $canonicalId): int
    {
        return (int) Cache::get("peek:changed-version:{$canonicalId}", 0);
    }

    public static function bumpVersion(int $canonicalId): void
    {
        Cache::forever("peek:changed-version:{$canonicalId}", self::version($canonicalId) + 1);
    }

    /**
     * URLs for the Visual Preview panes.
     *
     * A draft shares its entry's URI, so its plain URL is the live page. The Draft pane gets the
     * URL Craft's own Preview button opens: a preview token that swaps the draft in, and the
     * `x-craft-preview` param that makes `request.isPreview` true and keeps caches out of the way.
     *
     * @return array{canonical: string|null, draft: string|null}
     */
    public function previewUrls(Entry $draft, Entry $canonical): array
    {
        return [
            'canonical' => $canonical->getUrl(),
            'draft' => $this->draftPreviewUrl($draft),
        ];
    }

    private function draftPreviewUrl(Entry $draft): ?string
    {
        $url = $draft->getUrl();

        if ($url === null) {
            return null;
        }

        $token = $this->tokens->createPreviewToken([
            ActionRoute::uriForSegments(['preview', 'preview'], false), [
                'elementType' => Entry::class,
                'canonicalId' => $draft->getCanonicalId(),
                'siteId' => $draft->siteId,
                'draftId' => $draft->draftId,
                'revisionId' => null,
                'userId' => currentUser()?->getCraftUserId(),
            ],
        ]);

        if ($token === false) {
            Log::warning("Peek couldn't create a preview token for draft {$draft->id}.");

            return null;
        }

        return Url::urlWithToken(
            Url::urlWithParams($url, ['x-craft-preview' => Crypt::encrypt(Str::random(10))]),
            $token,
        );
    }

    private function diffField(FieldInterface $field, Entry $draft, Entry $canonical): FieldDiff
    {
        $handle = (string) $field->handle;
        $old = $this->valueToString($field, $canonical->getFieldValue($handle), $canonical);
        $new = $this->valueToString($field, $draft->getFieldValue($handle), $draft);
        $changed = $old !== $new;

        if ($this->redactRelations) {
            $this->displaying = true;

            try {
                $old = $this->valueToString($field, $canonical->getFieldValue($handle), $canonical);
                $new = $this->valueToString($field, $draft->getFieldValue($handle), $draft);
            } finally {
                $this->displaying = false;
            }
        }

        $html = null;
        if ($changed) {
            $html = $field instanceof LightswitchField
                ? $this->renderBooleanDiff($old === 'On', $new === 'On')
                : $this->renderTextDiff($old, $new);
        }

        return new FieldDiff(
            handle: $handle,
            label: (string) $field->name,
            type: $field::class,
            oldValue: $old,
            newValue: $new,
            hasChanges: $changed,
            diffHtml: $html,
        );
    }

    /**
     * A field value as comparable text. Each field type gets the form a person would read.
     */
    public function valueToString(FieldInterface $field, mixed $value, ?ElementInterface $owner = null, int $depth = 0): string
    {
        // Matrix, Content Block and the like: compare what's in the nested entries, never their
        // IDs. A draft gets its own copy of every nested entry it touches, with a new ID, so an
        // edited-then-reverted block would otherwise read as changed (5.1.2, issue #3).
        if ($field instanceof ElementContainerFieldInterface && ! $field instanceof RelationalFieldInterface) {
            return $this->nestedEntriesToString($field, $value, $owner, $depth);
        }

        if ($value === null) {
            return '';
        }

        // Eager-loaded relations (the element open in the editor) arrive as collections.
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if ($field instanceof LightswitchField) {
            return ! empty($value) ? 'On' : 'Off';
        }

        if ($field instanceof DateField) {
            return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : $this->scalarString($value);
        }

        if ($field instanceof ColorField || $field instanceof EmailField || $field instanceof LinkField) {
            return $this->scalarString($value);
        }

        if ($field instanceof AssetsField) {
            return $this->assetsToString($value);
        }

        if ($field instanceof RelationalFieldInterface || $value instanceof ElementQueryInterface) {
            return $this->relationsToString($value);
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        // CKEditor and other rich text: compare the words, not the markup.
        if ($value instanceof Stringable) {
            return trim(strip_tags((string) $value));
        }

        // Third-party models with no string form (issue #1).
        if ($value instanceof Arrayable) {
            return $this->toJson($value->toArray());
        }

        if (is_array($value) || $value instanceof JsonSerializable || is_object($value)) {
            return $this->toJson($value);
        }

        return '';
    }

    /**
     * A container field's entries, each as `#n Type` with its title and field values indented
     * beneath. Always queried fresh from the owner, enabled or not, so the editor's copy of a draft
     * (whose value may be a preloaded set) and the diff screen read the same thing.
     */
    private function nestedEntriesToString(FieldInterface $field, mixed $value, ?ElementInterface $owner, int $depth): string
    {
        if ($owner !== null && $owner->id) {
            $value = $field->normalizeValue(null, $owner);
        }

        if ($value instanceof ElementQueryInterface) {
            $entries = (clone $value)->status(null)->all();
        } elseif ($value instanceof Collection) {
            $entries = $value->all();
        } elseif ($value instanceof ElementInterface) {
            $entries = [$value];
        } elseif (is_array($value)) {
            $entries = $value;
        } else {
            return '';
        }

        if ($entries === []) {
            return '(none)';
        }

        if ($depth >= self::MAX_DEPTH) {
            return $this->relationsToString($entries);
        }

        $blocks = [];

        foreach (array_values($entries) as $i => $entry) {
            if (! $entry instanceof Entry) {
                continue;
            }

            $type = $entry->getType();
            $lines = ['#'.($i + 1).' '.$type->name.($entry->enabled ? '' : ' ('.t('disabled', category: 'peek').')')];

            if ($type->hasTitleField && (string) $entry->title !== '') {
                $lines[] = '    '.t('Title', category: 'peek').': '.$entry->title;
            }

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $nested) {
                try {
                    $text = $this->valueToString($nested, $entry->getFieldValue((string) $nested->handle), $entry, $depth + 1);
                } catch (Throwable $e) {
                    Log::warning("Peek couldn't diff nested field \"{$nested->handle}\": {$e->getMessage()}");
                    $text = '?';
                }

                if (trim($text) === '' || $text === '(none)') {
                    continue;
                }

                $valueLines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($text)));
                $lines[] = '    '.$nested->name.': '.array_shift($valueLines);

                foreach ($valueLines as $line) {
                    $lines[] = '        '.$line;
                }
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private function relationsToString(mixed $value): string
    {
        $elements = $this->elementsOf($value);

        if ($elements === null) {
            return '';
        }

        if ($elements === []) {
            return '(none)';
        }

        if ($this->redactRelations && $this->displaying) {
            return count($elements) === 1 ? '1 related element' : count($elements).' related elements';
        }

        return implode("\n", array_map(
            fn (ElementInterface $el) => trim(((string) ($el->title ?? '')).' (#'.$el->id.')'),
            $elements,
        ));
    }

    private function assetsToString(mixed $value): string
    {
        $elements = $this->elementsOf($value);

        if ($elements === null) {
            return '';
        }

        if ($elements === []) {
            return '(none)';
        }

        if ($this->redactRelations && $this->displaying) {
            return count($elements) === 1 ? '1 related element' : count($elements).' related elements';
        }

        return implode("\n", array_map(
            fn (ElementInterface $el) => ($el instanceof Asset ? $el->getFilename() : (string) ($el->title ?? '')).' (#'.$el->id.')',
            $elements,
        ));
    }

    /**
     * @return list<ElementInterface>|null
     */
    private function elementsOf(mixed $value): ?array
    {
        $elements = match (true) {
            $value instanceof ElementQueryInterface => $value->all(),
            $value instanceof Collection => $value->all(),
            is_array($value) => $value,
            default => null,
        };

        if ($elements === null) {
            return null;
        }

        return array_values(array_filter($elements, fn ($el) => $el instanceof ElementInterface));
    }

    private function scalarString(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => '',
        };
    }

    private function toJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $json === false ? '' : $json;
    }

    private function renderTextDiff(string $old, string $new): string
    {
        if ($old === '' && $new === '') {
            return '';
        }

        return DiffHelper::calculate(
            $old,
            $new,
            'SideBySide',
            ['context' => 3, 'ignoreWhitespace' => false, 'ignoreCase' => false],
            ['detailLevel' => 'word'],
        );
    }

    private function renderBooleanDiff(bool $old, bool $new): string
    {
        return sprintf(
            '<div class="peek-boolean-diff"><span class="peek-diff-removed">%s</span> → <span class="peek-diff-added">%s</span></div>',
            $old ? 'On' : 'Off',
            $new ? 'On' : 'Off',
        );
    }
}
