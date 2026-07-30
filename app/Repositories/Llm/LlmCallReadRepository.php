<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Enums\Llm\LlmPurpose;
use App\Models\Llm\LlmCall;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Чтение `llm_calls` для отчётности (`ai:cost-report`, `root/service-layer-map.md` §6).
 */
final readonly class LlmCallReadRepository
{
    /**
     * Оси, по которым репозиторий умеет резать агрегат расхода. Список закрыт: имена осей уезжают
     * в `GROUP BY`, и белый список — единственное, что отделяет их от произвольной строки.
     *
     * @var list<string>
     */
    private const AXES = ['day', 'provider', 'model', 'purpose'];

    /**
     * Слепок расхода одного анализа (P4.1, D5/D39): сумма ТОЛЬКО вызовов из `$purposes` —
     * принадлежность назначения шагу резолвит вызывающий (`UsageSnapshotRecorder`) через
     * `DocumentAnalyzerRegistry`, сюда приходит уже готовый список, литерального фильтра здесь
     * нет. `coalesce` — анализ без единого вызова не должен превращать сумму в `NULL`.
     *
     * @param  list<LlmPurpose>  $purposes
     * @return array{prompt_tokens: int, completion_tokens: int, cached_tokens: int, total_tokens: int, cost_micros: int}
     */
    public function usageSummary(int $analysisId, array $purposes): array
    {
        $values = array_map(static fn (LlmPurpose $purpose): string => $purpose->value, $purposes);

        /** @var object{prompt_tokens: numeric-string, completion_tokens: numeric-string, cached_tokens: numeric-string, total_tokens: numeric-string, cost_micros: numeric-string} $row */
        $row = LlmCall::query()
            ->where('analysis_id', $analysisId)
            ->whereIn('purpose', $values)
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as completion_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->selectRaw('coalesce(sum(total_tokens), 0) as total_tokens')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost_micros')
            ->first();

        return [
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'cached_tokens' => (int) $row->cached_tokens,
            'total_tokens' => (int) $row->total_tokens,
            'cost_micros' => (int) $row->cost_micros,
        ];
    }

    /**
     * Агрегат расхода за полуинтервал `[$from, $to)` в разрезе выбранных осей — единственный
     * запрос под контракт источника (`root/usage-source-contract.md` §1, D124). Семантика сумм
     * та же, что у прежнего `spendByProviderModelPurpose()`: `selectRaw`-агрегаты не переписаны,
     * фильтра по успешности вызова нет (отказы входят с нулевой ценой).
     *
     * Ось `day` — КАЛЕНДАРНЫЙ день в поясе приложения (`config('app.timezone')`, контракт §3):
     * `called_at` хранится как `timestamptz`, поэтому пояс применяется к моменту времени, а не к
     * «сырой» строке. Группировка идёт по ДИАПАЗОНУ `called_at` → работает партиционный pruning,
     * нового индекса разрез по дням не требует.
     *
     * Возврат — ТИПИЗИРОВАННЫЕ строки (тот же приём, что `usageSummary()`), а не модели с
     * агрегатами в динамических свойствах: агрегат не является колонкой `LlmCall`, и его чтение
     * через модель порождает записи в `phpstan-baseline.neon` (D125).
     *
     * @param  list<string>  $axes  подмножество `day`/`provider`/`model`/`purpose`
     * @return list<array{day: string|null, provider: string|null, model: string|null, purpose: string|null, calls: int, prompt_tokens: int, cached_tokens: int, completion_tokens: int, cost_micros: int}>
     */
    public function usageByAxes(CarbonInterface $from, CarbonInterface $to, array $axes): array
    {
        $unknown = array_diff($axes, self::AXES);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Неизвестная ось агрегата расхода: '.implode(', ', $unknown));
        }

        $timezone = (string) config('app.timezone');
        $dayExpression = '(called_at AT TIME ZONE CAST(? AS text))::date';

        // Границы окна биндятся С МИКРОСЕКУНДАМИ: грамматика запроса форматирует объект даты как
        // `Y-m-d H:i:s`, и тогда исключающая верхняя граница `to = now()` отрезала бы ВСЕ вызовы
        // текущей секунды (`called_at` хранится с точностью до секунды — тот же формат на записи).
        $query = LlmCall::query()
            ->toBase()
            ->where('called_at', '>=', $from->format('Y-m-d H:i:s.u'))
            ->where('called_at', '<', $to->format('Y-m-d H:i:s.u'))
            ->selectRaw('count(*) as calls')
            ->selectRaw('coalesce(sum(prompt_tokens), 0) as prompt_tokens')
            ->selectRaw('coalesce(sum(cached_tokens), 0) as cached_tokens')
            ->selectRaw('coalesce(sum(completion_tokens), 0) as completion_tokens')
            ->selectRaw('coalesce(sum(cost_micros), 0) as cost_micros');

        foreach ($axes as $axis) {
            if ($axis === 'day') {
                // Группировка и сортировка идут по ИМЕНИ выходной колонки, а не по повторённому
                // выражению: параметризованное выражение с другим плейсхолдером Postgres не
                // признаёт тем же самым («column called_at must appear in the GROUP BY clause»).
                $query->selectRaw($dayExpression.' as day', [$timezone])
                    ->groupBy('day')
                    ->orderBy('day');

                continue;
            }

            $query->addSelect($axis)->groupBy($axis)->orderBy($axis);
        }

        $rows = [];

        foreach ($query->get() as $row) {
            /** @var array<string, scalar|null> $values */
            $values = (array) $row;

            $rows[] = [
                'day' => isset($values['day']) ? (string) $values['day'] : null,
                'provider' => isset($values['provider']) ? (string) $values['provider'] : null,
                'model' => isset($values['model']) ? (string) $values['model'] : null,
                'purpose' => isset($values['purpose']) ? (string) $values['purpose'] : null,
                'calls' => (int) ($values['calls'] ?? 0),
                'prompt_tokens' => (int) ($values['prompt_tokens'] ?? 0),
                'cached_tokens' => (int) ($values['cached_tokens'] ?? 0),
                'completion_tokens' => (int) ($values['completion_tokens'] ?? 0),
                'cost_micros' => (int) ($values['cost_micros'] ?? 0),
            ];
        }

        return $rows;
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

    /**
     * Вызовы одного прогона для витрины финального промта (P5.5): ОБА шага (screening +
     * extraction) — ось назначения берётся из данных, литерального списка назначений здесь нет
     * (Implementation Rule 6 P5.5, D39 — после расщепления назначений ядро их не знает).
     *
     * @return Builder<LlmCall>
     */
    public function forRun(int $analysisRunId): Builder
    {
        return LlmCall::query()
            ->where('analysis_run_id', $analysisRunId)
            ->orderBy('called_at');
    }

    /**
     * Один вызов прогона по идентичности, СКОУПЛЕННЫЙ по `analysisRunId` (P5.5): выбор вызова на
     * экране не должен резолвиться в вызов чужого прогона по одному лишь id.
     */
    public function findInRun(int $callId, int $analysisRunId): ?LlmCall
    {
        return LlmCall::query()
            ->where('analysis_run_id', $analysisRunId)
            ->find($callId);
    }
}
