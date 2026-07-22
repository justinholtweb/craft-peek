<?php

/**
 * Test database connection.
 *
 * Defaults target the plugin's DDEV environment. The whole schema is dropped
 * and rebuilt for the integration suite, so this must never point at a database
 * you care about — hence the dedicated `craft_peek_test` default.
 */

return [
    'driver' => getenv('CRAFT_DB_DRIVER') ?: 'mysql',
    'server' => getenv('CRAFT_DB_SERVER') ?: 'db',
    'port' => (int)(getenv('CRAFT_DB_PORT') ?: 3306),
    'database' => getenv('CRAFT_DB_DATABASE') ?: 'craft_peek_test',
    'user' => getenv('CRAFT_DB_USER') ?: 'root',
    'password' => getenv('CRAFT_DB_PASSWORD') ?: 'root',
    'schema' => getenv('CRAFT_DB_SCHEMA') ?: 'public',
    'tablePrefix' => '',
];
