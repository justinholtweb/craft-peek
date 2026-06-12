<?php

namespace justinholtweb\peek\services;

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets as AssetsField;
use craft\fields\Categories as CategoriesField;
use craft\fields\Color as ColorField;
use craft\fields\Date as DateField;
use craft\fields\Email as EmailField;
use craft\fields\Entries as EntriesField;
use craft\fields\Lightswitch as LightswitchField;
use craft\fields\Link as LinkField;
use craft\fields\Users as UsersField;
use Jfcherng\Diff\DiffHelper;
use justinholtweb\peek\models\FieldDiff;
use yii\base\Component;

class DiffService extends Component
{
    /**
     * Compare a draft entry against its canonical version field by field.
     *
     * @return FieldDiff[]
     */
    public function diffEntry(Entry $draft, Entry $canonical): array
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
                $diffs[] = $this->_diffField($field, $draft, $canonical);
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
            'draft' => $draft->getUrl(),
        ];
    }

    private function _diffField(FieldInterface $field, Entry $draft, Entry $canonical): FieldDiff
    {
        $handle = $field->handle;
        $oldValue = $canonical->getFieldValue($handle);
        $newValue = $draft->getFieldValue($handle);

        // Use field-type-aware serialization
        $oldStr = $this->_fieldValueToString($field, $oldValue);
        $newStr = $this->_fieldValueToString($field, $newValue);

        $diff = new FieldDiff();
        $diff->handle = $handle;
        $diff->label = $field->name;
        $diff->type = get_class($field);
        $diff->oldValue = $oldStr;
        $diff->newValue = $newStr;
        $diff->hasChanges = $oldStr !== $newStr;

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

    /**
     * Serialize a field value to a comparable string representation.
     * Each field type gets appropriate treatment.
     */
    private function _fieldValueToString(FieldInterface $field, mixed $value): string
    {
        if ($value === null) {
            return '';
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
            return $this->_relationsToString($value);
        }

        // CKEditor / objects with __toString — strip HTML for text diff
        if (is_object($value) && method_exists($value, '__toString')) {
            $html = (string)$value;
            return strip_tags($html);
        }

        // Arrays (Matrix, table fields, etc.)
        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        return (string)$value;
    }

    /**
     * Convert a relation element query to a comparable string.
     */
    private function _relationsToString(mixed $value): string
    {
        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
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
