<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Llm\LlmSpendReporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Отчёт расхода LLM за окно (ТЗ 04 §6, Code Guidance P7.2): группировка `llm_calls` по
 * `(provider, model, purpose)` — tokens/cached_tokens/cost_micros → $ и ₽ (`config('ai.cost_report.
 * rub_per_usd')`); отдельная строка «экономия кэша» из `LlmSpendReporter`. Вывод — только
 * консольная таблица (Code Guidance: «без файлов»).
 */
final class CostReportCommand extends Command
{
    protected $signature = 'ai:cost-report {--days=7 : окно отчёта в днях}';

    protected $description = 'Расход LLM по provider/model/purpose за окно из llm_calls';

    public function __construct(private readonly LlmSpendReporter $reporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = CarbonImmutable::now()->subDays($days);

        $report = $this->reporter->report($since);

        if ($report->rows->isEmpty()) {
            $this->info("Вызовов LLM за последние {$days} дн. не найдено.");

            return self::SUCCESS;
        }

        $rubPerUsd = (float) config('ai.cost_report.rub_per_usd');

        $this->table(
            ['provider', 'model', 'purpose', 'calls', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'cost'],
            $report->rows->map(fn ($row): array => [
                $row->provider,
                $row->model,
                $row->purpose,
                $row->calls,
                $row->promptTokens,
                $row->cachedTokens,
                $row->completionTokens,
                $this->formatUsd($row->costMicros),
            ])->all(),
        );

        $this->line(sprintf(
            'Итого за %d дн.: %s (%s по курсу %.2f ₽/$, config(ai.cost_report.rub_per_usd))',
            $days,
            $this->formatUsd($report->totalCostMicros),
            $this->formatRub($report->totalCostMicros, $rubPerUsd),
            $rubPerUsd,
        ));
        $this->line(sprintf('Экономия кэша (cached_tokens × (input − cached) price): %s', $this->formatUsd($report->totalSavingsMicros)));

        return self::SUCCESS;
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
