<?php

declare(strict_types=1);

use App\Dto\Procurement\Crawler\ListingCursor;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Services\Llm\Dto\CacheableContent;
use App\Services\Llm\Dto\LlmCacheOptions;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use App\Services\Llm\Providers\PrismGeminiProvider;
use App\Services\Platforms\Eis\Csv\CsvSourceMethod;
use App\Services\Platforms\Eis\Filters\Okpd2NodeSynchronizer;
use App\Services\Procurement\DocumentDownloader;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures as C;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

test('CSV caller декодирует реальный win1251 экспорт и проводит единственный запрос через permit', function (): void {
    $wire = new WireFixture;
    try {
        $registry = C::platform($wire);
        Okpd2Node::factory()->create(['platform_id' => $registry->platform_id, 'code' => '43', 'external_id' => '8873921']);
        $crawler = Crawler::factory()->for($registry)->create(['filter' => ['okpd2Codes' => ['43']]]);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = app(CsvSourceMethod::class)->collectListing($crawler, new ListingCursor(blockFrom: 1));
        expect($batch->rows)->toHaveCount(4)
            ->and($batch->rows[0]['Реестровый номер закупки'])->toBe('№0338300047926000272')
            ->and($batch->rows[0]['Способ определения поставщика (подрядчика, исполнителя), подрядной организации (размещения закупки)'])->toBe('Электронный аукцион');
        C::assertAccounting($wire, 1, 'legacy.csv_listing');
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('синхронизатор ОКПД2 использует общий транспорт и сохраняет ограничение обхода', function (): void {
    $wire = new WireFixture;
    try {
        C::platform($wire);
        $wire->respond('/children', '[]', headers: ['Content-Type' => 'application/json']);
        $result = app(Okpd2NodeSynchronizer::class)->run(dryRun: true, maxRequests: 1);
        expect($result['requests'])->toBe(1)->and($result['nodes_seen'])->toBe(0)->and($result['written'])->toBeFalse();
        C::assertAccounting($wire, 1, 'legacy.reference_sync');
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('загрузчик документа читает настоящий TLS поток и сохраняет проверенный hash без fetch writer', function (): void {
    $wire = new WireFixture(443);
    try {
        $registry = C::platform($wire);
        Storage::fake('s3');
        $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
        $wire->respond('/document', $content, headers: ['Content-Type' => 'application/pdf']);
        $document = Document::factory()->create(['source_url' => $wire->url('/document', true)->reveal(), 'platform_id' => $registry->platform_id]);
        $version = app(DocumentDownloader::class)->download($document);
        expect($version)->not->toBeNull()->and($version?->content_hash)->toBe(hash('sha256', $content))
            ->and($document->refresh()->attempts)->toBe(0);
        Storage::disk('s3')->assertExists($version->storage_path);
        C::assertAccounting($wire, 1, 'legacy.document_download');
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('OpenAI SDK отправляет POST через общий handler с provider owner и permit', function (): void {
    $wire = new WireFixture;
    try {
        C::provider($wire);
        $body = json_encode(['id' => 'wire-1', 'model' => 'wire-model', 'status' => 'completed',
            'output' => [['id' => 'wire-msg', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'готово', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3]], JSON_THROW_ON_ERROR);
        $wire->respond('/v1/responses', $body, headers: ['Content-Type' => 'application/json'], method: 'POST');
        $result = app(OpenAiCompatibleProvider::class)->complete(new LlmPrompt(systemPart: 'test'),
            new LlmOptions('wire', 'wire-model', 0.0, 32, baseUrl: $wire->url('/v1')->reveal()));
        expect($result->data)->toBe('готово')
            ->and(DB::table('prc_outbound_operations')->value('provider_key'))->toBe('wire')
            ->and(DB::table('prc_outbound_operations')->value('platform_id'))->toBeNull()
            ->and($wire->receipts()[0]['method'])->toBe('POST');
        C::assertAccounting($wire, 1, 'legacy.provider');
    } finally {
        $wire->stop();
    }
})->group('t03', 'p26');

test('Gemini cache SDK использует тот же handler вместо скрытого сетевого клиента', function (): void {
    $wire = new WireFixture;
    try {
        C::provider($wire);
        config(['ai.gemini.files.base_url' => $wire->url('/')->reveal(), 'ai.gemini.api_key_env' => 'WIRE_SYNTHETIC_GEMINI_KEY']);
        putenv('WIRE_SYNTHETIC_GEMINI_KEY=synthetic-test-key');
        $wire->respond('/v1beta/cachedContents', json_encode(['name' => 'cachedContents/wire',
            'expireTime' => '2030-01-01T00:00:00Z'], JSON_THROW_ON_ERROR), headers: ['Content-Type' => 'application/json'], method: 'POST');
        $handle = app(PrismGeminiProvider::class)->createCache(new CacheableContent('test'), new LlmCacheOptions('wire', CarbonInterval::minutes(1)));
        expect($handle->id)->toBe('cachedContents/wire');
        C::assertAccounting($wire, 1, 'legacy.provider');
    } finally {
        putenv('WIRE_SYNTHETIC_GEMINI_KEY');
        $wire->stop();
    }
})->group('t03', 'p26');
