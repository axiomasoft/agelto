<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use Illuminate\Support\Carbon;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;
use Tests\Support\ProcessCore\LegacyBusinessNetwork;

final class DetailParseOutcomeSourceMethod implements SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        return new ListingBatch(
            rows: [],
            rawBody: '',
            dedupSignature: null,
            rowsTotal: 0,
            httpStatus: 200,
            durationMs: 0,
            requestParams: [],
        );
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        return new ProcurementDetails(
            registryNumber: $registryNumber,
            procedureUrlType: 'ea20',
            commonInfoHtml: CardHtmlFixture::commonInfo(),
            documentsHtml: CardHtmlFixture::documents(),
        );
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

beforeEach(fn () => LegacyBusinessNetwork::install());

test('репозиторий перезаписывает последний исход разбора', function (): void {
    $procurement = Procurement::factory()->create();
    $repository = app(ProcurementStoreRepository::class);

    $repository->markDetailFetched(
        procurement: $procurement,
        fetchedAt: Carbon::parse('2026-08-08 10:00:00'),
        detailParseOutcome: ['common-info' => ['status' => 'measured']],
    );
    $repository->markDetailFetched(
        procurement: $procurement,
        fetchedAt: Carbon::parse('2026-08-08 11:00:00'),
        detailParseOutcome: ['common-info' => ['status' => 'probe_failed']],
    );

    expect($procurement->refresh()->detail_parse_outcome)->toBe([
        'common-info' => ['status' => 'probe_failed'],
    ]);
});

test('отсутствие эталона откладывает разбор, после исправления конфигурации карточка восстанавливается', function (): void {
    config([
        'crawler.platforms.eis.source_methods.'.SourceMethodEnum::Csv->value => DetailParseOutcomeSourceMethod::class,
        'crawler.health.enabled' => true,
        'crawler.platforms.eis.baseline_path' => 'tests/Fixtures/Health/missing-baseline.json',
    ]);
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'detail_attempts' => 2,
    ]);

    $result = app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($result)->toBeFalse()
        ->and($procurement->detail_fetched_at)->toBeNull()
        ->and($procurement->detail_attempts)->toBe(2)
        ->and($procurement->detail_failed)->toBeFalse()
        ->and($procurement->detail_next_retry_at)->toBeNull();

    config(['crawler.platforms.eis.baseline_path' => 'resources/crawler/health/eis-baseline.json']);

    expect(app(DetailAnalysisProcessor::class)->run(procurement: $procurement))->toBeTrue()
        ->and($procurement->refresh()->detail_fetched_at)->not->toBeNull()
        ->and($procurement->detail_attempts)->toBe(0);
});
