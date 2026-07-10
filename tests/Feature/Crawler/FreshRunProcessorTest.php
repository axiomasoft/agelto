<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Enums\Procurement\RunStatus;
use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\FreshRunProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * Одна валидная строка блока (набор колонок, которые понимает `EisCsvRowMapper`) —
 * фикстура оверрайдится под сценарий по `Реестровый номер закупки`.
 *
 * @return array<string, string>
 */
function freshRunCsvRow(string $registryNumber, array $overrides = []): array
{
    return array_merge([
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'НМЦК' => '1 250 000,50',
        'Валюта' => 'RUB',
        'ОКПД2' => '26.20.11.110',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
    ], $overrides);
}

/**
 * @param  list<array<string, string>>  $rows
 */
function freshRunBatch(array $rows, ?string $dedupSignature, string $from = '1'): ListingBatch
{
    return new ListingBatch(
        rows: $rows,
        rawBody: 'raw csv body for batch from='.$from,
        dedupSignature: $dedupSignature,
        rowsTotal: count($rows),
        httpStatus: 200,
        durationMs: 42,
        requestParams: ['from' => $from, 'to' => (string) ((int) $from + 499)],
    );
}

/**
 * @param  list<ListingBatch>  $batches
 */
function useQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
});

test('останавливается на первом полностью известном блоке, курсор фиксирует reachedKnown', function () {
    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([freshRunCsvRow('0173200001234000001'), freshRunCsvRow('0173200001234000002')], dedupSignature: 'sig-1'),
        freshRunBatch([freshRunCsvRow('0173200001234000003')], dedupSignature: 'sig-2'),
        freshRunBatch([], dedupSignature: null),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->cursor['reachedKnown'])->toBeTrue()
        ->and($run->cursor['blockFrom'])->toBe(1001)
        ->and(Procurement::query()->count())->toBe(3);
});

test('cap по повтору dedup_signature поднимает флаг и завершает прогон', function () {
    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([freshRunCsvRow('0173200001234000010')], dedupSignature: 'dup-signature'),
        freshRunBatch([freshRunCsvRow('0173200001234000011')], dedupSignature: 'dup-signature'),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->cursor['reachedKnown'])->toBeTrue()
        ->and($run->stats['cap_hit'] ?? null)->toBeTrue()
        ->and($run->stats['possible_skip_window']['from'] ?? null)->not->toBeNull()
        ->and($run->stats['possible_skip_window']['to'] ?? null)->not->toBeNull();
});

test('повторный запуск на том же профиле после completed создаёт новый Run, не портит прежний', function () {
    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([freshRunCsvRow('0173200001234000020')], dedupSignature: 'sig-a'),
        freshRunBatch([], dedupSignature: null),
    ]);

    $firstRun = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect(Run::query()->count())->toBe(1);

    useQueueSourceMethod([
        freshRunBatch([], dedupSignature: null),
    ]);

    $secondRun = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($secondRun->id)->not->toBe($firstRun->id)
        ->and(Run::query()->count())->toBe(2)
        ->and($secondRun->status)->toBe(RunStatus::Completed)
        ->and($firstRun->fresh()->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});

test('safety hard-cap останавливает бесконечный цикл без стоп-условий, прогон завершается completed', function () {
    config(['crawler.fresh_max_blocks' => 2]);

    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([freshRunCsvRow('0173200001234000030')], dedupSignature: 'block-1'),
        freshRunBatch([freshRunCsvRow('0173200001234000031')], dedupSignature: 'block-2'),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->cursor['reachedKnown'])->toBeFalse()
        ->and(Procurement::query()->count())->toBe(2);
});
