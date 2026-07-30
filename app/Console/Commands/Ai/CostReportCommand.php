<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Models\Llm\LlmCall;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Отчёт расхода LLM за окно (ТЗ 04 §6, Code Guidance P7.2): группировка `llm_calls` по
 * `(provider, model, purpose)` — tokens/cached_tokens/cost_micros → $ и ₽ (`config('ai.cost_report.
 * rub_per_usd')`); отдельная строка «экономия кэша» = `cached_tokens × (input_per_1m −
 * cached_per_1m)` по `config('ai.capabilities')` (индекс по модели БЕЗ dot-notation — тот же
 * приём, что `ExtractionPreflight`/`LlmCallRecorder::costMicros()`, имена моделей Gemini содержат
 * точки). Вывод — только консольная таблица (Code Guidance: «без файлов»).
 */
final class CostReportCommand extends Command
{
    protected $signature = 'ai:cost-report {--days=7 : окно отчёта в днях}';

    protected $description = 'Расход LLM по provider/model/purpose за окно из llm_calls';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->subDays($days);

        $rows = LlmCall::query()
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

        if ($rows->isEmpty()) {
            $this->info("Вызовов LLM за последние {$days} дн. не найдено.");

            return self::SUCCESS;
        }

        $rubPerUsd = (float) config('ai.cost_report.rub_per_usd');
        $capabilities = (array) config('ai.capabilities', []);

        $table = [];
        $totalCostMicros = 0;
        $totalSavingsMicros = 0;

        foreach ($rows as $row) {
            $model = (string) $row->model;
            $cachedTokens = (int) $row->cached_tokens;
            $costMicros = (int) $row->cost_micros;
            $savingsMicros = $this->cacheSavingsMicros($capabilities, $model, $cachedTokens);

            $totalCostMicros += $costMicros;
            $totalSavingsMicros += $savingsMicros;

            $table[] = [
                $row->provider,
                $model,
                $row->purpose,
                (int) $row->calls,
                (int) $row->prompt_tokens,
                $cachedTokens,
                (int) $row->completion_tokens,
                $this->formatUsd($costMicros),
            ];
        }

        $this->table(
            ['provider', 'model', 'purpose', 'calls', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'cost'],
            $table,
        );

        $this->line(sprintf(
            'Итого за %d дн.: %s (%s по курсу %.2f ₽/$, config(ai.cost_report.rub_per_usd))',
            $days,
            $this->formatUsd($totalCostMicros),
            $this->formatRub($totalCostMicros, $rubPerUsd),
            $rubPerUsd,
        ));
        $this->line(sprintf('Экономия кэша (cached_tokens × (input − cached) price): %s', $this->formatUsd($totalSavingsMicros)));

        return self::SUCCESS;
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

    private function formatUsd(int $micros): string
    {
        return sprintf('$%.4f', $micros / 1_000_000);
    }

    private function formatRub(int $micros, float $rubPerUsd): string
    {
        return sprintf('%.2f ₽', ($micros / 1_000_000) * $rubPerUsd);
    }
}
