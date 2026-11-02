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

/** Known settled amounts and still-reserved exposure, in one explicit currency. */
final readonly class LlmSpendReporter
{
    public function __construct(private UsageSource $source) {}

    public function report(CarbonInterface $from, CarbonInterface $to, string $currency = 'USD'): LlmSpendReport
    {
        $report = $this->source->usage(new UsageQuery($from, $to, byProvider: true, byModel: true, byPurpose: true, currency: $currency));
        $daily = $this->source->usage(new UsageQuery($from, $to, byDay: true, byProvider: true, byModel: true, currency: $currency));
        foreach ([...$report->rows, ...$daily->rows] as $row) {
            if ($row->currency !== $currency) {
                throw new \LogicException('Usage source returned a different currency.');
            }
        }
        /** @var Collection<int,LlmSpendRow> $rows */
        $rows = Collection::make($report->rows)->map(static fn (UsageRow $row): LlmSpendRow => new LlmSpendRow(
            $row->provider ?? '', $row->model ?? '', $row->purpose ?? '', $row->calls, $row->promptTokens,
            $row->cachedTokens, $row->completionTokens, $row->costMicros,
            null, $row->unknownCalls, $row->reservedMicros));

        // Current config prices cannot establish a historical counterfactual cache saving.
        return new LlmSpendReport($rows,
            $rows->reduce(static fn (int $total, LlmSpendRow $row): int => self::add($total, $row->costMicros), 0),
            null, $daily->rows, $report->signature, $report->beyondHorizon, $currency,
            $rows->reduce(static fn (int $total, LlmSpendRow $row): int => self::add($total, $row->unknownCalls), 0),
            $rows->reduce(static fn (int $total, LlmSpendRow $row): int => self::add($total, $row->reservedMicros), 0));
    }

    private static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $right > PHP_INT_MAX - $left) {
            throw new \OverflowException('Usage reporting exceeds bounded integer microcurrency format. Exact ledger facts remain available.');
        }

        return $left + $right;
    }
}
