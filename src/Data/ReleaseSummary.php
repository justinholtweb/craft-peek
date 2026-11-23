<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Data;

use Carbon\CarbonInterface;

/**
 * A release, whichever system it lives in, as the dashboard, the editor sidebar and the console
 * show it.
 */
final readonly class ReleaseSummary
{
    public const string SOURCE_PEEK = 'peek';

    public const string SOURCE_CRAFT = 'craft';

    /**
     * @param  list<array{canonicalId: int, draftId: int|null, status: string}>  $entries  `draftId` is the draft element's ID.
     */
    public function __construct(
        /** `peek` (Peek's own tables) or `craft` (Craft's releases, Pro and Enterprise). */
        public string $source,
        public int $id,
        public string $name,
        public string $status,
        public string $statusLabel,
        public string $color,
        public ?string $site,
        public int $entryCount,
        public ?CarbonInterface $scheduledAt,
        public ?CarbonInterface $publishedAt,
        public string $cpUrl,
        public bool $editable,
        public bool $active,
        public array $entries = [],
        public ?string $lastError = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $withEntries = false): array
    {
        $data = [
            'source' => $this->source,
            'id' => $this->id,
            'name' => $this->name,
            'site' => $this->site,
            'status' => $this->status,
            'entryCount' => $this->entryCount,
            'scheduledDate' => $this->scheduledAt?->toAtomString(),
            'publishedDate' => $this->publishedAt?->toAtomString(),
        ];

        if ($this->lastError !== null) {
            $data['lastError'] = $this->lastError;
        }

        if ($withEntries) {
            $data['entries'] = $this->entries;
        }

        return $data;
    }
}
