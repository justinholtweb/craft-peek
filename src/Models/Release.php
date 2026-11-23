<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Models;

use Carbon\Carbon;
use CraftCms\Cms\Shared\BaseModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use JustinHoltWeb\Peek\Database\Tables;
use JustinHoltWeb\Peek\Enums\ReleaseStatus;
use Override;

/**
 * One of Peek's own releases: a set of drafts published together.
 *
 * Craft's `BaseModel` stores dates as UTC and names the timestamps `dateCreated`/`dateUpdated`,
 * which is exactly the 5.x schema.
 *
 * @property int $id
 * @property int $siteId
 * @property string $name
 * @property string|null $description
 * @property ReleaseStatus $status
 * @property Carbon|null $scheduledDate
 * @property Carbon|null $publishedDate
 * @property int|null $publishedBy
 * @property int|null $createdBy
 * @property int|null $scheduledBy The user a scheduled publish is authorized as.
 * @property string $uid
 * @property Carbon $dateCreated
 * @property Carbon $dateUpdated
 * @property-read Collection<int, ReleaseEntry> $entries
 */
class Release extends BaseModel
{
    #[Override]
    protected $table = Tables::RELEASES;

    #[Override]
    protected $attributes = [
        'status' => 'draft',
    ];

    #[Override]
    protected static function booted(): void
    {
        static::creating(function (Release $release): void {
            if (in_array($release->getAttribute('uid'), [null, '', '0'], true)) {
                $release->uid = Str::uuid()->toString();
            }
        });
    }

    /** @return HasMany<ReleaseEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ReleaseEntry::class, 'releaseId')->orderBy('sortOrder')->orderBy('id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'siteId' => 'integer',
            'status' => ReleaseStatus::class,
            'scheduledDate' => 'datetime',
            'publishedDate' => 'datetime',
            'publishedBy' => 'integer',
            'createdBy' => 'integer',
            'scheduledBy' => 'integer',
        ];
    }
}
