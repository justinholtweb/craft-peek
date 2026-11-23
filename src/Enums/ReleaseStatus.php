<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Enums;

use function CraftCms\Cms\t;

/**
 * The status of one of Peek's own releases. Values are what 5.x stored, so upgraded rows read as-is.
 */
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
            self::Draft => t('Draft', category: 'peek'),
            self::Ready => t('Ready', category: 'peek'),
            self::Scheduled => t('Scheduled', category: 'peek'),
            self::Publishing => t('Publishing', category: 'peek'),
            self::Published => t('Published', category: 'peek'),
            self::Failed => t('Failed', category: 'peek'),
        };
    }

    /** A CSS colour name for the status dot. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ready => 'blue',
            self::Scheduled => 'orange',
            self::Publishing => 'yellow',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }

    /**
     * Whether a release in this status still has drafts to apply. Applying a draft deletes it, so
     * a published release has none. `Publishing` stays publishable because the scheduler sets it
     * before queueing the job, and `Failed` because a rolled-back release still has all its drafts.
     */
    public function isPublishable(): bool
    {
        return $this !== self::Published;
    }

    /** Whether the release's details and drafts can still change. */
    public function isEditable(): bool
    {
        return ! in_array($this, [self::Publishing, self::Published], true);
    }

    /** Statuses a person can choose. The rest are only ever set by publishing. */
    public function isChoosable(): bool
    {
        return in_array($this, [self::Draft, self::Ready, self::Scheduled], true);
    }

    /** Whether the release still counts as work in progress (the dashboard's "active"). */
    public function isActive(): bool
    {
        return ! in_array($this, [self::Published, self::Failed], true);
    }
}
