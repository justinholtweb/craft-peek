<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Database;

/**
 * Peek's table names, unprefixed: Laravel adds the connection's prefix itself.
 *
 * Here rather than on the install migration, which Craft `require`s outside the autoloader.
 */
final class Tables
{
    public const string RELEASES = 'peek_releases';

    public const string RELEASE_ENTRIES = 'peek_release_entries';
}
