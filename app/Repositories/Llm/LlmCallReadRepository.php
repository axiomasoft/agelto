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
}
