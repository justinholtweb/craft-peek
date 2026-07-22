<?php

/**
 * Global test bootstrap.
 *
 * Defines the CRAFT_* path constants that craft\test\TestSetup needs and boots
 * enough of Craft for the integration suite to spin up a real application
 * against a throwaway database. The unit suite doesn't need any of this beyond
 * having the `Yii` class available, which configureCraft() also provides.
 */

use craft\test\TestSetup;

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');

TestSetup::configureCraft();
