<?php

namespace justinholtweb\peek\enums;

enum ReleaseStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready',
            self::Scheduled => 'Scheduled',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'grey',
            self::Ready => 'blue',
            self::Scheduled => 'orange',
            self::Publishing => 'yellow',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }

    /**
     * Valid state transitions for releases.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Ready, self::Scheduled, self::Publishing]),
            self::Ready => in_array($target, [self::Draft, self::Scheduled, self::Publishing]),
            self::Scheduled => in_array($target, [self::Draft, self::Ready, self::Publishing]),
            self::Publishing => in_array($target, [self::Published, self::Failed]),
            self::Published => false,
            self::Failed => in_array($target, [self::Draft, self::Ready]),
        };
    }
}
