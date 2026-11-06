<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Repositories\Llm\LlmCallReadRepository;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageReport;
use App\Services\Llm\Dto\UsageRow;
use App\Services\Llm\Dto\UsageSignature;
use Carbon\CarbonImmutable;

/** Read-only physical-call facts. Never settles a request or changes a conservative reservation. */
final readonly class TrackingUsageSource implements UsageSource
{
    private const string SOURCE_ID = 'tracking';

    public function __construct(private LlmCallReadRepository $calls) {}

    public function usage(UsageQuery $query): UsageReport
    {
        $signature = $this->signature();

        $rows = array_map(
            static fn (array $row): UsageRow => new UsageRow(
                day: $row['day'] === null ? null : CarbonImmutable::parse($row['day']),
                provider: $row['provider'],
                model: $row['model'],
                purpose: $row['purpose'],
                calls: $row['calls'],
                promptTokens: $row['prompt_tokens'],
                cachedTokens: $row['cached_tokens'],
                completionTokens: $row['completion_tokens'],
                costMicros: $row['cost_micros'],
                unknownCalls: $row['unknown_calls'], reservedMicros: $row['reserved_micros'], currency: $query->currency,
            ),
            $this->calls->usageByAxes(from: $query->from, to: $query->to, axes: $query->axes(), currency: $query->currency),
        );

        return new UsageReport(
            rows: $rows,
            signature: $signature,
            beyondHorizon: false,
        );
    }

    /** Financial provenance is retained independently of ordinary log partitions. */
    private function signature(): UsageSignature
    {
        return new UsageSignature(self::SOURCE_ID, 'provider ledger (известные суммы; невыясненные usage/charge и резервы отдельно)',
            0, null, null, CarbonImmutable::now());
    }
}
