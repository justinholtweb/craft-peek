<?php

declare(strict_types=1);

use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\Peek\Database\Migrations\Install;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use JustinHoltWeb\Peek\Models\Release;

it('keeps 5.x rows when the install migration meets existing tables', function () {
    // A row as Peek 5 wrote it: plain status string, UTC dates, its own uid.
    DB::table('peek_releases')->insert([
        'siteId' => Sites::getPrimarySite()->id,
        'name' => 'From Craft 5',
        'status' => 'scheduled',
        'scheduledDate' => '2030-01-01 09:00:00',
        'dateCreated' => '2026-10-01 12:00:00',
        'dateUpdated' => '2026-10-01 12:00:00',
        'uid' => 'b3f1c1d2-0000-4000-8000-000000000001',
    ]);

    // Installing Peek already loaded the migration (Craft requires it outside the autoloader).
    if (! class_exists(Install::class, false)) {
        require dirname(__DIR__, 2).'/database/migrations/Install.php';
    }

    new Install()->up();

    $release = Release::query()->where('name', 'From Craft 5')->sole();

    expect($release->status)->toBe(ReleaseStatus::Scheduled)
        ->and($release->scheduledDate->utc()->format('Y-m-d H:i'))->toBe('2030-01-01 09:00')
        ->and($release->uid)->toBe('b3f1c1d2-0000-4000-8000-000000000001');
});
