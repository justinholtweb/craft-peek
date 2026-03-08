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
];
