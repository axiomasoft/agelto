<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\LlmSpendReport;
use App\Services\Llm\Dto\LlmSpendRow;
use App\Services\Llm\Dto\UsageQuery;
use App\Services\Llm\Dto\UsageRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Расход `llm_calls` за окно `[from, to)` для `ai:cost-report`: строки берутся у источника
 * (`UsageSource`, контракт `root/usage-source-contract.md`), а деньги-производные — экономия
 * explicit-кэша по `config('ai.capabilities')` — считаются ЗДЕСЬ (D124: источник отдаёт токены и
 * `cost_micros`, прайсинг остаётся у потребителя). Индекс по модели БЕЗ dot-notation — тот же
 * приём, что `ExtractionPreflight`/`LlmCallRecorder::costMicros()`, имена моделей Gemini содержат
 * точки.
 */
final readonly class LlmSpendReporter
{
    public function __construct(private UsageSource $source) {}

    public function report(CarbonInterface $from, CarbonInterface $to): LlmSpendReport
    {
        $configuredCapabilities = config('ai.capabilities', []);
        $capabilities = $this->stringKeyedArray($configuredCapabilities);

        // Два запроса к ОДНОМУ методу источника с разными осями: старая таблица (провайдер ×
        // модель × назначение) и АДДИТИВНАЯ дневная секция (D124/D38) — заменять первую второй
        // нельзя, свёртка календарных суток не воспроизводит скользящее окно.
        $report = $this->source->usage(new UsageQuery(
            from: $from,
            to: $to,
            byProvider: true,
            byModel: true,
            byPurpose: true,
        ));

        $daily = $this->source->usage(new UsageQuery(
            from: $from,
            to: $to,
            byDay: true,
            byProvider: true,
            byModel: true,
        ));

        /** @var Collection<int, LlmSpendRow> $rows */
        $rows = Collection::make($report->rows)->map(
            fn (UsageRow $row): LlmSpendRow => new LlmSpendRow(
                provider: $row->provider ?? '',
                model: $row->model ?? '',
                purpose: $row->purpose ?? '',
                calls: $row->calls,
                promptTokens: $row->promptTokens,
                cachedTokens: $row->cachedTokens,
                completionTokens: $row->completionTokens,
                costMicros: $row->costMicros,
                savingsMicros: $this->cacheSavingsMicros($capabilities, $row->model ?? '', $row->cachedTokens),
            ),
        );

        return new LlmSpendReport(
            rows: $rows,
            totalCostMicros: $rows->reduce(static fn (int $total, LlmSpendRow $row): int => $total + $row->costMicros, initial: 0),
            totalSavingsMicros: $rows->reduce(static fn (int $total, LlmSpendRow $row): int => $total + $row->savingsMicros, initial: 0),
            dailyRows: $daily->rows,
            signature: $report->signature,
            beyondHorizon: $report->beyondHorizon,
        );
    }

    /**
     * @param  array<string, mixed>  $capabilities
     */
    private function cacheSavingsMicros(array $capabilities, string $model, int $cachedTokens): int
    {
        $pricing = $capabilities[$model] ?? null;

        if (! is_array($pricing) || $cachedTokens <= 0) {
            return 0;
        }

        $inputPrice = $this->number($pricing['input_per_1m'] ?? null);
        $cachedPrice = $this->number($pricing['cached_per_1m'] ?? null);

        if ($inputPrice === null || $cachedPrice === null) {
            return 0;
        }

        $deltaPerMillion = $inputPrice - $cachedPrice;

        return (int) ceil($cachedTokens * max(0.0, $deltaPerMillion));
    }

    private function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || is_string($value) ? (float) $value : null;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        foreach ($value as $key => $_) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
