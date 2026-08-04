<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AnalysisRunStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Exceptions\Procurement\Ai\NoActiveAnalysisRun;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Procurement\Prepare\ArchiveStrategy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Девять гейтов контракта `root/document-contour.md` (§7, D91/D92/D93) — единый контур
 * «обучение ↔ боевой разбор»: ось площадки, связь документа с эталоном, прогон.
 */
test('документ без platform_id не создаётся (NOT NULL)', function (): void {
    expect(fn () => Document::factory()->create(['platform_id' => null]))
        ->toThrow(QueryException::class);
});

test('выборка витрины не содержит смету и документ площадки «Обучалка»', function (): void {
    $training = Platform::factory()->training()->create();
    $public = Platform::factory()->create();

    $trainingDocument = Document::factory()->create(['platform_id' => $training->id]);
    $publicDocument = Document::factory()->create(['platform_id' => $public->id]);

    $documents = Document::query()->publicPlatform()->pluck('id');
    expect($documents)->toContain($publicDocument->id)
        ->and($documents)->not->toContain($trainingDocument->id);

    $trainingEstimate = Estimate::factory()->create(['platform_id' => $training->id]);
    $publicEstimate = Estimate::factory()->create(['platform_id' => $public->id]);

    $estimates = Estimate::query()->publicPlatform()->pluck('id');
    expect($estimates)->toContain($publicEstimate->id)
        ->and($estimates)->not->toContain($trainingEstimate->id);
});

test('прогон с mode=auto над документом площадки «Обучалка» не создаётся', function (): void {
    Platform::factory()->training()->create();

    $fixturesDisk = config('ai.fixtures.disk');
    $documentsDisk = config('crawler.documents_disk');
    Storage::fake($fixturesDisk);
    Storage::fake($documentsDisk);

    $goldDir = sys_get_temp_dir().'/contour-gold-'.Str::uuid();
    File::ensureDirectoryExists($goldDir);
    config(['ai.fixtures.gold_dir' => $goldDir]);

    $code = 'EST-XLSX-CONTOUR-001';
    $content = 'contour-content';
    Storage::disk($fixturesDisk)->put(Fixture::storagePathFor($code, 'xlsx'), $content);

    Fixture::factory()->create([
        'code' => $code,
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
    file_put_contents("{$goldDir}/{$code}.gold.json", json_encode(['estimates' => []], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:gold:import', ['--code' => [$code]])->assertExitCode(0);

    $document = Document::query()->where('external_id', "fixture:{$code}")->firstOrFail();
    expect($document->platform_id)->toBe(Platform::training()->id);

    expect(AnalysisRun::query()->where('mode', RunMode::Auto)->exists())->toBeFalse();

    File::deleteDirectory($goldDir);
});

test('второй незавершённый прогон по той же версии отвергается базой', function (): void {
    $version = DocumentVersion::factory()->create();

    AnalysisRun::factory()->create(['document_version_id' => $version->id, 'status' => AnalysisRunStatus::Running]);

    expect(fn () => AnalysisRun::factory()->create(['document_version_id' => $version->id, 'status' => AnalysisRunStatus::Running]))
        ->toThrow(QueryException::class);
});

test('дочерний документ архива наследует площадку родителя', function (): void {
    $platform = Platform::factory()->create();
    $document = Document::factory()->create(['status' => DocumentStatus::Downloaded, 'platform_id' => $platform->id]);

    $workDir = sys_get_temp_dir().'/contour_archive_'.Str::uuid();
    mkdir($workDir, recursive: true);
    file_put_contents($workDir.'/estimate.txt', 'содержимое сметы');

    $archivePath = sys_get_temp_dir().'/contour_archive_'.Str::uuid().'.7z';
    $result = Process::path($workDir)->timeout(30)->run(['7z', 'a', $archivePath, '.']);
    expect($result->successful())->toBeTrue($result->errorOutput());

    $disk = Storage::fake((string) config('crawler.documents_disk'));
    $content = file_get_contents($archivePath);
    $storagePath = 'documents/contour-archive-test.7z';
    $disk->put($storagePath, $content);

    $version = DocumentVersion::factory()->create([
        'document_id' => $document->id,
        'extension' => '7z',
        'storage_path' => $storagePath,
        'content_hash' => hash('sha256', $storagePath),
        'size_bytes' => strlen($content),
    ]);

    $processed = app(ArchiveStrategy::class)->process($document, $version);
    expect($processed)->toBeTrue();

    $child = Document::query()->where('external_id', "{$version->id}:estimate.txt")->firstOrFail();
    expect($child->platform_id)->toBe($platform->id);
});

test('анализ без активного прогона бросает NoActiveAnalysisRun, а не создаётся тихо', function (): void {
    $version = DocumentVersion::factory()->create();

    expect(fn () => app(AnalysisStoreRepository::class)->firstOrCreate($version->id, AnalysisStep::Screening))
        ->toThrow(NoActiveAnalysisRun::class);
});
