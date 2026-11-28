<?php

declare(strict_types=1);

use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * контракт манифеста (`FixtureManifestEntry`) и `estimate:fixtures:import`, идемпотентный по
 * sha256. Синтетические манифесты и `Storage::fake(config('ai.fixtures.disk'))`; реальный корпус
 * в тестах не участвует.
 */
function fixtureSourceDir(): string
{
    $folder = sys_get_temp_dir().'/fixtures-import-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

function fixtureManifestEntry(string $code, string $content, array $overrides = []): array
{
    return array_merge([
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
        'document_type' => 'estimate',
        'owner_document_type' => 'estimate',
        'title' => "Эталон {$code}",
        'quirks' => ['TWO_LEVEL_PRICE'],
        'expected_estimates' => 1,
        'expected_rows' => null,
        'gold_source' => 'deterministic',
        'gold_path' => null,
        'is_verified' => false,
        'notes' => null,
    ], $overrides);
}

function writeManifest(string $folder, array $entries): string
{
    $path = "{$folder}/manifest.json";
    file_put_contents($path, json_encode(['version' => 1, 'fixtures' => $entries], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    return $path;
}

test('валидный манифест импортирует файл и запись реестра', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/EST-XLSX-KS2-001.xlsx", $content);
    $manifest = writeManifest($folder, [fixtureManifestEntry('EST-XLSX-KS2-001', $content)]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])
        ->assertExitCode(0);

    expect(Storage::disk(config('ai.fixtures.disk'))->exists('EST-XLSX-KS2-001/source.xlsx'))->toBeTrue()
        ->and(Fixture::query()->where('code', 'EST-XLSX-KS2-001')->exists())->toBeTrue();

    File::deleteDirectory($folder);
});

test('повторный импорт идемпотентен — не плодит файл и не плодит запись', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/EST-XLSX-KS2-001.xlsx", $content);
    $manifest = writeManifest($folder, [fixtureManifestEntry('EST-XLSX-KS2-001', $content)]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])->assertExitCode(0);
    $hashBefore = hash('sha256', Storage::disk(config('ai.fixtures.disk'))->get('EST-XLSX-KS2-001/source.xlsx'));

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])->assertExitCode(0);
    $hashAfter = hash('sha256', Storage::disk(config('ai.fixtures.disk'))->get('EST-XLSX-KS2-001/source.xlsx'));

    expect(Fixture::query()->count())->toBe(1)
        ->and($hashAfter)->toBe($hashBefore);

    File::deleteDirectory($folder);
});

test('sha256-дрейф отклоняется без записи файла и реестра', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/EST-XLSX-KS2-001.xlsx", $content);
    $manifest = writeManifest($folder, [fixtureManifestEntry('EST-XLSX-KS2-001', $content, ['sha256' => str_repeat('a', 64)])]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])
        ->assertExitCode(1);

    expect(Storage::disk(config('ai.fixtures.disk'))->exists('EST-XLSX-KS2-001/source.xlsx'))->toBeFalse()
        ->and(Fixture::query()->exists())->toBeFalse();

    File::deleteDirectory($folder);
});

test('отсутствующий файл-источник отклоняется, остальные записи импортируются', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/EST-XLSX-KS2-002.xlsx", $content);
    $manifest = writeManifest($folder, [
        fixtureManifestEntry('EST-XLSX-KS2-001', 'нет-такого-файла', ['source_path' => 'missing.xlsx']),
        fixtureManifestEntry('EST-XLSX-KS2-002', $content),
    ]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])
        ->assertExitCode(1);

    expect(Fixture::query()->where('code', 'EST-XLSX-KS2-001')->exists())->toBeFalse()
        ->and(Fixture::query()->where('code', 'EST-XLSX-KS2-002')->exists())->toBeTrue();

    File::deleteDirectory($folder);
});

test('код вне CODE_PATTERN отклоняется с названием поля', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/bad-code.xlsx", $content);
    $manifest = writeManifest($folder, [
        fixtureManifestEntry('not-a-valid-code', $content, ['storage_path' => 'not-a-valid-code/source.xlsx', 'source_path' => 'bad-code.xlsx']),
    ]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder])
        ->expectsOutputToContain('fixtures.0.code')
        ->assertExitCode(1);

    expect(Fixture::query()->exists())->toBeFalse();

    File::deleteDirectory($folder);
});

test('--dry-run ничего не пишет ни на диск, ни в БД', function (): void {
    Storage::fake(config('ai.fixtures.disk'));
    $folder = fixtureSourceDir();
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$folder}/EST-XLSX-KS2-001.xlsx", $content);
    $manifest = writeManifest($folder, [fixtureManifestEntry('EST-XLSX-KS2-001', $content)]);

    $this->artisan('estimate:fixtures:import', ['--manifest' => $manifest, '--source-dir' => $folder, '--dry-run' => true])
        ->assertExitCode(0);

    expect(Storage::disk(config('ai.fixtures.disk'))->exists('EST-XLSX-KS2-001/source.xlsx'))->toBeFalse()
        ->and(Fixture::query()->exists())->toBeFalse();

    File::deleteDirectory($folder);
});
