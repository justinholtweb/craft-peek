<?php

use craft\ecs\SetList;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests/unit',
        __DIR__ . '/tests/integration',
        __DIR__ . '/tests/_support',
        __FILE__,
    ]);

    // Codeception rewrites these on every `codecept build`.
    $ecsConfig->skip([__DIR__ . '/tests/_support/_generated']);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);
};
