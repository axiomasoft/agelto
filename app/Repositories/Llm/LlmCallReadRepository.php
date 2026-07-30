<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\LlmCall;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Чтение `llm_calls` для отчётности (`ai:cost-report`, `root/service-layer-map.md` §6).
 */
final readonly class LlmCallReadRepository
{
    /**
     * Агрегат по `(provider, model, purpose)` за окно от `$since` — `selectRaw` переносится
     * БЕЗ переписывания (`CostReportCommand:30`, семантика сумм подтверждена тестом). Строки —
     * инстансы `LlmCall` только с выбранными колонками (`selectRaw`-агрегаты как динамические
     * свойства, тот же приём, что `LlmCacheManager::implicitHitStats()`).
     *
     * @return Collection<int, LlmCall>
     */
    public function spendByProviderModelPurpose(CarbonInterface $since): Collection
    {
        return LlmCall::query()
            ->selectRaw('provider, model, purpose')
            ->selectRaw('count(*) as calls')
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as completion_tokens')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost_micros')
            ->where('called_at', '>=', $since)
            ->groupBy('provider', 'model', 'purpose')
            ->orderBy('provider')
            ->orderBy('model')
            ->orderBy('purpose')
            ->get();
    }

    /**
     * Вызовы обработанных примеров прогона, случившиеся ПОСЛЕ его старта (`estimate:eval:run` —
     * `resolvePromptVersionSet()`, тот же `Analysis` мог обслужить предыдущий прогон, старые
     * вызовы не в счёт).
     *
     * @param  list<int>  $analysisIds
     * @return Collection<int, LlmCall>
     */
    public function forAnalysesSince(array $analysisIds, CarbonInterface $since): Collection
    {
        return LlmCall::query()
            ->whereIn('analysis_id', $analysisIds)
            ->where('called_at', '>=', $since)
            ->get();
    }

    /**
     * Версии промтов, реально участвовавших в вызовах прогона (тот же `Analysis` мог обслужить
     * предыдущий `estimate:eval:run`, старые вызовы не в счёт). Расхождение версии одного ключа
     * МЕЖДУ вызовами прогона делает прогон непригодным для сравнения — `null` здесь читается
     * `estimate:eval:compare` как отказ, неотличимый от «вызовов не было» (оба исхода одинаково
     * непригодны для критерия приёмки). Общий для `ExtractionEvalRunner` и `ScreeningEvalRunner`
     * (Implementation Rule 7 P3.1 — дубль устраняется вверх, не вбок).
     *
     * @param  list<int>  $analysisIds
     * @return array<string, int>|null
     */
    public function resolvePromptVersionSet(array $analysisIds, CarbonInterface $since): ?array
    {
        if ($analysisIds === []) {
            return null;
        }

        $calls = $this->forAnalysesSince($analysisIds, $since);

        if ($calls->isEmpty()) {
            return null;
        }

        $merged = [];

        foreach ($calls as $call) {
            foreach ($call->prompt_version_set?->all() ?? [] as $key => $version) {
                if (array_key_exists($key, $merged) && $merged[$key] !== $version) {
                    return null;
                }

                $merged[$key] = $version;
            }
        }

        return $merged;
    }
}
