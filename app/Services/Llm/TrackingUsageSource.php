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

/**
 * «Наш трекинг» — единственная сегодня реализация источника расхода
 * (`root/usage-source-contract.md`, D34): агрегат `llm_calls` в пределах retention.
 *
 * Границу с P4.1 держит контракт §6: слепок на анализе отвечает «сколько стоил ЭТОТ документ» и
 * живёт вечно, источник отвечает «сколько мы тратили в эти дни» и живёт в пределах горизонта;
 * пересчёта из одного в другой нет ни в одну сторону. Чтение — только через репозиторий,
 * собственного `selectRaw` источник не содержит.
 */
final readonly class TrackingUsageSource implements UsageSource
{
    private const SOURCE_ID = 'tracking';

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
            ),
            $this->calls->usageByAxes(from: $query->from, to: $query->to, axes: $query->axes()),
        );

        return new UsageReport(
            rows: $rows,
            signature: $signature,
            beyondHorizon: $query->from->lessThan($signature->horizonFrom),
        );
    }

    /**
     * Подпись вычисляется, а не вписывается (контракт §2): горизонт — из конфига retention, лаг —
     * ноль ПО ПОСТРОЕНИЮ (цена считается и пишется в момент самого вызова, `LlmCallRecorder`;
     * между фактом расхода и строкой в `llm_calls` нет ни выгрузки, ни пересчёта).
     */
    private function signature(): UsageSignature
    {
        $configuredMonths = config('partitioning.retention.llm_calls.months');
        $horizonMonths = is_int($configuredMonths) || is_float($configuredMonths) || is_string($configuredMonths)
            ? (int) $configuredMonths
            : 0;
        $asOf = CarbonImmutable::now();

        return new UsageSignature(
            sourceId: self::SOURCE_ID,
            label: 'наш трекинг (llm_calls)',
            lagSeconds: 0,
            horizonMonths: $horizonMonths,
            horizonFrom: $asOf->subMonths($horizonMonths),
            asOf: $asOf,
        );
    }
}
