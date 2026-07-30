<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Services\Llm\Dto\UsageRow;
use App\Services\Llm\LlmSpendReporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Отчёт расхода LLM за окно (ТЗ 04 §6, Code Guidance P7.2): группировка `llm_calls` по
 * `(provider, model, purpose)` — tokens/cached_tokens/cost_micros → $ и ₽ (`config('ai.cost_report.
 * rub_per_usd')`); отдельная строка «экономия кэша» из `LlmSpendReporter`. Ниже — АДДИТИВНАЯ
 * дневная секция и подпись источника (D124/D38, контракт `root/usage-source-contract.md`): старая
 * таблица и «Итого» остаются как были, дневной разрез добавляется, а не заменяет их. Вывод —
 * только консольная таблица (Code Guidance: «без файлов»).
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
        $to = CarbonImmutable::now();
        $since = $to->subDays($days);

        $report = $this->reporter->report(from: $since, to: $to);

        if ($report->rows->isEmpty()) {
            $this->info("Вызовов LLM за последние {$days} дн. не найдено.");
            $this->line($report->signature->toLine());
            $this->warnBeyondHorizon($report->beyondHorizon, $report->signature->horizonMonths);

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

        $this->renderDaily($report->dailyRows);
        $this->line($report->signature->toLine());
        $this->warnBeyondHorizon($report->beyondHorizon, $report->signature->horizonMonths);

        return self::SUCCESS;
    }

    /**
     * @param  list<UsageRow>  $dailyRows
     */
    private function renderDaily(array $dailyRows): void
    {
        if ($dailyRows === []) {
            return;
        }

        $this->newLine();
        $this->line('Расход по календарным дням (пояс '.config('app.timezone').'):');
        $this->table(
            ['day', 'provider', 'model', 'calls', 'cost'],
            array_map(fn (UsageRow $row): array => [
                $row->day?->toDateString(),
                $row->provider,
                $row->model,
                $row->calls,
                $this->formatUsd($row->costMicros),
            ], $dailyRows),
        );
    }

    /**
     * Запрос за пределом горизонта хранения не отвечает молча пустой серией (контракт §4): часть
     * окна старше retention уехала вместе с партициями, и это говорится словами.
     */
    private function warnBeyondHorizon(bool $beyondHorizon, int $horizonMonths): void
    {
        if (! $beyondHorizon) {
            return;
        }

        $this->warn(sprintf(
            'Часть окна за горизонтом хранения (%d мес.): данных за эти дни нет — партиции удалены.',
            $horizonMonths,
        ));
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
