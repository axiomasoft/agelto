<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\Crawler\RecoveryScope;
use App\Enums\Procurement\Crawler\CrawlerMode;
use App\Enums\Procurement\Crawler\RunStatus;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Modes\BacklogRescanProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\QueueSourceMethod;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

/**
 * Регрессия П-2/Ц-4 (`research/05-change-tracking.md` §1): окно рескана простоя обязано
 * перекрываться назад на `window_overlap_days`, иначе обновление, случившееся ровно в стыке
 * между прогонами, никогда не будет замечено. Один "drained" блок (`rowsTotal < block_size`)
 * с уникальным реестровым номером на день — дренирует день немедленно, без ценового дробления.
 *
 * @return array<string, string>
 */
function changeTrackingCsvRow(string $registryNumber): array
{
    return [
        'Реестровый номер закупки' => $registryNumber,
        'Наименование закупки' => 'Поставка оргтехники',
        'Этап закупки' => 'Подача заявок',
        'Дата размещения' => '01.03.2026',
        'Дата обновления' => '02.03.2026 10:15:00',
    ];
}

function changeTrackingBatch(string $registryNumber): ListingBatch
{
    return new ListingBatch(
        rows: [changeTrackingCsvRow($registryNumber)],
        rawBody: "raw csv body for {$registryNumber}",
        dedupSignature: "sig-{$registryNumber}",
        rowsTotal: 1,
        httpStatus: 200,
        durationMs: 5,
        requestParams: [],
    );
}

function useChangeTrackingQueueSourceMethod(array $batches): void
{
    app()->instance(QueueSourceMethod::class, new QueueSourceMethod(repeatStableWindows: true, batches: $batches));

    config(['crawler.platforms.eis.source_methods' => [
        SourceMethodEnum::Csv->value => QueueSourceMethod::class,
    ]]);
}

beforeEach(function () {
    Sleep::fake();
    Storage::fake('s3');
    config([
        'crawler.platforms.eis.block_size' => 2,
        'crawler.archive.price_ceiling' => 1_000_000_000,
        'crawler.tracking.window_overlap_days' => 2,
    ]);
});

beforeEach(fn () => LegacyBusinessNetwork::install());

test('обновление, попавшее в стык окон, не теряется — перекрытие расширяет рескан назад', function () {
    $registry = Registry::factory()->eis()->create();
    $freshCrawler = Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Fresh]);
    Crawler::factory()->create(['registry_id' => $registry->id, 'mode' => CrawlerMode::Archive, 'is_active' => true]);

    // Окно простоя без перекрытия — три дня: 2024-01-01..2024-01-03. С перекрытием 2 суток
    // рескан обязан начаться с 2023-12-30 — покрыть пять дней, а не три; иначе обновление,
    // случившееся 2023-12-30/31 (в стыке с предыдущим прогоном), теряется безвозвратно.
    Run::factory()->for($freshCrawler, 'crawler')->create([
        'status' => RunStatus::Completed,
        'cursor' => (new ListingCursor(
            recoveryScope: RecoveryScope::fromFreshCrawler(crawler: $freshCrawler)->toArray(),
        ))->toArray(),
        'stats' => ['cap_hit' => true, 'possible_skip_window' => [
            'from' => '2024-01-01T00:00:00Z',
            'to' => '2024-01-03T00:00:00Z',
        ]],
    ]);

    useChangeTrackingQueueSourceMethod([
        changeTrackingBatch('p46-overlap-day-1'), // 2023-12-30 — виден ТОЛЬКО благодаря перекрытию
        changeTrackingBatch('p46-overlap-day-2'), // 2023-12-31 — виден ТОЛЬКО благодаря перекрытию
        changeTrackingBatch('p46-window-day-1'),  // 2024-01-01
        changeTrackingBatch('p46-window-day-2'),  // 2024-01-02
        changeTrackingBatch('p46-window-day-3'),  // 2024-01-03
    ]);

    app(BacklogRescanProcessor::class)->sweep();

    expect(Procurement::query()->count())->toBe(5)
        ->and(Procurement::query()->where('source_identity_value', 'p46-overlap-day-1')->exists())->toBeTrue()
        ->and(Procurement::query()->where('source_identity_value', 'p46-overlap-day-2')->exists())->toBeTrue();
});
