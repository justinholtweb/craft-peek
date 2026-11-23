<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Tests;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Peek\Tests\Support\CreatesContent;
use Override;

abstract class TestCase extends PluginTestCase
{
    use CreatesContent;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Solo allows one user account, and most tests need an editor beside the admin. Team is
        // also the edition where Peek's own releases are the release system; tests of Craft's
        // releases switch to Pro themselves.
        Edition::set(Edition::Team);
    }

    /**
     * SQLite only enforces foreign keys when asked, and the pragma is ignored inside a
     * transaction, so it's set just before each test's transaction starts. Peek depends on one:
     * `peek_release_entries.draftId` is ON DELETE SET NULL, which is how an applied draft's row
     * survives the draft element's deletion.
     */
    #[Override]
    public function beginDatabaseTransaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        parent::beginDatabaseTransaction();
    }
}
