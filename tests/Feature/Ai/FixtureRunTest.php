<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\RunMode;
use App\Exceptions\Procurement\AnalysisRunAlreadyActive;
use App\Exceptions\Procurement\ScreeningDisabled;
use App\Jobs\Procurement\PrepareDocumentJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Services\Procurement\Fixture\RunFixtureAnalysisAction;
use App\Services\Procurement\Fixture\WorkingCopyProvisioner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * P5.2 — `RunFixtureAnalysisAction` (D41/D135/D136): рабочая копия сеймом P3.3 → гейты
 * предусловий → снятие активных анализов на повторном прогоне → диспатч штатного входа.
 */
beforeEach(function (): void {
    Platform::factory()->training()->create();
    config(['ai.screening.enabled' => true]);
});

function fixtureRunFixture(string $fixturesDisk, string $code): Fixture
{
    Storage::disk($fixturesDisk)->put(Fixture::storagePathFor($code, 'xlsx'), 'contenido');

    return Fixture::factory()->create([
        'code' => $code,
        'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
        'sha256' => hash('sha256', $code),
    ]);
}

function fixtureRunSeedVersion(Document $document): DocumentVersion
{
    $content = file_get_contents(base_path('tests/Fixtures/Documents/sample-v1.pdf'));
    $hash = hash('sha256', $content);
    $storagePath = "documents/{$hash}";

    Storage::disk('s3')->put($storagePath, $content);

    return DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'content_hash' => $hash,
        'size_bytes' => strlen($content),
        'filename' => 'sample-v1.pdf',
        'extension' => 'pdf',
        'mime' => 'application/pdf',
        'storage_path' => $storagePath,
        'downloaded_at' => now(),
    ]);
}

test('запуск создаёт рабочую копию и диспатчит PrepareDocumentJob с RunMode::Manual и id инициатора', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    Queue::fake();

    $fixture = fixtureRunFixture($fixturesDisk, 'EST-XLSX-RUN-001');
    $user = User::factory()->create();

    app(RunFixtureAnalysisAction::class)->run($fixture, $user->id);

    $document = Document::query()->where('external_id', "fixture:{$fixture->code}")->firstOrFail();
    expect($document->platform_id)->toBe(Platform::training()->id)
        ->and($fixture->refresh()->document_id)->toBe($document->id);

    $version = DocumentVersion::query()->where('document_id', $document->id)->firstOrFail();
    expect($version->content_hash)->toBe($fixture->sha256);

    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job): bool => $job->documentId === $document->id
        && $job->mode === RunMode::Manual
        && $job->initiatedBy === $user->id);
});

test('PrepareDocumentJob со значениями по умолчанию заводит прогон auto без инициатора, а с явными параметрами — manual с initiated_by (D135)', function (): void {
    Storage::fake('s3');
    Queue::fake();

    $documentA = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $versionA = fixtureRunSeedVersion($documentA);

    (new PrepareDocumentJob($documentA->id))->handle();

    $runA = AnalysisRun::query()->where('document_version_id', $versionA->id)->firstOrFail();
    expect($runA->mode)->toBe(RunMode::Auto)
        ->and($runA->initiated_by)->toBeNull();

    $documentB = Document::factory()->create(['status' => DocumentStatus::Downloaded]);
    $versionB = fixtureRunSeedVersion($documentB);
    $user = User::factory()->create();

    (new PrepareDocumentJob($documentB->id, RunMode::Manual, $user->id))->handle();

    $runB = AnalysisRun::query()->where('document_version_id', $versionB->id)->firstOrFail();
    expect($runB->mode)->toBe(RunMode::Manual)
        ->and($runB->initiated_by)->toBe($user->id);
});

test('активный прогон версии → AnalysisRunAlreadyActive, повторный клик не диспатчит второй job', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);

    $fixture = fixtureRunFixture($fixturesDisk, 'EST-XLSX-RUN-002');
    [, $version] = app(WorkingCopyProvisioner::class)->provision($fixture, dryRun: false);
    app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);
    // `document` — belongsTo лениво закэшированный на первом (ещё "null") обращении внутри
    // provision() — без refresh() второй provision() внутри Action увидел бы стухший кэш и
    // завёл БЫ второй документ/версию мимо только что созданной (реальный вызов из UI получает
    // $fixture свежим из запроса — эта строка только устраняет double-provision артефакт теста).
    $fixture->refresh();

    Queue::fake();

    expect(fn () => app(RunFixtureAnalysisAction::class)->run($fixture))
        ->toThrow(AnalysisRunAlreadyActive::class);

    Queue::assertNothingPushed();
});

test('выключенный ai.screening.enabled → ScreeningDisabled, рабочая копия не создана', function (): void {
    config(['ai.screening.enabled' => false]);
    $fixturesDisk = config('ai.fixtures.disk');
    Storage::fake($fixturesDisk);
    Storage::fake(config('crawler.documents_disk'));

    $fixture = fixtureRunFixture($fixturesDisk, 'EST-XLSX-RUN-003');

    expect(fn () => app(RunFixtureAnalysisAction::class)->run($fixture))
        ->toThrow(ScreeningDisabled::class);

    expect(Document::query()->where('external_id', "fixture:{$fixture->code}")->exists())->toBeFalse();
});

test('повторный прогон того же эталона снимает активные анализы обоих шагов и откатывает статус документа в downloaded', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);

    $fixture = fixtureRunFixture($fixturesDisk, 'EST-XLSX-RUN-004');
    [$document, $version] = app(WorkingCopyProvisioner::class)->provision($fixture, dryRun: false);

    $finishedRun = app(AnalysisRunStoreRepository::class)->start($version->id, RunMode::Auto);
    app(AnalysisRunStoreRepository::class)->markSucceeded($finishedRun);

    $screening = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $finishedRun->id,
        'step' => AnalysisStep::Screening,
        'status' => AnalysisStatus::Succeeded,
    ]);
    $extraction = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'analysis_run_id' => $finishedRun->id,
        'step' => AnalysisStep::Extraction,
        'status' => AnalysisStatus::Succeeded,
    ]);

    app(DocumentStoreRepository::class)->update($document, ['status' => DocumentStatus::Extracted]);
    $fixture->refresh();

    Queue::fake();

    app(RunFixtureAnalysisAction::class)->run($fixture);

    expect($screening->refresh()->superseded_at)->not->toBeNull()
        ->and($extraction->refresh()->superseded_at)->not->toBeNull()
        ->and($document->refresh()->status)->toBe(DocumentStatus::Downloaded);

    Queue::assertPushed(PrepareDocumentJob::class, fn (PrepareDocumentJob $job): bool => $job->documentId === $document->id
        && $job->mode === RunMode::Manual);
});

test('оба вызывающих пути (estimate:gold:import и «Прогнать») резолвят одну и ту же рабочую копию по sha256 (D41, единственность сейма)', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);

    $fixture = fixtureRunFixture($fixturesDisk, 'EST-XLSX-RUN-005');

    $this->artisan('estimate:gold:import', ['--code' => [$fixture->code]])->assertExitCode(0);

    $documentFromImport = Document::query()->where('external_id', "fixture:{$fixture->code}")->firstOrFail();
    $versionFromImport = DocumentVersion::query()->where('document_id', $documentFromImport->id)->firstOrFail();

    Queue::fake();
    $fixture->refresh();
    app(RunFixtureAnalysisAction::class)->run($fixture);

    $documentFromRun = Document::query()->where('external_id', "fixture:{$fixture->code}")->firstOrFail();
    $versionFromRun = DocumentVersion::query()->where('document_id', $documentFromRun->id)->firstOrFail();

    expect($documentFromRun->id)->toBe($documentFromImport->id)
        ->and($versionFromRun->id)->toBe($versionFromImport->id)
        ->and($versionFromRun->content_hash)->toBe($fixture->sha256);
});
