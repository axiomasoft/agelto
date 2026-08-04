<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * P5.5 — `estimate:gold:import` создаёт рабочие копии `Document`/`DocumentVersion` вне закупки (D19) и
 * записи `prc_ai_dataset_examples` со ссылкой `fixture_id` и `source=deterministic` из
 * `estimates/gold/<CODE>.{gold,screening}.json`. `--prepare` не покрывается здесь глубоко —
 * реальный прогон фабрики представлений по корпусу (P3) — предмет Completion Notes, не синтетики.
 */
beforeEach(fn () => Platform::factory()->training()->create());

function goldImportGoldDir(): string
{
    $folder = sys_get_temp_dir().'/gold-import-'.Str::uuid();
    File::ensureDirectoryExists($folder);
    config(['ai.fixtures.gold_dir' => $folder]);

    return $folder;
}

function goldImportFixture(string $fixturesDisk, string $code, string $content): Fixture
{
    Storage::disk($fixturesDisk)->put(Fixture::storagePathFor($code, 'xlsx'), $content);

    return Fixture::factory()->create([
        'code' => $code,
        'document_type' => AiDocumentType::Estimate,
        'owner_document_type' => AiDocumentType::Estimate,
        'title' => "Эталон {$code}",
        'source_format' => 'xlsx',
        'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);
}

test('эталон с extraction-gold → документ, версия и запись task=extraction с fixture_id и source=deterministic', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    $fixture = goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-001', 'contenido-001');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-001.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-001']])->assertExitCode(0);

    $document = Document::query()->where('external_id', 'fixture:EST-XLSX-IMPORT-001')->first();
    expect($document)->not->toBeNull()
        ->and($document->procurement_id)->toBeNull();

    $version = DocumentVersion::query()->where('document_id', $document->id)->first();
    expect($version)->not->toBeNull()
        ->and($version->content_hash)->toBe($fixture->sha256);

    $example = DatasetExample::query()->where('document_version_id', $version->id)->where('task', 'extraction')->first();
    expect($example)->not->toBeNull()
        ->and($example->fixture_id)->toBe($fixture->id)
        ->and($example->source)->toBe('deterministic');

    File::deleteDirectory($goldDir);
});

test('эталон со screening-gold → вторая запись task=screening', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-002', 'contenido-002');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-002.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-002.screening.json", json_encode([
        'document_type' => 'estimate', 'confidence' => 1.0, 'description' => null, 'page_count_estimate' => null, 'ignore_reason' => null,
    ], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-002']])->assertExitCode(0);

    $version = DocumentVersion::query()->whereHas('document', fn ($q) => $q->where('external_id', 'fixture:EST-XLSX-IMPORT-002'))->first();

    expect(DatasetExample::query()->where('document_version_id', $version->id)->where('task', 'extraction')->exists())->toBeTrue()
        ->and(DatasetExample::query()->where('document_version_id', $version->id)->where('task', 'screening')->exists())->toBeTrue();

    File::deleteDirectory($goldDir);
});

test('повторный прогон идемпотентен (числа не изменились, файл не перезаписан)', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    $fixture = goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-003', 'contenido-003');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-003.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-003']])->assertExitCode(0);
    $documentsAfterFirst = Document::query()->count();
    $versionsAfterFirst = DocumentVersion::query()->count();
    $examplesAfterFirst = DatasetExample::query()->count();
    $storagePath = 'documents/'.substr($fixture->sha256, 0, 2)."/{$fixture->sha256}";
    $bytesAfterFirst = Storage::disk($documentsDisk)->get($storagePath);

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-003']])->assertExitCode(0);

    expect(Document::query()->count())->toBe($documentsAfterFirst)
        ->and(DocumentVersion::query()->count())->toBe($versionsAfterFirst)
        ->and(DatasetExample::query()->count())->toBe($examplesAfterFirst)
        ->and(Storage::disk($documentsDisk)->get($storagePath))->toBe($bytesAfterFirst);

    File::deleteDirectory($goldDir);
});

test('эталон без gold → пропуск со строкой отчёта', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-004', 'contenido-004');
    // Ни .gold.json, ни .screening.json не создаются.

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-004']])
        ->expectsOutputToContain('записей extraction 0')
        ->assertExitCode(0);

    expect(DatasetExample::query()->count())->toBe(0);

    File::deleteDirectory($goldDir);
});

test('невалидный gold → отклонение индивидуальное, остальные импортированы', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-BAD', 'contenido-bad');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-BAD.gold.json", json_encode(['estimates' => ['not-an-object']], JSON_THROW_ON_ERROR));

    goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-GOOD', 'contenido-good');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-GOOD.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-BAD', 'EST-XLSX-IMPORT-GOOD']])
        ->assertExitCode(1);

    $goodVersion = DocumentVersion::query()->whereHas('document', fn ($q) => $q->where('external_id', 'fixture:EST-XLSX-IMPORT-GOOD'))->first();
    expect(DatasetExample::query()->where('document_version_id', $goodVersion->id)->exists())->toBeTrue();

    $badDocument = Document::query()->where('external_id', 'fixture:EST-XLSX-IMPORT-BAD')->first();
    $badVersion = DocumentVersion::query()->where('document_id', $badDocument->id)->first();
    expect(DatasetExample::query()->where('document_version_id', $badVersion->id)->exists())->toBeFalse();

    File::deleteDirectory($goldDir);
});

test('--dry-run → ноль записей', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-DRY', 'contenido-dry');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-DRY.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-DRY'], '--dry-run' => true])->assertExitCode(0);

    expect(Document::query()->count())->toBe(0)
        ->and(DocumentVersion::query()->count())->toBe(0)
        ->and(DatasetExample::query()->count())->toBe(0);

    File::deleteDirectory($goldDir);
});

test('is_verified остался false после прогона', function (): void {
    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);
    $goldDir = goldImportGoldDir();

    $fixture = goldImportFixture($fixturesDisk, 'EST-XLSX-IMPORT-VERIFY', 'contenido-verify');
    file_put_contents("{$goldDir}/EST-XLSX-IMPORT-VERIFY.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => ['EST-XLSX-IMPORT-VERIFY']])->assertExitCode(0);

    expect($fixture->refresh()->is_verified)->toBeFalse();

    $example = DatasetExample::query()->first();
    expect($example->verified_by)->toBeNull()
        ->and($example->verified_at)->toBeNull();

    File::deleteDirectory($goldDir);
});
