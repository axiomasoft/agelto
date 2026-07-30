<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Gold\GoldFormProfile;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Gold\FormProfileReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * P5.3 — `estimate:gold:build` собирает `estimates/gold/**` по реестру `prc_ai_fixtures`, обходит
 * gsfx- (P5.1) и табличные (P5.2) мапперы, пишет сводный отчёт и (`--update-manifest`) РОВНО
 * четыре gold-производных поля манифеста. Синтетические gsfx/xlsx-эталоны собираются в тесте
 * (D1 — реальный корпус не коммитится); реальный прогон — предмет Completion Notes P5.3.
 */
function buildGoldTestDir(): string
{
    $folder = sys_get_temp_dir().'/gold-build-'.Str::uuid();
    File::ensureDirectoryExists($folder.'/gold');

    return $folder;
}

function buildGoldManifest(string $folder, array $entries): string
{
    $path = "{$folder}/manifest.json";
    file_put_contents($path, json_encode(['version' => 1, 'fixtures' => $entries], JSON_THROW_ON_ERROR));

    return $path;
}

function buildGoldManifestEntry(Fixture $fixture): array
{
    return [
        'code' => $fixture->code,
        'storage_path' => $fixture->storage_path,
        'source_path' => $fixture->source_path,
        'original_filename' => $fixture->original_filename,
        'sha256' => $fixture->sha256,
        'size_bytes' => $fixture->size_bytes,
        'mime' => $fixture->mime,
        'class' => $fixture->profileClass()->value,
        'source_format' => $fixture->source_format,
        'form' => $fixture->profileForm(),
        'generator' => $fixture->generator,
        'cluster_id' => $fixture->cluster_id,
        'document_type' => $fixture->document_type->value,
        'owner_document_type' => $fixture->owner_document_type->value,
        'title' => $fixture->title,
        'quirks' => $fixture->quirks->all(),
        'expected_estimates' => $fixture->profileExpectedEstimates(),
        'expected_rows' => $fixture->profileExpectedRows(),
        'gold_source' => $fixture->profileGoldSource()->value,
        'gold_path' => null,
        'is_verified' => $fixture->is_verified,
        'notes' => $fixture->notes,
    ];
}

/**
 * Три строки-позиции (условие г) с числовыми `quantity`/стоимостью (условие в) и 100%-й привязкой
 * шапки (условие а) — гарантированный `pass by profile`.
 */
function passingWorkbookXlsxBytes(string $code): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');

    // Наименование первой позиции несёт код фикстуры — разные фикстуры дают разный sha256
    // (реестр требует уникальности содержимого), сама разметка гейта на это не смотрит.
    $sheet->fromArray([
        ['№ п/п', 'Наименование', 'Ед. изм.', 'Количество', 'Стоимость'],
        [1, "Разработка грунта ({$code})", 'м3', 10, 1100],
        [2, 'Уплотнение грунта', 'м3', 5, 1000],
        [3, 'Планировка площадки', 'м2', 20, 2100],
        [null, 'Итого прямые затраты', null, null, 4200],
    ], null, 'A1');

    $writer = new Xlsx($spreadsheet);
    $tmp = tempnam(sys_get_temp_dir(), 'gold_build_pass_').'.xlsx';
    $writer->save($tmp);
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function passingWorkbookProfileJson(string $code): array
{
    return [
        'version' => GoldFormProfile::VERSION,
        'code' => $code,
        'estimates' => [[
            'ordinal' => 1,
            'sheet' => 'Смета',
            'header_rows' => [1, 1],
            'data_row_start' => 2,
            'data_row_end' => null,
            'columns' => [
                'source_position_number' => 'A',
                'name' => 'B',
                'unit' => 'C',
                'quantity' => 'D',
                'total_price_curr' => 'E',
            ],
            'header_cells' => [
                'source_position_number' => [['cell' => 'A1', 'text' => '№ п/п']],
                'name' => [['cell' => 'B1', 'text' => 'Наименование']],
                'unit' => [['cell' => 'C1', 'text' => 'Ед. изм.']],
                'quantity' => [['cell' => 'D1', 'text' => 'Количество']],
                'total_price_curr' => [['cell' => 'E1', 'text' => 'Стоимость']],
            ],
            'row_rules' => [
                'item_number_column' => 'A',
                'subtotal_prefixes' => ['Итого прямые затраты'],
            ],
            'note' => 'фикстура GoldBuildTest (pass)',
        ]],
    ];
}

/**
 * Одна строка-позиция (условие г нарушено, < 3) — гарантированный `downgrade`.
 */
function downgradingWorkbookXlsxBytes(): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Смета');

    $sheet->fromArray([
        ['№ п/п', 'Наименование', 'Ед. изм.', 'Количество', 'Стоимость'],
        [1, 'Разработка грунта', 'м3', 10, 1100],
    ], null, 'A1');

    $writer = new Xlsx($spreadsheet);
    $tmp = tempnam(sys_get_temp_dir(), 'gold_build_downgrade_').'.xlsx';
    $writer->save($tmp);
    $bytes = (string) file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function downgradingWorkbookProfileJson(string $code): array
{
    $profile = passingWorkbookProfileJson($code);
    $profile['estimates'][0]['note'] = 'фикстура GoldBuildTest (downgrade — 1 строка < 3)';

    return $profile;
}

function seedGoldFixture(string $disk, string $folder, string $code, string $bytes, GoldSource $goldSource, ?array $profileJson = null): Fixture
{
    Storage::disk($disk)->put(Fixture::storagePathFor($code, 'xlsx'), $bytes);

    $fixture = Fixture::factory()->create([
        'code' => $code,
        'document_type' => AiDocumentType::Estimate,
        'owner_document_type' => AiDocumentType::Estimate,
        'title' => "Эталон {$code}",
        'source_format' => 'xlsx',
        'storage_path' => Fixture::storagePathFor($code, 'xlsx'),
        'sha256' => hash('sha256', $bytes),
        'size_bytes' => strlen($bytes),
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => $goldSource->value,
        ],
    ]);

    if ($profileJson !== null) {
        file_put_contents("{$folder}/gold/{$code}.profile.json", json_encode($profileJson, JSON_THROW_ON_ERROR));
    }

    return $fixture;
}

test('gsfx-эталон → файл gold на месте и валиден', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    $zip = new ZipArchive;
    $zipPath = tempnam(sys_get_temp_dir(), 'gold_build_gsfx_').'.gsfx';
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFile(base_path('tests/Fixtures/gsfx/Properties.txt'), 'Properties.txt');
    $zip->addFile(base_path('tests/Fixtures/gsfx/Data.xml'), 'Data.xml');
    $zip->close();
    $bytes = (string) file_get_contents($zipPath);
    unlink($zipPath);

    Storage::disk($disk)->put(Fixture::storagePathFor('EST-GSFX-TEST-001', 'gsfx'), $bytes);
    $fixture = Fixture::factory()->create([
        'code' => 'EST-GSFX-TEST-001',
        'document_type' => AiDocumentType::Estimate,
        'owner_document_type' => AiDocumentType::Estimate,
        'title' => 'Эталон EST-GSFX-TEST-001',
        'source_format' => 'gsfx',
        'storage_path' => Fixture::storagePathFor('EST-GSFX-TEST-001', 'gsfx'),
        'sha256' => hash('sha256', $bytes),
        'size_bytes' => strlen($bytes),
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);

    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($fixture)]);

    $this->artisan('estimate:gold:build', ['--manifest' => $manifestPath, '--gold-dir' => "{$folder}/gold"])
        ->assertExitCode(0);

    $goldPath = "{$folder}/gold/EST-GSFX-TEST-001.gold.json";
    expect(is_file($goldPath))->toBeTrue();

    $gold = json_decode((string) file_get_contents($goldPath), true);
    expect($gold['estimates'])->toHaveCount(1);

    File::deleteDirectory($folder);
});

test('табличный эталон с downgrade → gold не создан, строка понижения в отчёте', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    $bytes = downgradingWorkbookXlsxBytes();
    $fixture = seedGoldFixture($disk, $folder, 'EST-XLSX-DOWNGRADE-001', $bytes, GoldSource::Deterministic, downgradingWorkbookProfileJson('EST-XLSX-DOWNGRADE-001'));
    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($fixture)]);

    $this->artisan('estimate:gold:build', ['--manifest' => $manifestPath, '--gold-dir' => "{$folder}/gold"])
        ->assertExitCode(0);

    expect(is_file("{$folder}/gold/EST-XLSX-DOWNGRADE-001.gold.json"))->toBeFalse();

    $report = (string) file_get_contents(((string) config('ai.fixtures.reports_dir')).'/gold-corpus-report.md');
    expect($report)->toContain('EST-XLSX-DOWNGRADE-001');

    File::deleteDirectory($folder);
});

test('dry-run → ноль файлов и ноль правок манифеста', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    $bytes = passingWorkbookXlsxBytes('EST-XLSX-DRYRUN-001');
    $fixture = seedGoldFixture($disk, $folder, 'EST-XLSX-DRYRUN-001', $bytes, GoldSource::Deterministic, passingWorkbookProfileJson('EST-XLSX-DRYRUN-001'));
    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($fixture)]);
    $manifestBefore = (string) file_get_contents($manifestPath);

    $this->artisan('estimate:gold:build', [
        '--manifest' => $manifestPath,
        '--gold-dir' => "{$folder}/gold",
        '--update-manifest' => true,
        '--dry-run' => true,
    ])->assertExitCode(0);

    expect(glob("{$folder}/gold/*.gold.json"))->toBeEmpty()
        ->and((string) file_get_contents($manifestPath))->toBe($manifestBefore);

    File::deleteDirectory($folder);
});

test('повторный прогон байт-идентичен', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    $bytes = passingWorkbookXlsxBytes('EST-XLSX-REPEAT-001');
    $fixture = seedGoldFixture($disk, $folder, 'EST-XLSX-REPEAT-001', $bytes, GoldSource::Deterministic, passingWorkbookProfileJson('EST-XLSX-REPEAT-001'));
    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($fixture)]);
    $goldPath = "{$folder}/gold/EST-XLSX-REPEAT-001.gold.json";

    $this->artisan('estimate:gold:build', ['--manifest' => $manifestPath, '--gold-dir' => "{$folder}/gold"])->assertExitCode(0);
    $first = (string) file_get_contents($goldPath);

    $this->artisan('estimate:gold:build', ['--manifest' => $manifestPath, '--gold-dir' => "{$folder}/gold"])->assertExitCode(0);
    $second = (string) file_get_contents($goldPath);

    expect($second)->toBe($first);

    File::deleteDirectory($folder);
});

test('--update-manifest меняет РОВНО четыре поля', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    $bytes = passingWorkbookXlsxBytes('EST-XLSX-MANIFEST-001');
    $fixture = seedGoldFixture($disk, $folder, 'EST-XLSX-MANIFEST-001', $bytes, GoldSource::Deterministic, passingWorkbookProfileJson('EST-XLSX-MANIFEST-001'));
    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($fixture)]);

    $this->artisan('estimate:gold:build', [
        '--manifest' => $manifestPath,
        '--gold-dir' => "{$folder}/gold",
        '--update-manifest' => true,
    ])->assertExitCode(0);

    $before = buildGoldManifestEntry($fixture);
    $after = json_decode((string) file_get_contents($manifestPath), true)['fixtures'][0];

    $touchedKeys = ['gold_path', 'expected_rows', 'expected_estimates', 'gold_source'];

    foreach ($before as $key => $value) {
        if (in_array($key, $touchedKeys, strict: true)) {
            continue;
        }

        expect($after[$key])->toBe($value, "неожиданно изменено поле манифеста: {$key}");
    }

    expect($after['gold_path'])->toBe('estimates/gold/EST-XLSX-MANIFEST-001.gold.json')
        ->and($after['expected_estimates'])->toBe(1)
        ->and($after['expected_rows'])->toBeGreaterThan(0)
        ->and($after['gold_source'])->toBe('deterministic');

    File::deleteDirectory($folder);
});

test('невалидный gold отклонён индивидуально, остальные построены', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $folder = buildGoldTestDir();

    // Профиль эталона намеренно битый (неизвестный ключ) — FormProfileReader обязан его отклонить.
    $brokenBytes = passingWorkbookXlsxBytes('EST-XLSX-BROKEN-001');
    Storage::disk($disk)->put(Fixture::storagePathFor('EST-XLSX-BROKEN-001', 'xlsx'), $brokenBytes);
    $broken = Fixture::factory()->create([
        'code' => 'EST-XLSX-BROKEN-001',
        'document_type' => AiDocumentType::Estimate,
        'owner_document_type' => AiDocumentType::Estimate,
        'title' => 'Эталон EST-XLSX-BROKEN-001',
        'source_format' => 'xlsx',
        'storage_path' => Fixture::storagePathFor('EST-XLSX-BROKEN-001', 'xlsx'),
        'sha256' => hash('sha256', $brokenBytes),
        'size_bytes' => strlen($brokenBytes),
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => 'KS2',
            'expected_estimates' => 1,
            'expected_rows' => null,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ]);
    $brokenProfile = passingWorkbookProfileJson('EST-XLSX-BROKEN-001');
    $brokenProfile['unknown_top_level_key'] = 'boom';
    file_put_contents("{$folder}/gold/EST-XLSX-BROKEN-001.profile.json", json_encode($brokenProfile, JSON_THROW_ON_ERROR));

    $good = seedGoldFixture($disk, $folder, 'EST-XLSX-GOOD-001', passingWorkbookXlsxBytes('EST-XLSX-GOOD-001'), GoldSource::Deterministic, passingWorkbookProfileJson('EST-XLSX-GOOD-001'));

    $manifestPath = buildGoldManifest($folder, [buildGoldManifestEntry($broken), buildGoldManifestEntry($good)]);

    $this->artisan('estimate:gold:build', ['--manifest' => $manifestPath, '--gold-dir' => "{$folder}/gold"])
        ->assertExitCode(1);

    expect(is_file("{$folder}/gold/EST-XLSX-BROKEN-001.gold.json"))->toBeFalse()
        ->and(is_file("{$folder}/gold/EST-XLSX-GOOD-001.gold.json"))->toBeTrue();

    File::deleteDirectory($folder);
});
