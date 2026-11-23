<?php

declare(strict_types=1);

use JustinHoltWeb\Peek\Enums\ReleaseStatus;

it('reads every status 5.x stored', function () {
    foreach (['draft', 'ready', 'scheduled', 'publishing', 'published', 'failed'] as $value) {
        expect(ReleaseStatus::tryFrom($value))->not->toBeNull();
    }
});

it('only lets people choose draft, ready and scheduled', function () {
    $choosable = array_values(array_filter(ReleaseStatus::cases(), fn ($s) => $s->isChoosable()));

    expect($choosable)->toBe([ReleaseStatus::Draft, ReleaseStatus::Ready, ReleaseStatus::Scheduled]);
});

it('treats published as final and publishing as locked', function () {
    expect(ReleaseStatus::Published->isPublishable())->toBeFalse()
        ->and(ReleaseStatus::Failed->isPublishable())->toBeTrue()
        ->and(ReleaseStatus::Publishing->isEditable())->toBeFalse()
        ->and(ReleaseStatus::Failed->isEditable())->toBeTrue();
});
