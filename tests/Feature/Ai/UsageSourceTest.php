<?php

declare(strict_types=1);

use App\Enums\Llm\CoreLlmPurpose;
use App\Models\Llm\LlmCall;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\UsageQuery;
use Carbon\CarbonImmutable;

// Смещения во времени НЕБОЛЬШИЕ (сутки, часы): `llm_calls` партиционирована RANGE(called_at),
// далёкая дата ушла бы в несуществующую партицию. Тот же приём, что в `CostReportTest`.

test('дневная ось режет расход по календарным суткам, а не по скользящему окну', function (): void {
    $today = CarbonImmutable::now();
    $yesterday = $today->subDay();

    LlmCall::factory()->create(['cost_micros' => 1_000, 'called_at' => $yesterday]);
    LlmCall::factory()->create(['cost_micros' => 2_000, 'called_at' => $today]);
    LlmCall::factory()->create(['cost_micros' => 3_000, 'called_at' => $today]);

    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: $today->subDays(3),
        to: $today->addMinute(),
        byDay: true,
    ));

    expect($report->rows)->toHaveCount(2);

    [$first, $second] = $report->rows;

    expect($first->day?->toDateString())->toBe($yesterday->toDateString())
        ->and($first->calls)->toBe(1)
        ->and($first->costMicros)->toBe(1_000)
        ->and($second->day?->toDateString())->toBe($today->toDateString())
        ->and($second->calls)->toBe(2)
        ->and($second->costMicros)->toBe(5_000);
});

test('два вызова в ОДНИ сутки дают одну дневную строку', function (): void {
    $moment = CarbonImmutable::now();

    LlmCall::factory()->create(['cost_micros' => 1_000, 'called_at' => $moment]);
    LlmCall::factory()->create(['cost_micros' => 500, 'called_at' => $moment->subMinutes(10)]);

    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: $moment->subDay(),
        to: $moment->addMinute(),
        byDay: true,
    ));

    expect($report->rows)->toHaveCount(1)
        ->and($report->rows[0]->calls)->toBe(2)
        ->and($report->rows[0]->costMicros)->toBe(1_500);
});

test('окно полуинтервальное — from включительно, to исключительно', function (): void {
    // Целая секунда: `called_at` пишется в формате `Y-m-d H:i:s`, и сравнение на самой границе
    // имеет смысл только при таком же округлении с обеих сторон.
    $moment = CarbonImmutable::now()->subHours(2)->startOfSecond();

    LlmCall::factory()->create(['cost_micros' => 700, 'called_at' => $moment]);

    $source = app(UsageSource::class);

    $inclusive = $source->usage(new UsageQuery(from: $moment, to: $moment->addSecond(), byPurpose: true));
    $afterFrom = $source->usage(new UsageQuery(from: $moment->addSecond(), to: $moment->addMinute(), byPurpose: true));
    $upToMoment = $source->usage(new UsageQuery(from: $moment->subMinute(), to: $moment, byPurpose: true));

    expect($inclusive->rows[0]->costMicros)->toBe(700)
        ->and($afterFrom->rows)->toBe([])
        ->and($upToMoment->rows)->toBe([]);
});

test('ось, не выбранная запросом, приходит в строке как null', function (): void {
    LlmCall::factory()->create([
        'provider' => 'gemini',
        'model' => 'gemini-2.5-flash',
        'purpose' => CoreLlmPurpose::Screening->value,
        'called_at' => CarbonImmutable::now(),
    ]);

    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: CarbonImmutable::now()->subDay(),
        to: CarbonImmutable::now()->addMinute(),
        byPurpose: true,
    ));

    expect($report->rows[0]->purpose)->toBe(CoreLlmPurpose::Screening->value)
        ->and($report->rows[0]->provider)->toBeNull()
        ->and($report->rows[0]->model)->toBeNull()
        ->and($report->rows[0]->day)->toBeNull();
});

test('подпись приходит полем ответа — идентификатор, нулевой лаг и горизонт', function (): void {
    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: CarbonImmutable::now()->subDay(),
        to: CarbonImmutable::now(),
    ));

    expect($report->signature->sourceId)->toBe('tracking')
        ->and($report->signature->lagSeconds)->toBe(0)
        ->and($report->signature->toLine())->toContain('наш трекинг (llm_calls)');
});

test('горизонт подписи следует за конфигом retention, а не за литералом', function (): void {
    config(['partitioning.retention.llm_calls.months' => 3]);

    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: CarbonImmutable::now()->subDay(),
        to: CarbonImmutable::now(),
    ));

    expect($report->signature->horizonMonths)->toBe(3)
        ->and($report->signature->horizonFrom->toDateString())
        ->toBe(CarbonImmutable::now()->subMonths(3)->toDateString())
        ->and($report->signature->toLine())->toContain('горизонт 3 мес.');
});

test('запрос за пределом горизонта несёт явный признак, а не молча пустую серию', function (): void {
    config(['partitioning.retention.llm_calls.months' => 12]);

    $now = CarbonImmutable::now();

    LlmCall::factory()->create(['cost_micros' => 900, 'called_at' => $now]);

    $source = app(UsageSource::class);

    $beyond = $source->usage(new UsageQuery(from: $now->subYears(2), to: $now->addMinute(), byDay: true));
    $within = $source->usage(new UsageQuery(from: $now->subDay(), to: $now->addMinute(), byDay: true));

    // Признак поднят, но строки в пересечении окна с горизонтом всё равно возвращаются — ответ
    // честен и в части «что осталось», и в части «что могло быть удалено».
    expect($beyond->beyondHorizon)->toBeTrue()
        ->and($beyond->rows)->toHaveCount(1)
        ->and($beyond->rows[0]->costMicros)->toBe(900)
        ->and($within->beyondHorizon)->toBeFalse();
});

test('пустое окно — пустые строки и подпись на месте', function (): void {
    $report = app(UsageSource::class)->usage(new UsageQuery(
        from: CarbonImmutable::now()->subHour(),
        to: CarbonImmutable::now(),
        byDay: true,
        byProvider: true,
    ));

    expect($report->rows)->toBe([])
        ->and($report->beyondHorizon)->toBeFalse()
        ->and($report->signature->sourceId)->toBe('tracking');
});

test('запрос без осей — всегда ОДНА строка-итог, на пустом окне нулевая', function (): void {
    $now = CarbonImmutable::now();

    $totals = app(UsageSource::class)->usage(new UsageQuery(from: $now->subHour(), to: $now));

    // Контракт §1: пустой набор осей — законный запрос «итог за окно». Итог за окно без вызовов
    // равен нулю, а не отсутствует: потребителю не приходится отличать «ноль» от «нет строки».
    expect($totals->rows)->toHaveCount(1)
        ->and($totals->rows[0]->calls)->toBe(0)
        ->and($totals->rows[0]->costMicros)->toBe(0)
        ->and($totals->rows[0]->purpose)->toBeNull();
});
