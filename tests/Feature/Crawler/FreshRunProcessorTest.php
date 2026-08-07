<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\RunStopPolicy;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\FreshRunProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
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

afterEach(function () {
    Carbon::setTestNow();
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
        ->and($run->stats['stop_reason'] ?? null)->toBe('no_new_or_updated_rows')
        ->and(Procurement::query()->count())->toBe(3);
});

test('метрика отставания: первый скан профиля пишет oldest_update_seen, scan_gap_seconds — null', function () {
    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([
            freshRunCsvRow('0173200001234000040'),
            freshRunCsvRow('0173200001234000041', ['Дата обновления' => '01.03.2026 08:00:00']),
        ], dedupSignature: 'sig-metrics'),
        freshRunBatch([], dedupSignature: null),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect(array_key_exists('scan_gap_seconds', $run->stats->toArray()))->toBeTrue()
        ->and($run->stats['scan_gap_seconds'])->toBeNull()
        ->and($run->stats['oldest_update_seen'])->not->toBeNull()
        ->and(Carbon::parse($run->stats['oldest_update_seen'])->toDateString())->toBe('2026-03-01');
});

test('метрика отставания: повторный скан профиля пишет ненулевой scan_gap_seconds', function () {
    $crawler = Crawler::factory()->create();

    useQueueSourceMethod([
        freshRunBatch([freshRunCsvRow('0173200001234000050')], dedupSignature: 'sig-first'),
        freshRunBatch([], dedupSignature: null),
    ]);
    app(FreshRunProcessor::class)->run(crawler: $crawler);

    useQueueSourceMethod([
        freshRunBatch([], dedupSignature: null),
    ]);
    $secondRun = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($secondRun->stats['scan_gap_seconds'] ?? null)->not->toBeNull();
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
        ->and($run->stats['possible_skip_window']['to'] ?? null)->not->toBeNull()
        // stop_reason не затирает соседние ключи, записанные handleCapHit() раньше в том же стеке.
        ->and($run->stats['stop_reason'] ?? null)->toBe('dedup_signature_repeat');
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

test('политика only_fresh: блок с НОВЫМИ строками за порогом старта предыдущего прогона останавливает прогон', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');

    $crawler = Crawler::factory()->onlyFresh()->create();
    Run::factory()->create([
        'crawler_id' => $crawler->id,
        'status' => RunStatus::Completed,
        'started_at' => Carbon::parse('2026-03-10 10:00:00'),
        'finished_at' => Carbon::parse('2026-03-10 10:30:00'),
    ]);

    // Дефолтный запас — 60 минут: порог = 10:00 - 60мин = 09:00. Строка «за порогом»
    // (09:00 ровно), но реестровый номер НОВЫЙ — доказывает именно OnlyFresh, а не
    // старое условие «блок без изменений» (anti-self-deception, Code Guidance).
    useQueueSourceMethod([
        freshRunBatch(
            [freshRunCsvRow('0173200001234000060', ['Дата обновления' => '10.03.2026 09:00:00'])],
            dedupSignature: 'sig-only-fresh',
        ),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->cursor['reachedKnown'])->toBeTrue()
        ->and($run->stats['stop_reason'] ?? null)->toBe('reached_previous_run')
        ->and($run->stats['stop_threshold_at'] ?? null)->not->toBeNull()
        ->and(Carbon::parse($run->stats['stop_threshold_at']))->toEqual(Carbon::parse('2026-03-10 09:00:00'))
        ->and(Procurement::query()->count())->toBe(1);
});

test('политика default: тот же блок за временны́м порогом прогон НЕ останавливает', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');

    $crawler = Crawler::factory()->create(['stop_policy' => RunStopPolicy::Default]);
    Run::factory()->create([
        'crawler_id' => $crawler->id,
        'status' => RunStatus::Completed,
        'started_at' => Carbon::parse('2026-03-10 10:00:00'),
        'finished_at' => Carbon::parse('2026-03-10 10:30:00'),
    ]);

    useQueueSourceMethod([
        freshRunBatch(
            [freshRunCsvRow('0173200001234000061', ['Дата обновления' => '10.03.2026 09:00:00'])],
            dedupSignature: 'sig-default-1',
        ),
        freshRunBatch([], dedupSignature: null),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        // Остановился на ВТОРОМ (пустом) блоке — первый, старый по дате, дошёл до инжеста.
        ->and($run->stats['stop_reason'] ?? null)->toBe('no_new_or_updated_rows')
        ->and(Procurement::query()->count())->toBe(1);
});

test('политика only_fresh без предыдущего завершённого прогона не останавливает — первый скан дренирует как обычно', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');

    $crawler = Crawler::factory()->onlyFresh()->create();

    useQueueSourceMethod([
        freshRunBatch(
            [freshRunCsvRow('0173200001234000062', ['Дата обновления' => '01.01.2020 00:00:00'])],
            dedupSignature: 'sig-first-scan',
        ),
        freshRunBatch([], dedupSignature: null),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->stats['stop_reason'] ?? null)->toBe('no_new_or_updated_rows')
        ->and(array_key_exists('stop_threshold_at', $run->stats->toArray()))->toBeFalse()
        ->and(Procurement::query()->count())->toBe(1);
});

test('политика only_fresh: граница «ровно на пороге» останавливает прогон детерминированно', function () {
    Carbon::setTestNow('2026-03-10 12:00:00');
    config(['crawler.fresh.only_fresh_margin_minutes' => 30]);

    $crawler = Crawler::factory()->onlyFresh()->create();
    Run::factory()->create([
        'crawler_id' => $crawler->id,
        'status' => RunStatus::Completed,
        'started_at' => Carbon::parse('2026-03-10 11:00:00'),
        'finished_at' => Carbon::parse('2026-03-10 11:15:00'),
    ]);

    // Порог = 11:00 - 30мин = 10:30 РОВНО; строка датирована РОВНО порогом.
    useQueueSourceMethod([
        freshRunBatch(
            [freshRunCsvRow('0173200001234000063', ['Дата обновления' => '10.03.2026 10:30:00'])],
            dedupSignature: 'sig-boundary',
        ),
    ]);

    $run = app(FreshRunProcessor::class)->run(crawler: $crawler);

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->stats['stop_reason'] ?? null)->toBe('reached_previous_run');
});

test('профиль archive с stop_policy=only_fresh не сохраняется', function () {
    expect(fn () => Crawler::factory()->create([
        'mode' => CrawlerMode::Archive,
        'stop_policy' => RunStopPolicy::OnlyFresh,
    ]))->toThrow(ValidationException::class);
});
