<?php

namespace justinholtweb\peek\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets as AssetsField;
use craft\fields\BaseRelationField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Color as ColorField;
use craft\fields\data\LinkData;
use craft\fields\Date as DateField;
use craft\fields\Email as EmailField;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch as LightswitchField;
use craft\fields\Link as LinkField;
use craft\fields\Matrix as MatrixField;
use craft\fields\Users as UsersField;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use Illuminate\Support\Collection;
use Jfcherng\Diff\DiffHelper;
use justinholtweb\peek\models\FieldDiff;
use yii\base\Arrayable;
use yii\base\Component;

class DiffService extends Component
{
    /**
     * Compare a draft entry against its canonical version field by field.
     *
     * With `$redactRelations`, relation fields (anywhere, including inside Matrix blocks) show
     * only how many elements they hold — "2 related elements" — never their titles or IDs.
     * Whether a field changed is still decided on the full values. GraphQL uses this: a schema's
     * section scope covers the draft, not whatever it relates to.
     *
     * @return FieldDiff[]
     */
    public function diffEntry(Entry $draft, Entry $canonical, bool $redactRelations = false): array
    {
        $diffs = [];

        // Native attributes
        $nativeFields = [
            'title' => 'Title',
            'slug' => 'Slug',
        ];

        foreach ($nativeFields as $handle => $label) {
            $oldVal = (string)($canonical->$handle ?? '');
            $newVal = (string)($draft->$handle ?? '');

            $diff = new FieldDiff();
            $diff->handle = $handle;
            $diff->label = $label;
            $diff->type = 'native';
            $diff->oldValue = $oldVal;
            $diff->newValue = $newVal;
            $diff->hasChanges = $oldVal !== $newVal;

            if ($diff->hasChanges) {
                $diff->diffHtml = $this->_renderTextDiff($oldVal, $newVal);
            }

            $diffs[] = $diff;
        }

        // Enabled status
        $enabledDiff = new FieldDiff();
        $enabledDiff->handle = 'enabled';
        $enabledDiff->label = 'Enabled';
        $enabledDiff->type = 'native';
        $enabledDiff->oldValue = $canonical->enabled;
        $enabledDiff->newValue = $draft->enabled;
        $enabledDiff->hasChanges = $canonical->enabled !== $draft->enabled;
        if ($enabledDiff->hasChanges) {
            $enabledDiff->diffHtml = $this->_renderBooleanDiff($canonical->enabled, $draft->enabled);
        }
        $diffs[] = $enabledDiff;

        // Custom fields
        $fieldLayout = $canonical->getFieldLayout();
        if ($fieldLayout) {
            foreach ($fieldLayout->getCustomFields() as $field) {
                try {
                    $diffs[] = $this->_diffField($field, $draft, $canonical, $redactRelations);
                } catch (\Throwable $e) {
                    // A third-party field we can't serialize shouldn't take down the whole diff
                    Craft::warning("Couldn't diff field \"{$field->handle}\": {$e->getMessage()}", __METHOD__);
                    $diffs[] = $this->_unreadableFieldDiff($field);
                }
            }
        }

        return $diffs;
    }

    /**
     * Get preview URLs for iframe comparison.
     *
     * @return array{canonical: string|null, draft: string|null}
     */
    public function getPreviewUrls(Entry $draft, Entry $canonical): array
    {
        return [
            'canonical' => $canonical->getUrl(),
            'draft' => $this->_draftPreviewUrl($draft),
        ];
    }

    /**
     * A draft has its entry's URI, so its plain URL serves the live page. Before 5.1.1 that's
     * what the Draft pane showed. This is the URL Craft's own Preview button builds: a preview
     * token that swaps the draft in for its entry, and the signed `x-craft-preview` param that
     * makes `craft.app.request.isPreview` true (and keeps static caches out of the way).
     */
    private function _draftPreviewUrl(Entry $draft): ?string
    {
        $url = $draft->getUrl();
        if ($url === null) {
            return null;
        }

        $token = Craft::$app->getTokens()->createPreviewToken([
            'preview/preview', [
                'elementType' => Entry::class,
                'canonicalId' => $draft->getCanonicalId(),
                'siteId' => $draft->siteId,
                'draftId' => $draft->draftId,
                'revisionId' => null,
                'userId' => Craft::$app->getUser()->getId(),
            ],
        ]);

        if ($token === false) {
            Craft::warning("Couldn't create a preview token for draft {$draft->id}.", 'peek');
            return null;
        }

        $url = UrlHelper::urlWithParams($url, [
            'x-craft-preview' => Craft::$app->getSecurity()->hashData(StringHelper::randomString(10)),
        ]);

        return UrlHelper::urlWithToken($url, $token);
    }

    private function _diffField(FieldInterface $field, Entry $draft, Entry $canonical, bool $redact = false): FieldDiff
    {
        $handle = $field->handle;
        $oldValue = $canonical->getFieldValue($handle);
        $newValue = $draft->getFieldValue($handle);

        // Use field-type-aware serialization
        $oldStr = $this->_fieldValueToString($field, $oldValue, $canonical);
        $newStr = $this->_fieldValueToString($field, $newValue, $draft);

        $diff = new FieldDiff();
        $diff->handle = $handle;
        $diff->label = $field->name;
        $diff->type = get_class($field);
        // Changed or not on the full values: swapping one related element for another is a change
        $diff->hasChanges = $oldStr !== $newStr;

        if ($redact) {
            $oldStr = $this->_fieldValueToString($field, $oldValue, $canonical, 0, true);
            $newStr = $this->_fieldValueToString($field, $newValue, $draft, 0, true);
        }

        $diff->oldValue = $oldStr;
        $diff->newValue = $newStr;

        if ($diff->hasChanges) {
            // Lightswitch gets special boolean rendering
            if ($field instanceof LightswitchField) {
                $oldBool = !empty($oldValue);
                $newBool = !empty($newValue);
                $diff->diffHtml = $this->_renderBooleanDiff($oldBool, $newBool);
            } else {
                $diff->diffHtml = $this->_renderTextDiff($oldStr, $newStr);
            }
        }

        return $diff;
    }

    private function _unreadableFieldDiff(FieldInterface $field): FieldDiff
    {
        $diff = new FieldDiff();
        $diff->handle = $field->handle;
        $diff->label = $field->name;
        $diff->type = get_class($field);
        $diff->oldValue = '';
        $diff->newValue = '';
        $diff->hasChanges = false;
        $diff->error = Craft::t('peek', 'This field can’t be compared.');

        return $diff;
    }

    /**
     * Serialize a field value to a comparable string representation.
     * Each field type gets appropriate treatment.
     */
    private function _fieldValueToString(FieldInterface $field, mixed $value, ?ElementInterface $owner = null, int $depth = 0, bool $redact = false): string
    {
        // Matrix — compare blocks by what's in them. A draft gets its own copy of every block it
        // touches, with a new ID, so before 5.1.2 an edited-then-reverted block read as a change
        // and the diff showed nothing but "(#id)" lines (issue #3).
        if ($field instanceof MatrixField) {
            return $this->_nestedEntriesToString($field, $value, $owner, $depth, $redact);
        }

        // Redacted (GraphQL), deny by default: nothing that holds an element is shown, only counted.
        // A schema's scopes cover the draft, not what its fields point at.
        if ($redact) {
            if ($field instanceof BaseRelationField) {
                return $this->_relationCount($value);
            }

            // An element link's text is the element's URL
            if ($value instanceof LinkData) {
                return $value->getElement() !== null
                    ? Craft::t('peek', '1 linked element')
                    : (string)$value;
            }

            $elements = $this->_countElements($value);
            if ($elements !== null) {
                return $elements === 0 ? '(none)' : $this->_relatedText($elements);
            }

            if ($value instanceof Collection || is_array($value)) {
                // Element-free arrays (Table fields) are shown only when every leaf is plain data:
                // encoding anything else would run an object's jsonSerialize()/toArray().
                $plain = $this->_plainData($value instanceof Collection ? $value->all() : $value);

                return $plain === null ? Craft::t('peek', '(not shown over GraphQL)') : $this->_toJson($plain);
            } elseif (is_object($value) && method_exists($value, 'getRawContent')) {
                // Rich text (CKEditor, HtmlFieldData). Never render it: rendering resolves reference
                // tags and nested entries, which can pull in other elements. The stored HTML, minus
                // reference tags, is the draft's own text.
                $raw = (string)$value->getRawContent();
                $raw = (string)preg_replace('/\{[a-z][a-z0-9_-]*:[^}]*\}/i', '', $raw);

                return trim(strip_tags($raw));
            } elseif (is_object($value) && !$value instanceof \DateTimeInterface) {
                // Any other object — a third-party field's value, Stringable or not — is something
                // Peek can't vouch for. Element-free arrays (Table fields) are still shown.
                return Craft::t('peek', '(not shown over GraphQL)');
            }
        }

        if ($value === null) {
            return '';
        }

        // Eager-loaded relations (e.g. the element open in the entry editor) come as a collection
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        // Lightswitch — boolean
        if ($field instanceof LightswitchField) {
            return !empty($value) ? 'On' : 'Off';
        }

        // Date field
        if ($field instanceof DateField) {
            if ($value instanceof \DateTime) {
                return $value->format('Y-m-d H:i:s');
            }
            return (string)$value;
        }

        // Color field
        if ($field instanceof ColorField) {
            return is_string($value) ? $value : (string)$value;
        }

        // Email field
        if ($field instanceof EmailField) {
            return is_string($value) ? $value : '';
        }

        // Link field
        if ($field instanceof LinkField) {
            if (is_object($value) && method_exists($value, '__toString')) {
                return (string)$value;
            }
            if (is_string($value)) {
                return $value;
            }
            return '';
        }

        // Relation fields — Entries, Categories, Users, Assets
        if ($field instanceof EntriesField || $field instanceof CategoriesField || $field instanceof UsersField) {
            return $this->_relationsToString($value);
        }

        if ($field instanceof AssetsField) {
            return $this->_assetsToString($value);
        }

        // Plain text / textarea
        if (is_string($value)) {
            return $value;
        }

        // Boolean (fallback)
        if (is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        // Numeric
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        // DateTime (fallback)
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        // Element queries (generic relation fallback)
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            return $redact ? $this->_relationCount($value) : $this->_relationsToString($value);
        }

        // CKEditor / objects with __toString — strip HTML for text diff
        if (is_object($value) && method_exists($value, '__toString')) {
            $html = (string)$value;
            return strip_tags($html);
        }

        // Models from third-party fields (e.g. SEOmatic's MetaBundle) have no __toString
        if ($value instanceof Arrayable) {
            return $this->_toJson($value->toArray());
        }

        // Arrays (Matrix, table fields, etc.), JsonSerializable and plain objects
        if (is_array($value) || is_object($value)) {
            return $this->_toJson($value);
        }

        return (string)$value;
    }

    /**
     * A Matrix field's blocks, each as its entry type, title and field values, indented under
     * a "#n Type" heading. The blocks are always queried fresh from the owner, enabled or not,
     * so the entry open in the editor (whose field value may be a preloaded set) and the diff
     * page read the same thing.
     */
    private function _nestedEntriesToString(MatrixField $field, mixed $value, ?ElementInterface $owner, int $depth, bool $redact = false): string
    {
        if ($owner !== null && $owner->id) {
            $entries = $field->normalizeValue(null, $owner)->status(null)->all();
        } elseif ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $entries = (clone $value)->status(null)->all();
        } elseif ($value instanceof Collection) {
            $entries = $value->all();
        } elseif (is_array($value)) {
            $entries = $value;
        } else {
            return '';
        }

        if (empty($entries)) {
            return '(none)';
        }

        // Matrix inside Matrix inside… — past this, fall back to IDs
        if ($depth >= 4) {
            return $redact ? $this->_relationCount($entries) : $this->_relationsToString($entries);
        }

        $blocks = [];
        foreach (array_values($entries) as $i => $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $type = $entry->getType();
            $lines = ['#' . ($i + 1) . ' ' . $type->name . ($entry->enabled ? '' : ' (disabled)')];

            if ($type->hasTitleField && (string)$entry->title !== '') {
                $lines[] = '    Title: ' . $entry->title;
            }

            foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $nestedField) {
                try {
                    $str = $this->_fieldValueToString($nestedField, $entry->getFieldValue($nestedField->handle), $entry, $depth + 1, $redact);
                } catch (\Throwable $e) {
                    Craft::warning("Couldn't diff nested field \"{$nestedField->handle}\": {$e->getMessage()}", __METHOD__);
                    $str = '?';
                }

                if (trim($str) === '' || $str === '(none)') {
                    continue;
                }

                $valueLines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($str)));
                $lines[] = '    ' . $nestedField->name . ': ' . array_shift($valueLines);
                foreach ($valueLines as $line) {
                    $lines[] = '        ' . $line;
                }
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private function _toJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $json === false ? '' : $json;
    }

    /**
     * A relation value as only its size: "1 related element", "3 related elements", or "(none)".
     */
    private function _relationCount(mixed $value): string
    {
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $count = (int)(clone $value)->count();
        } elseif ($value instanceof Collection) {
            $count = $value->count();
        } elseif (is_array($value)) {
            $count = count($value);
        } else {
            return '';
        }

        if ($count === 0) {
            return '(none)';
        }

        return $this->_relatedText($count);
    }

    private function _relatedText(int $count): string
    {
        return Craft::t('peek', '{n, plural, =1{# related element} other{# related elements}}', ['n' => $count]);
    }

    /**
     * An array with every leaf null, scalar or a date (formatted `Y-m-d H:i:s`), or null if it holds
     * anything else, or nests deeper than `$maxDepth` (fail closed).
     *
     * @param array<mixed> $value
     * @return array<mixed>|null
     */
    private function _plainData(array $value, int $depth = 0, int $maxDepth = 5): ?array
    {
        if ($value !== [] && $depth >= $maxDepth) {
            return null;
        }

        foreach ($value as $key => $item) {
            if ($item === null || is_scalar($item)) {
                continue;
            }

            if ($item instanceof \DateTimeInterface) {
                $value[$key] = $item->format('Y-m-d H:i:s');
            } elseif (is_array($item)) {
                $item = $this->_plainData($item, $depth + 1, $maxDepth);
                if ($item === null) {
                    return null;
                }
                $value[$key] = $item;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * How many elements a value holds, or null when it holds none and isn't an element container.
     * Looks inside arrays, collections and plain objects, `$maxDepth` levels down; anything past
     * that counts as holding an element, so redaction fails closed.
     */
    private function _countElements(mixed $value, int $depth = 0, int $maxDepth = 5): ?int
    {
        if ($value instanceof ElementInterface) {
            return 1;
        }

        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            return (int)(clone $value)->count();
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        } elseif ($value instanceof Arrayable || $value instanceof \JsonSerializable || (is_object($value) && !$value instanceof \Stringable)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return null;
        }

        if ($depth >= $maxDepth) {
            return $value === [] ? null : 1;
        }

        $count = null;
        foreach ($value as $item) {
            $found = $this->_countElements($item, $depth + 1, $maxDepth);
            if ($found !== null) {
                $count = ($count ?? 0) + $found;
            }
        }

        return $count;
    }

    /**
     * Convert a relation element query to a comparable string.
     */
    private function _relationsToString(mixed $value): string
    {
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $elements = $value->all();
        } elseif ($value instanceof Collection) {
            $elements = $value->all();
        } elseif (is_array($value)) {
            $elements = $value;
        } else {
            return '';
        }

        if (empty($elements)) {
            return '(none)';
        }

        $lines = [];
        foreach ($elements as $el) {
            $label = $el->title ?? ($el->name ?? '');
            $lines[] = "{$label} (#{$el->id})";
        }
        return implode("\n", $lines);
    }

    /**
     * Convert asset relations to a comparable string with filenames.
     */
    private function _assetsToString(mixed $value): string
    {
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $elements = $value->all();
        } elseif ($value instanceof Collection) {
            $elements = $value->all();
        } elseif (is_array($value)) {
            $elements = $value;
        } else {
            return '';
        }

        if (empty($elements)) {
            return '(none)';
        }

        $lines = [];
        foreach ($elements as $el) {
            if ($el instanceof Asset) {
                $lines[] = "{$el->filename} (#{$el->id})";
            } else {
                $lines[] = ($el->title ?? '') . " (#{$el->id})";
            }
        }
        return implode("\n", $lines);
    }

    private function _renderTextDiff(string $old, string $new): string
    {
        if ($old === '' && $new === '') {
            return '';
        }

        return DiffHelper::calculate(
            $old,
            $new,
            'SideBySide',
            [
                'context' => 3,
                'ignoreWhitespace' => false,
                'ignoreCase' => false,
            ],
            ['detailLevel' => 'word']
        );
    }

    private function _renderBooleanDiff(bool $old, bool $new): string
    {
        $oldLabel = $old ? 'On' : 'Off';
        $newLabel = $new ? 'On' : 'Off';

        return sprintf(
            '<div class="peek-boolean-diff"><span class="peek-diff-removed">%s</span> → <span class="peek-diff-added">%s</span></div>',
            htmlspecialchars($oldLabel),
            htmlspecialchars($newLabel),
        );
    }
}
