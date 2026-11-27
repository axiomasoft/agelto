<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Dto\Llm\Reporting\UsageRow;
use App\Services\Llm\LlmSpendReporter;
use App\Services\Llm\ReportMoney;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Physical provider ledger report: settled costs, unknown usage/charge and retained reserves. */
#[Description('Учтённая стоимость и незакрытые резервы provider ledger')]
#[Signature('ai:cost-report {--days=7 : окно отчёта в днях} {--currency=USD : валюта provider accounts}')]
final class CostReportCommand extends Command
{
    private string $currency = 'USD';

    public function __construct(private readonly LlmSpendReporter $reporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $to = CarbonImmutable::now();
        $since = $to->subDays($days);

        $currency = $this->option('currency');
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            $this->error('Валюта должна содержать три заглавные латинские буквы.');

            return self::FAILURE;
        }
        $this->currency = $currency;
        $report = $this->reporter->report(from: $since, to: $to, currency: $currency);

        if ($report->rows->isEmpty()) {
            $this->info("Вызовов LLM за последние {$days} дн. не найдено.");
            $this->line($report->signature->toLine());
            $this->warnBeyondHorizon($report->beyondHorizon, $report->signature->horizonMonths);

            return self::SUCCESS;
        }

        $rubPerUsd = config('ai.cost_report.rub_per_usd');

        if ($currency === 'USD' && ! is_float($rubPerUsd) && ! is_int($rubPerUsd)) {
            throw new \UnexpectedValueException('ai.cost_report.rub_per_usd must be numeric.');
        }

        $this->table(
            ['provider', 'model', 'purpose', 'calls', 'prompt_tokens', 'cached_tokens', 'completion_tokens', 'cost '.$currency, 'unknown usage/charge', 'reserved '.$currency],
            $report->rows->map(fn ($row): array => [
                $row->provider,
                $row->model,
                $row->purpose,
                $row->calls,
                $row->promptTokens,
                $row->cachedTokens,
                $row->completionTokens,
                $this->formatAmount($row->costMicros),
                $row->unknownCalls,
                $this->formatAmount($row->reservedMicros),
            ])->all(),
        );

        $this->line(sprintf('Учтено за %d дн.: %s', $days, $this->formatAmount($report->totalCostMicros)));
        if ($currency === 'USD' && (is_int($rubPerUsd) || is_float($rubPerUsd))) {
            $this->line(sprintf('%s по курсу %.2f ₽/$, config(ai.cost_report.rub_per_usd)',
                $this->formatRub($report->totalCostMicros, $rubPerUsd), $rubPerUsd));
        }
        $this->line('Экономия кэша: цена сравнения не зафиксирована.');
        if ($report->unknownCalls > 0 || $report->reservedMicros > 0) {
            $this->warn(sprintf('Неуточнённые usage/charge: %d; остаётся зарезервировано %s.',
                $report->unknownCalls, $this->formatAmount($report->reservedMicros)));
        }

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
        $this->line('Расход по календарным дням (пояс '.$this->timezone().'):');
        $this->table(
            ['day', 'provider', 'model', 'calls', 'cost '.$this->currency, 'unknown usage/charge', 'reserved '.$this->currency],
            array_map(fn (UsageRow $row): array => [
                $row->day?->toDateString(),
                $row->provider,
                $row->model,
                $row->calls,
                $this->formatAmount($row->costMicros),
                $row->unknownCalls,
                $this->formatAmount($row->reservedMicros),
            ], $dailyRows),
        );
    }

    /**
     * Запрос за пределом горизонта хранения не отвечает молча пустой серией (контракт §4): часть
     * окна старше retention уехала вместе с партициями, и это говорится словами.
     */
    private function warnBeyondHorizon(bool $beyondHorizon, ?int $horizonMonths): void
    {
        if (! $beyondHorizon) {
            return;
        }

        $this->warn(sprintf(
            'Часть окна за горизонтом хранения (%d мес.): данных за эти дни нет — партиции удалены.',
            $horizonMonths ?? throw new \LogicException('Retention cutoff is absent.'),
        ));
    }

    private function formatAmount(int $micros): string
    {
        return ReportMoney::format($micros, $this->currency);
    }

    private function formatRub(int $micros, float $rubPerUsd): string
    {
        $rate = (string) $rubPerUsd;
        if (! is_numeric($rate)) {
            throw new \UnexpectedValueException('ai.cost_report.rub_per_usd must be numeric.');
        }

        $rub = bcmul(num1: bcdiv(num1: (string) $micros, num2: '1000000', scale: 8), num2: $rate, scale: 4);
        $rounded = bcdiv(bcadd($rub, '0.005', 4), '1', 2);

        return $rounded.' ₽';
    }

    private function timezone(): string
    {
        $timezone = config('app.timezone');

        if (! is_string($timezone)) {
            throw new \UnexpectedValueException('app.timezone must be a string.');
        }

        return $timezone;
    }
}
