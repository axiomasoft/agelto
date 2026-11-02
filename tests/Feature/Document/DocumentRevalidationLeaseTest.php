<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\DocumentHead;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\WorkState;
use App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob;
use App\Jobs\Procurement\ScreeningJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\PrepareFixtures;
use Tests\Support\ProcessCore\StubPdfTools;

test('новая immutable версия при running prepare fence запрещает checkpoint и late publication старой версии', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $f = new PrepareFixtures;
    $ctx = $f->context();
    $tools = new StubPdfTools(1);
    $outcome = $f->handler($tools)->execute($f->input, $ctx);
    app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    $ctx = $f->context();
    $outcome = $f->handler($tools)->execute($f->input, $ctx);
    $doc = DB::table('prc_document_versions')->where('id', $f->input->documentVersionId)->value('document_id');
    DocumentStorageFixtures::observe(new DocumentHead($doc, 2, $f->input->documentVersionId),
        DocumentStorageFixtures::upload('new source bytes', DocumentStorageFixtures::domain()));
    $result = app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
    expect($result->status)->toBe(CompletionStatus::Applied)->and($result->state)->toBe(WorkState::Superseded)
        ->and(DB::table('prc_document_representations')->where('range_key', 'complete')->count())->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});

// Retained fake-AI compatibility assertions; typed document revalidation uses the head fence above.
test('AI job не входит в обработку пока документ занят ревалидацией', function (DocumentStatus $status, AnalysisStep $step, string $jobClass): void {
    Http::preventStrayRequests();
    $document = Document::factory()->create(['status' => $status]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    expect(app(DocumentStoreRepository::class)->acquireRevalidationLease(
        document: $document, owner: 'historical-revalidation-fixture', ttlSeconds: 420,
    ))->toBeTrue();

    $analysis = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => $step,
        'status' => AnalysisStatus::Pending,
    ]);
    (new $jobClass(documentId: $document->id))->handle();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Pending)
        ->and($analysis->locked_until)->toBeNull()
        ->and($document->refresh()->locked_by)->toStartWith('historical-revalidation-fixture'.':');
})->with([
    'screening' => [DocumentStatus::Prepared, AnalysisStep::Screening, ScreeningJob::class],
    'extraction' => [DocumentStatus::Screened, AnalysisStep::Extraction, EstimateExtractionJob::class],
]);
