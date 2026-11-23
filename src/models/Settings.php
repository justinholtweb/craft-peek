<?php

namespace justinholtweb\peek\models;

use Craft;
use craft\base\Model;

class Settings extends Model
{
    /** Largest custom nav icon accepted, in bytes. */
    public const MAX_SVG_BYTES = 51200;

    /** How many days before a draft is considered "stale" */
    public int $staleDraftDays = 14;

    /** Default site ID for releases (null = primary site) */
    public ?int $defaultSiteId = null;

    /** Enable visual preview iframe comparison */
    public bool $enableVisualPreview = true;

    /** Max entries allowed per release */
    public int $maxEntriesPerRelease = 50;

    /** The CP sidebar label. Empty uses "Peek". */
    public ?string $navLabel = null;

    /** The CP sidebar icon: an icon name from Craft's icon picker (e.g. `eye`). */
    public ?string $navIcon = null;

    /**
     * Raw SVG for the CP sidebar icon. Wins over `navIcon`. Stored as entered and sanitized every
     * time it is drawn.
     */
    public ?string $navIconSvg = null;

    public function defineRules(): array
    {
        return [
            [['staleDraftDays'], 'integer', 'min' => 1],
            [['defaultSiteId'], 'integer', 'skipOnEmpty' => true],
            [['enableVisualPreview'], 'boolean'],
            [['maxEntriesPerRelease'], 'integer', 'min' => 1, 'max' => 500],
            [['navLabel', 'navIcon', 'navIconSvg'], 'trim'],
            [['navLabel'], 'string', 'max' => 64],
            [['navIcon'], 'match', 'pattern' => '/^[a-z0-9\-]+$/', 'message' => Craft::t('peek', 'Choose an icon from the icon picker.')],
            [['navIcon'], 'validateNavIcon', 'skipOnEmpty' => true],
            [['navIconSvg'], 'string', 'max' => self::MAX_SVG_BYTES],
            [['navIconSvg'], 'validateSvg', 'skipOnEmpty' => true],
        ];
    }

    public function validateNavIcon(string $attribute): void
    {
        if (!self::iconExists((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('peek', 'Choose an icon from the icon picker.'));
        }
    }

    public function validateSvg(string $attribute): void
    {
        if (!self::isSvgMarkup((string)$this->$attribute)) {
            $this->addError($attribute, Craft::t('peek', 'Paste a single <svg> element.'));
        }
    }

    /**
     * Whether an icon name is one of Craft's bundled icons (the set the icon picker offers).
     */
    public static function iconExists(string $name): bool
    {
        if ($name === '' || strlen($name) > 64 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name)) {
            return false;
        }

        // `@appicons` is Craft's solid set (plus a few aliases) on a real install
        foreach (["@appicons/$name.svg", "@app/icons/solid/$name.svg", "@app/icons/custom-icons/$name.svg"] as $alias) {
            $path = Craft::getAlias($alias, false);
            if ($path && is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the text is one `<svg>` element (optionally after an XML declaration / comments).
     */
    public static function isSvgMarkup(string $svg): bool
    {
        $svg = trim($svg);

        if (!preg_match('/^(<\?xml[^>]*\?>\s*)?(<!--.*?-->\s*)*(<!DOCTYPE[^>]*>\s*)?<svg[\s>]/is', $svg)) {
            return false;
        }

        if (!preg_match('/<\/svg>\s*$/i', $svg)) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // No network, no entity expansion.
        $loaded = $doc->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $doc->documentElement !== null && strtolower($doc->documentElement->localName) === 'svg';
    }
}
