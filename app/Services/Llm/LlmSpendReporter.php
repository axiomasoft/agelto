<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Llm\LlmCall;
use App\Repositories\Llm\LlmCallReadRepository;
use App\Services\Llm\Dto\LlmSpendReport;
use App\Services\Llm\Dto\LlmSpendRow;
use Carbon\CarbonInterface;

/**
 * Агрегат расхода `llm_calls` за окно + экономия explicit-кэша по `config('ai.capabilities')`
 * (`ai:cost-report`, `root/service-layer-map.md` §3). Индекс по модели БЕЗ dot-notation — тот же
 * приём, что `ExtractionPreflight`/`LlmCallRecorder::costMicros()`, имена моделей Gemini содержат
 * точки.
 */
final readonly class LlmSpendReporter
{
    public function __construct(private LlmCallReadRepository $calls) {}

    public function report(CarbonInterface $since): LlmSpendReport
    {
        $capabilities = (array) config('ai.capabilities', []);

        $rows = $this->calls->spendByProviderModelPurpose($since)->map(
            function (LlmCall $row) use ($capabilities): LlmSpendRow {
                $model = (string) $row->model;
                $cachedTokens = (int) $row->cached_tokens;
                $costMicros = (int) $row->cost_micros;

                return new LlmSpendRow(
                    provider: (string) $row->provider,
                    model: $model,
                    purpose: (string) $row->purpose,
                    calls: (int) $row->calls,
                    promptTokens: (int) $row->prompt_tokens,
                    cachedTokens: $cachedTokens,
                    completionTokens: (int) $row->completion_tokens,
                    costMicros: $costMicros,
                    savingsMicros: $this->cacheSavingsMicros($capabilities, $model, $cachedTokens),
                );
            },
        );

        return new LlmSpendReport(
            rows: $rows,
            totalCostMicros: (int) $rows->sum('costMicros'),
            totalSavingsMicros: (int) $rows->sum('savingsMicros'),
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

        $deltaPerMillion = (float) $pricing['input_per_1m'] - (float) $pricing['cached_per_1m'];

        return (int) ceil($cachedTokens * max(0.0, $deltaPerMillion));
    }
}
