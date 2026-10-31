<?php

declare(strict_types=1);

namespace App\Services\Platforms\Eis;

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Procurement\Acquisition\ListingPlan;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Acquisition\ListingWindow;
use App\Dto\Procurement\Acquisition\PartitionResult;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Services\Procurement\Platform\Contracts\ListingFilterCodec;
use App\Services\Procurement\Platform\Contracts\ListingPartitioner;

/** Pure plan, never schedules, writes or finalizes children. */
final readonly class EisListingPartitioner implements ListingFilterCodec, ListingPartitioner
{
    public function __construct(private EisListingPolicy $policy = new EisListingPolicy,
        private \DateTimeZone $timezone = new \DateTimeZone('Europe/Moscow'), private int $settleDays = 2)
    {
        if ($settleDays < 0 || $settleDays > 30) {
            throw new \InvalidArgumentException('Invalid EIS settle days.');
        }
    }

    public function restoreFilter(CursorEnvelope $snapshot): EisListingFilter
    {
        if ($snapshot->codecId !== 'eis.filter' || ! in_array($snapshot->schemaVersion, [1, 2], true)) {
            throw new \InvalidArgumentException('Unsupported EIS filter snapshot.');
        }
        $v = json_decode($snapshot->serializedPayload, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($v) || count($v) !== ($snapshot->schemaVersion === 1 ? 3 : 5) || ! is_array($v['ids'] ?? null) || ! is_array($v['stages'] ?? null)
            || ! array_key_exists('search', $v) || ($v['search'] !== null && ! is_string($v['search']))) {
            throw new \InvalidArgumentException('Invalid EIS filter snapshot.');
        }
        foreach (['ids', 'stages'] as $key) {
            if (! is_array($v[$key]) || ! array_is_list($v[$key])) {
                throw new \InvalidArgumentException('Invalid EIS filter list.');
            }
            foreach ($v[$key] as $entry) {
                if (! is_string($entry)) {
                    throw new \InvalidArgumentException('Invalid EIS filter value.');
                }
            }
        }

        /** @var list<string> $ids */
        $ids = $v['ids'];
        /** @var list<string> $stages */
        $stages = $v['stages'];

        if ($snapshot->schemaVersion === 2 && (! is_bool($v['morphology'] ?? null) || ! is_string($v['sortBy'] ?? null))) {
            throw new \InvalidArgumentException('Invalid EIS filter options.');
        }

        $morphology = $snapshot->schemaVersion === 1 ? false : $v['morphology'];
        $sortBy = $snapshot->schemaVersion === 1 ? 'UPDATE_DATE' : $v['sortBy'];

        return new EisListingFilter($ids, $stages, $v['search'], $morphology, $sortBy);
    }

    public function snapshot(EisListingFilter $filter): CursorEnvelope
    {
        return new CursorEnvelope('eis.filter', 2, json_encode(['ids' => $filter->okpd2Ids,
            'stages' => $filter->stages, 'search' => $filter->search, 'morphology' => $filter->morphology,
            'sortBy' => $filter->sortBy], JSON_THROW_ON_ERROR));
    }

    /** Pure bounded root allocation; the unallocated tail is explicit, never silently dropped. */
    public function plan(EisListingFilter $filter, string $from, string $through,
        string $dateField = 'publish', string $priceFrom = '0.00', string $priceTo = '1000000000.00', int $blockSize = 500): ListingPlan
    {
        $low = $this->cents($priceFrom);
        $high = $this->cents($priceTo);
        $cursor = new EisListingCursor($from, $dateField, $low, $high, $filter->fingerprint(), blockSize: $blockSize);
        new EisListingCursor($through, $dateField, $low, $high, $filter->fingerprint(), blockSize: $blockSize);
        if ($from > $through) {
            throw new \InvalidArgumentException('Inverted listing range.');
        }
        $day = new \DateTimeImmutable($from, $this->timezone);
        $windows = [];
        while ($day->format('Y-m-d') <= $through && count($windows) < 400) {
            $cursor = new EisListingCursor($day->format('Y-m-d'), $dateField, $low, $high, $filter->fingerprint(), blockSize: $blockSize);
            $windows[] = new ListingWindow($cursor->envelope(), $cursor->day, $cursor->day,
                $day->modify('+'.($this->settleDays + 1).' days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
            $day = $day->modify('+1 day');
        }
        $tail = null;
        if ($day->format('Y-m-d') <= $through) {
            $cursor = new EisListingCursor($day->format('Y-m-d'), $dateField, $low, $high, $filter->fingerprint(), blockSize: $blockSize);
            $tail = new ListingWindow($cursor->envelope(), $cursor->day, $through,
                (new \DateTimeImmutable($through, $this->timezone))->modify('+'.($this->settleDays + 1).' days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        }

        return new ListingPlan($windows, $tail);
    }

    private function cents(string $decimal): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $decimal, $m)) {
            throw new \InvalidArgumentException('Invalid decimal partition bound.');
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** Scope identity deliberately excludes the requested date horizon, but includes its axis and declared price scope. */
    public function streamKey(int $registryId, CursorEnvelope $cursor, ContentHash $accessPolicy,
        string $adapterVersion): ContentHash
    {
        if ($registryId < 1 || $adapterVersion === '') {
            throw new \InvalidArgumentException('Invalid listing stream identity.');
        }
        $p = EisListingCursor::decode($cursor);

        return ContentHash::of(json_encode([$registryId, $p->filterHash->hex,
            $accessPolicy->hex, $adapterVersion, $p->dateField, $p->lowCents, $p->highCents, $p->blockSize], JSON_THROW_ON_ERROR));
    }

    /** Pure closing/outage recovery from the immutable assignment, independent of the current profile. */
    public function recoveryPlan(ListingTask $task): ListingPlan
    {
        $p = EisListingCursor::decode($task->rootCursor);

        return $this->plan($this->restoreFilter($task->filter), $task->windowStart, $task->windowEnd, $p->dateField,
            EisListingCursor::decimal($p->lowCents), EisListingCursor::decimal($p->highCents), $p->blockSize);
    }

    public function split(CursorEnvelope $cursor, int $allocatedPartitions): PartitionResult
    {
        $partition = EisListingCursor::decode($cursor);
        if ($allocatedPartitions < 1) {
            throw new \InvalidArgumentException('Invalid allocated partition count.');
        }
        $width = $partition->highCents - $partition->lowCents;
        if ($width <= $this->policy->minIntervalCents || $partition->depth >= $this->policy->maxDepth || $allocatedPartitions + 2 > $this->policy->maxPartitions) {
            return new PartitionResult([], AssessmentReason::Overflow);
        }
        $mid = $partition->lowCents + intdiv($width, 2);

        return new PartitionResult(array_map(static fn (array $range): CursorEnvelope => (new EisListingCursor(
            $partition->day, $partition->dateField, $range[0], $range[1], $partition->filterHash, 1, $partition->depth + 1, $partition->blockSize,
        ))->envelope(), [[$partition->lowCents, $mid], [$mid, $partition->highCents]]));
    }
}
