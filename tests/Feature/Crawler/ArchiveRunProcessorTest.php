<?php

declare(strict_types=1);

use App\Dto\Procurement\ListingBatch;
use App\Dto\Procurement\ListingCursor;
use App\Enums\Procurement\CrawlerMode;
use App\Enums\Procurement\RunStatus;
use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\ArchiveRunProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;

/**
 * @return array<string, string>
 */
function archiveRunCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Способ определения поставщика' => 'Электронный аукцион',
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Размещение извещения',
        'Идентификационный код закупки (ИКЗ)' => '243773601234512345100100001',
        'НМЦК' => '1 250 000,50',
        'Валюта' => 'RUB',
        'ОКПД2' => '26.20.11.110',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
        'Начало подачи заявок' => '01.03.2026',
        'Окончание подачи заявок' => '15.03.2026',
        'Наименование заказчика' => 'ГБУ «Тестовое учреждение»',
    ];
}

function archiveRunBatch(string $registryNumber, string $dedupSignature): ListingBatch
{
    return new ListingBatch(
        rows: [archiveRunCsvRow($registryNumber)],
        rawBody: 'raw csv body',
        dedupSignature: $dedupSignature,
        rowsTotal: 1,
        httpStatus: 200,
        durationMs: 10,
        requestParams: [],
    );
}

function useArchiveRunQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(batches: $batches));

    config(['crawler.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        // Блок = 2 строки, наши батчи по 1 строке — сразу "частичный блок" (drain).
        'crawler.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 100,
        'crawler.archive.min_price_interval' => 10,
        'crawler.archive.max_blocks_per_window' => 50,
    ]);
});

test('период из 2 дней — scan вызван на каждый день, курсор прошёл оба дня, run completed', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    useArchiveRunQueueSourceMethod([
        archiveRunBatch('0173200001234000001', 'sig-day-1'),
        archiveRunBatch('0173200001234000002', 'sig-day-2'),
    ]);

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-02'),
    );

    expect($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(2)
        ->and($run->cursor['day'])->toBe('2024-01-03');
});

test('оборванный прогон (paused с курсором на 2-м дне) резюмится со 2-го дня, не с 1-го', function () {
    $crawler = Crawler::factory()->create(['mode' => CrawlerMode::Archive]);

    $existingRun = Run::factory()->for($crawler)->create([
        'status' => RunStatus::Paused,
        'cursor' => (new ListingCursor(blockFrom: 1, day: '2024-01-02'))->toArray(),
    ]);

    useArchiveRunQueueSourceMethod([
        archiveRunBatch('0173200001234000003', 'sig-day-2-only'),
    ]);

    $run = app(ArchiveRunProcessor::class)->run(
        crawler: $crawler,
        from: Carbon::parse('2024-01-01'),
        to: Carbon::parse('2024-01-02'),
    );

    expect($run->id)->toBe($existingRun->id)
        ->and($run->status)->toBe(RunStatus::Completed)
        ->and(Procurement::query()->count())->toBe(1);
});
