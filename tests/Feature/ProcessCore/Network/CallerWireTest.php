<?php

declare(strict_types=1);

use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Exceptions\Llm\UnsupportedConsumer;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use App\Services\Platforms\Eis\Filters\Okpd2NodeSynchronizer;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Platforms\Eis\EisCsvFixture;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\AcquisitionWireFixture;
use Tests\Support\ProcessCore\Http\CallerFixtures as C;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});

test('исторический win1251 экспорт с четырьмя строками и пустым обязательным title остаётся partial', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $wire->respond('/csv', EisCsvFixture::body(), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = $fixture->fetch($fixture->listingRequest('2026-08-05'));
        expect($batch->value)->toBeInstanceOf(ListingFetched::class)
            ->and($batch->value->page->rowsTotal)->toBe(4)
            ->and($batch->value->page->assessment->completeness->value)->toBe('partial')
            ->and($batch->value->page->summaries->items)->toBeEmpty()
            ->and($batch->value->page->nextCursor)->toBeNull()
            ->and(DB::table('prc_procurements')->count())->toBe(0);
        C::assertAccounting($wire, 1, 'acquisition.diagnostic');
    } finally {
        $wire->stop();
    }
});

test('CSV caller декодирует реальный win1251 экспорт и проводит единственный запрос через permit', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new AcquisitionWireFixture($wire);
        $utf8 = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv'));
        $wire->respond('/csv', mb_convert_encoding($utf8, 'Windows-1251', 'UTF-8'), headers: ['Content-Type' => 'text/csv; charset=windows-1251']);
        $batch = $fixture->fetch($fixture->listingRequest());
        expect($batch->value)->toBeInstanceOf(ListingFetched::class)
            ->and($batch->value->page->summaries->items)->toHaveCount(1)
            ->and($batch->value->page->summaries->items[0]->identity->value->value)->toBe('00042')
            ->and($batch->value->page->summaries->items[0]->sourceStage)->toBe('Подача заявок');
        C::assertAccounting($wire, 1, 'acquisition.diagnostic');
        expect(DB::table('prc_outbound_operations')->sole()->kind)->toBe('probe');
    } finally {
        $wire->stop();
    }
})->group('transport-and-work-budget');

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
})->group('transport-and-work-budget');

test('загрузчик документа читает настоящий bounded поток через Process permit без ordinary bridge', function (): void {
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
        $wire->respond('/document', $content, headers: ['Content-Type' => 'application/pdf']);
        $f = new DownloadFixtures($wire);
        [$input, $work, $ctx] = $f->running();
        app(WorkFinalizer::class)->finish($ctx->lease(), $f->handler()->execute($input, $ctx));
        $version = DB::table('prc_document_versions')->selectRaw("*,encode(content_hash,'hex') AS content_hash")->sole();
        expect($version->content_hash)->toBe(hash('sha256', $content))
            ->and(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(0)
            ->and(DB::table('prc_outbound_operations')->where('kind', 'legacy_bridge')->count())->toBe(0)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)->and($wire->receipts())->toHaveCount(1);
        Storage::disk($version->disk)->assertExists($version->storage_path);
    } finally {
        $wire->stop();
    }
})->group('transport-and-work-budget');

test('OpenAI caller without a durable financial owner refuses before any ordinary bridge or HTTP', function (): void {
    $wire = new WireFixture;
    try {
        C::provider($wire);
        $body = json_encode(['id' => 'wire-1', 'model' => 'wire-model', 'status' => 'completed',
            'output' => [['id' => 'wire-msg', 'type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'готово', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3]], JSON_THROW_ON_ERROR);
        $wire->respond('/v1/responses', $body, headers: ['Content-Type' => 'application/json'], method: 'POST');
        expect(fn () => app(OpenAiCompatibleProvider::class)->complete(new LlmPrompt(systemPart: 'test'),
            new LlmOptions('wire', 'wire-model', 0.0, 32, baseUrl: $wire->url('/v1')->reveal())))
            ->toThrow(UnsupportedConsumer::class);
        expect(DB::table('prc_outbound_operations')->count())->toBe(0)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)
            ->and($wire->receipts())->toBeEmpty();
    } finally {
        $wire->stop();
    }
})->group('transport-and-work-budget');
