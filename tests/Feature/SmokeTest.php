<?php

declare(strict_types=1);

use CraftCms\Cms\Plugin\Plugins;
use Illuminate\Support\Facades\Schema;
use JustinHoltWeb\Peek\Plugin;

it('installs Peek with its tables', function () {
    expect(app(Plugins::class)->getPlugin('peek'))->toBeInstanceOf(Plugin::class)
        ->and(Schema::hasTable('peek_releases'))->toBeTrue()
        ->and(Schema::hasTable('peek_release_entries'))->toBeTrue();
});
