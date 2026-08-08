<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingBatch;
use App\Dto\Procurement\Crawler\ListingCursor;
use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Crawler\PlatformBudgetExhaustedException;
use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Modes\DetailAnalysisProcessor;
use App\Services\Procurement\PipelineDispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\Doubles\Crawler\CardHtmlFixture;

final class DetailJobBudgetExhaustedSourceMethod implements SourceMethod
{
    public function collectListing(Crawler $crawler, ListingCursor $cursor): ListingBatch
    {
        throw PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0);
    }

    public function collectDetails(string $registryNumber, Registry $registry): ProcurementDetails
    {
        throw PlatformBudgetExhaustedException::forHost(host: 'eis.test', maxWaitSeconds: 0);
    }

    public function isAvailable(): bool
    {
        return true;
    }
}

function detailJobRegistry(): Registry
{
    return Registry::factory()->create([
        'code' => 'fz44',
        'settings' => [
            'procedure_url_types' => ['Электронный аукцион' => 'ea20'],
            'card_url_templates' => [
                'ea20' => [
                    'common-info' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/common-info.html?regNumber={regNumber}',
                    'documents' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/documents.html?regNumber={regNumber}',
                    'event-journal' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/event-journal.html?regNumber={regNumber}',
                    'supplier-results' => 'https://zakupki.gov.ru/epz/order/notice/ea20/view/supplier-results.html?regNumber={regNumber}',
                ],
            ],
        ],
    ]);
}

function detailJobEventJournalHtml(): string
{
    $html = (string) file_get_contents(base_path('tests/Fixtures/Crawler/Card/event-journal.html'));
    $html = str_replace('23.01.2025 09:29', now()->startOfMonth()->addDay()->format('d.m.Y').' 09:29', $html);
    $html = str_replace('23.01.2025 09:00', now()->startOfMonth()->addDays(2)->format('d.m.Y').' 09:00', $html);

    return str_replace('15.01.2025 11:55', now()->startOfMonth()->addDays(3)->format('d.m.Y').' 11:55', $html);
}

function fakeDetailJobHttp(): void
{
    Http::fake([
        '*view/common-info.html*' => Http::response(body: CardHtmlFixture::commonInfo(), status: 200),
        '*view/documents.html*' => Http::response(body: CardHtmlFixture::documents(), status: 200),
        '*view/event-journal.html*' => Http::response(body: detailJobEventJournalHtml(), status: 200),
        '*view/supplier-results.html*' => Http::response(body: CardHtmlFixture::supplierResults(), status: 200),
        '*filestore*' => Http::response(body: 'binary-content', status: 200),
    ]);
}

test('job вызывает детальный процессор и мост в pipeline после успеха', function (): void {
    Queue::fake();
    Sleep::fake();
    Storage::fake('s3');
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlDetail])->create();
    Proxy::factory()->country('RU')->withScopes([ProxyScope::DocumentDownload])->create();
    $procurement = Procurement::factory()->create([
        'registry_id' => detailJobRegistry()->id,
        'registry_number' => '0338200009125000001',
        'procedure_type' => 'electronic_auction',
        'procedure_type_raw' => 'Электронный аукцион',
    ]);
    fakeDetailJobHttp();

    (new FetchProcurementDetailJob(procurementId: $procurement->id))->handle(
        app(DetailAnalysisProcessor::class),
        app(PipelineDispatcher::class),
    );

    expect($procurement->refresh()->detail_fetched_at)->not->toBeNull();
    expect(Document::query()->where('procurement_id', $procurement->id)->where('status', DocumentStatus::Downloaded)->exists())->toBeTrue();
    Queue::assertPushed(PrepareDocumentJob::class);
});

test('job тихо заканчивается при исчерпанном бюджете и не вызывает мост', function (): void {
    Queue::fake();
    Sleep::fake();
    config(['crawler.source_methods.'.SourceMethodEnum::Csv->value => DetailJobBudgetExhaustedSourceMethod::class]);
    $procurement = Procurement::factory()->create(['detail_attempts' => 2]);

    (new FetchProcurementDetailJob(procurementId: $procurement->id))->handle(
        app(DetailAnalysisProcessor::class),
        app(PipelineDispatcher::class),
    );

    expect($procurement->refresh()->detail_attempts)->toBe(2)
        ->and($procurement->detail_fetched_at)->toBeNull();
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('job стоит на detail-очереди и держит unique-lock по id закупки', function (): void {
    $job = new FetchProcurementDetailJob(procurementId: 42);

    expect(class_implements($job))->toHaveKey(ShouldQueue::class)
        ->and(class_implements($job))->toHaveKey(ShouldBeUnique::class)
        ->and($job->queue)->toBe('crawl_detail')
        ->and($job->uniqueId())->toBe('42')
        ->and($job->uniqueFor())->toBe(360);
});
