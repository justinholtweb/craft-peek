<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use JustinHoltWeb\Peek\Database\Tables;
use Override;

/**
 * A draft in one of Peek's releases.
 *
 * `draftId` is the draft *element's* ID, and becomes null once the draft is applied (the foreign
 * key is ON DELETE SET NULL), which is why `status` is recorded before the apply.
 *
 * @property int $id
 * @property int $releaseId
 * @property int $canonicalId
 * @property int|null $draftId
 * @property int $sortOrder
 * @property string $status pending, published or failed
 * @property string|null $errorMessage
 * @property string $uid
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 * @property-read Release $release
 */
class ReleaseEntry extends BaseModel
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_FAILED = 'failed';

    #[Override]
    protected $table = Tables::RELEASE_ENTRIES;

    #[Override]
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'sortOrder' => 0,
    ];

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (ReleaseEntry $entry): void {
            if (in_array($entry->getAttribute('uid'), [null, '', '0'], true)) {
                $entry->uid = Str::uuid()->toString();
            }
        });
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'releaseId');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'releaseId' => 'integer',
            'canonicalId' => 'integer',
            'draftId' => 'integer',
            'sortOrder' => 'integer',
        ];
    }
}
