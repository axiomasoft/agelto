<?php

declare(strict_types=1);

use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * P2.4 — `ai:fixtures:verify` сверяет манифест ↔ файл на диске ↔ запись реестра. Синтетический
 * манифест и `Storage::fake(config('ai.fixtures.disk'))`; реальный корпус не участвует.
 */
function verifyTestDir(): string
{
    $folder = sys_get_temp_dir().'/fixtures-verify-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

function verifyManifestEntry(string $code, string $content): array
{
    return [
        'code' => $code,
        'storage_path' => "{$code}/source.xlsx",
        'source_path' => "{$code}.xlsx",
        'original_filename' => "{$code}.xlsx",
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'class' => 'est',
        'source_format' => 'xlsx',
        'form' => 'KS2',
        'generator' => null,
        'cluster_id' => 'CL-01',
        'doc_type' => null,
        'quirks' => ['TWO_LEVEL_PRICE'],
        'expected_estimates' => 1,
        'expected_rows' => null,
        'gold_source' => 'none',
        'gold_path' => null,
        'is_verified' => false,
        'notes' => null,
    ];
}

function seedConsistentFixture(string $disk, string $code, string $content): void
{
    Storage::disk($disk)->put("{$code}/source.xlsx", $content);
    Fixture::factory()->create([
        'code' => $code,
        'storage_path' => "{$code}/source.xlsx",
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'class' => FixtureClass::Estimate,
        'form' => 'KS2',
        'gold_source' => GoldSource::None,
        'quirks' => ['TWO_LEVEL_PRICE'],
    ]);
}

test('verify зелёный на согласованном комплекте', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $content = 'XLSX-DUMMY-CONTENT';
    seedConsistentFixture($disk, 'EST-XLSX-KS2-001', $content);

    $folder = verifyTestDir();
    $manifestPath = "{$folder}/manifest.json";
    file_put_contents($manifestPath, json_encode(['version' => 1, 'fixtures' => [verifyManifestEntry('EST-XLSX-KS2-001', $content)]], JSON_THROW_ON_ERROR));

    $this->artisan('ai:fixtures:verify', ['--manifest' => $manifestPath])->assertExitCode(0);

    File::deleteDirectory($folder);
});

test('verify красный при подменённом файле на диске', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $content = 'XLSX-DUMMY-CONTENT';
    seedConsistentFixture($disk, 'EST-XLSX-KS2-001', $content);
    Storage::disk($disk)->put('EST-XLSX-KS2-001/source.xlsx', 'ПОДМЕНЁННОЕ СОДЕРЖИМОЕ');

    $folder = verifyTestDir();
    $manifestPath = "{$folder}/manifest.json";
    file_put_contents($manifestPath, json_encode(['version' => 1, 'fixtures' => [verifyManifestEntry('EST-XLSX-KS2-001', $content)]], JSON_THROW_ON_ERROR));

    $this->artisan('ai:fixtures:verify', ['--manifest' => $manifestPath])->assertExitCode(1);

    File::deleteDirectory($folder);
});

test('verify красный при отсутствующей записи реестра', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $content = 'XLSX-DUMMY-CONTENT';
    Storage::disk($disk)->put('EST-XLSX-KS2-001/source.xlsx', $content);
    // Fixture-запись НЕ создана.

    $folder = verifyTestDir();
    $manifestPath = "{$folder}/manifest.json";
    file_put_contents($manifestPath, json_encode(['version' => 1, 'fixtures' => [verifyManifestEntry('EST-XLSX-KS2-001', $content)]], JSON_THROW_ON_ERROR));

    $this->artisan('ai:fixtures:verify', ['--manifest' => $manifestPath])->assertExitCode(1);

    File::deleteDirectory($folder);
});

test('verify красный при непустом gold_path на несуществующий файл', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $content = 'XLSX-DUMMY-CONTENT';
    seedConsistentFixture($disk, 'EST-XLSX-KS2-001', $content);

    $folder = verifyTestDir();
    $manifestPath = "{$folder}/manifest.json";
    $entry = verifyManifestEntry('EST-XLSX-KS2-001', $content);
    $entry['gold_path'] = 'gold/EST-XLSX-KS2-001.gold.json';
    file_put_contents($manifestPath, json_encode(['version' => 1, 'fixtures' => [$entry]], JSON_THROW_ON_ERROR));

    $this->artisan('ai:fixtures:verify', ['--manifest' => $manifestPath])->assertExitCode(1);

    File::deleteDirectory($folder);
});

test('verify красный при orphan-записи реестра', function (): void {
    $disk = config('ai.fixtures.disk');
    Storage::fake($disk);
    $content = 'XLSX-DUMMY-CONTENT';
    seedConsistentFixture($disk, 'EST-XLSX-KS2-001', $content);
    Fixture::factory()->create(['code' => 'EST-XLSX-KS2-999']);

    $folder = verifyTestDir();
    $manifestPath = "{$folder}/manifest.json";
    file_put_contents($manifestPath, json_encode(['version' => 1, 'fixtures' => [verifyManifestEntry('EST-XLSX-KS2-001', $content)]], JSON_THROW_ON_ERROR));

    $this->artisan('ai:fixtures:verify', ['--manifest' => $manifestPath])->assertExitCode(1);

    File::deleteDirectory($folder);
});
