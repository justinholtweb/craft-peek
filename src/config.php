<?php

/**
 * Peek default config
 *
 * Copy this file to config/peek.php and adjust as needed.
 * Multi-environment config is supported — use '*', 'dev', 'staging', 'production' keys.
 */

return [
    // How many days before a draft is considered "stale"
    'staleDraftDays' => 14,

    // Default site ID for releases (null = primary site)
    'defaultSiteId' => null,

    // Enable visual preview iframe comparison
    'enableVisualPreview' => true,

    // Max entries allowed per release
    'maxEntriesPerRelease' => 50,

    // The CP sidebar label (max 64 characters). null or '' = "Peek"
    'navLabel' => null,

    // The CP sidebar icon: the name of an icon from Craft's icon picker, e.g. 'eye'.
    // null = Peek's own icon
    'navIcon' => null,

    // Raw SVG for the CP sidebar icon, a single <svg> element (max 50 KB). Wins over navIcon.
    // Sanitized every time it is drawn: scripts, event handlers and external references are removed.
    'navIconSvg' => null,
];
