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
        );
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

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

test('ошибка health-зонда не увеличивает попытки и не отменяет снятие карточки', function (): void {
    config([
        'crawler.platforms.eis.source_methods.'.SourceMethodEnum::Csv->value => DetailParseOutcomeSourceMethod::class,
        'crawler.health.enabled' => true,
        'crawler.health.baseline_path' => 'tests/Fixtures/Health/missing-baseline.json',
    ]);
    $registry = Registry::factory()->eis()->create();
    $procurement = Procurement::factory()->create([
        'registry_id' => $registry->id,
        'detail_attempts' => 2,
    ]);

    $result = app(DetailAnalysisProcessor::class)->run(procurement: $procurement);

    $procurement->refresh();

    expect($result)->toBeTrue()
        ->and($procurement->detail_fetched_at)->not->toBeNull()
        ->and($procurement->detail_attempts)->toBe(0)
        ->and($procurement->detail_failed)->toBeFalse()
        ->and($procurement->detail_parse_outcome['common-info']['status'])->toBe('probe_failed')
        ->and($procurement->detail_parse_outcome['documents']['status'])->toBe('probe_failed');
});
