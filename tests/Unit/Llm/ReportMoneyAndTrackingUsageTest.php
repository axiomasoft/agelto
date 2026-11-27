<?php

declare(strict_types=1);

use App\Dto\Llm\Reporting\UsageQuery;
use App\Dto\Llm\Reporting\UsageReport;
use App\Dto\Llm\Reporting\UsageRow;
use App\Dto\Llm\Reporting\UsageSignature;
use App\Services\Llm\ReportMoney;
use Carbon\CarbonImmutable;

test('ReportMoney форматирует микроединицы валюты с округлением HALF_UP до четырёх знаков и отвергает невалидные аргументы', function (): void {
    expect(ReportMoney::format(0, 'USD'))->toBe('$0.0000')
        ->and(ReportMoney::format(49, 'USD'))->toBe('$0.0000')
        ->and(ReportMoney::format(50, 'USD'))->toBe('$0.0001')
        ->and(ReportMoney::format(12_345_678, 'USD'))->toBe('$12.3457')
        ->and(ReportMoney::format(1_500_000, 'EUR'))->toBe('EUR 1.5000')
        ->and(ReportMoney::format(250_000, 'RUB'))->toBe('RUB 0.2500');

    foreach ([[-1, 'USD'], [100, ''], [100, 'usd'], [100, 'US'], [100, 'USDT'], [100, '12A']] as [$micros, $currency]) {
        expect(fn () => ReportMoney::format($micros, $currency))->toThrow(InvalidArgumentException::class);
    }
});

test('UsageQuery и UsageReport формируют неизменяемые DTO отчёта использования LLM', function (): void {
    $from = CarbonImmutable::parse('2026-05-01T00:00:00Z');
    $to = CarbonImmutable::parse('2026-05-15T00:00:00Z');
    $query = new UsageQuery(
        from: $from,
        to: $to,
        byDay: true,
        byProvider: true,
        byModel: false,
        byPurpose: true,
        currency: 'CHF',
    );
    $signature = new UsageSignature(
        sourceId: 'tracking',
        label: 'llm_provider_calls',
        lagSeconds: 0,
        horizonMonths: null,
        horizonFrom: null,
        asOf: $to,
    );
    $row = new UsageRow(
        day: $from,
        provider: 'gemini',
        model: null,
        purpose: 'generate',
        calls: 2,
        promptTokens: 100,
        cachedTokens: 20,
        completionTokens: 50,
        costMicros: 15_000,
        unknownCalls: 0,
        reservedMicros: 0,
        currency: 'CHF',
    );
    $report = new UsageReport(rows: [$row], signature: $signature, beyondHorizon: false);

    expect($query->currency)->toBe('CHF')
        ->and($query->byDay)->toBeTrue()
        ->and($query->byModel)->toBeFalse()
        ->and($report->beyondHorizon)->toBeFalse()
        ->and($report->signature->sourceId)->toBe('tracking')
        ->and($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->costMicros)->toBe(15_000);
});
