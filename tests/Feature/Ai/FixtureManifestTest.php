<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * P2.4 — генератор `estimates/manifest.json` из шорт-листа P1.3. Синтетический шорт-лист и
 * временный `source_dir`; реальный корпус в тестах не участвует.
 */
function manifestTestDir(): string
{
    $folder = sys_get_temp_dir().'/fixtures-manifest-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

function manifestShortlistRow(string $code, string $content, array $overrides = []): array
{
    return array_merge([
        'code' => $code,
        'class' => 'EST',
        'cluster_id' => 'CL-01',
        'expected_estimates' => 1,
        'expected_rows' => null,
        'form' => 'KS2',
        'format' => 'XLSX',
        'generator' => 'ГРАНД-Смета',
        'gold_source' => 'deterministic',
        'is_verified' => false,
        'quirks' => ['TWO_LEVEL_PRICE'],
        'selection_reason' => 'тестовый образец',
        'sha256' => hash('sha256', $content),
        'size_bytes' => strlen($content),
        'source_path' => "{$code}.xlsx",
    ], $overrides);
}

test('генератор строит валидный манифест из шорт-листа', function (): void {
    $sourceDir = manifestTestDir();
    config(['ai.fixtures.source_dir' => $sourceDir]);
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$sourceDir}/EST-XLSX-KS2-001.xlsx", $content);
    $shortlistPath = "{$sourceDir}/shortlist.json";
    file_put_contents($shortlistPath, json_encode([manifestShortlistRow('EST-XLSX-KS2-001', $content)], JSON_THROW_ON_ERROR));
    $manifestPath = "{$sourceDir}/manifest.json";

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])
        ->assertExitCode(0);

    $manifest = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['fixtures'])->toHaveCount(1)
        ->and($manifest['fixtures'][0]['code'])->toBe('EST-XLSX-KS2-001')
        ->and($manifest['fixtures'][0]['storage_path'])->toBe('EST-XLSX-KS2-001/source.xlsx')
        ->and($manifest['fixtures'][0]['gold_path'])->toBeNull();

    File::deleteDirectory($sourceDir);
});

test('генератор оставляет gold_path = null даже при gold_source = deterministic', function (): void {
    $sourceDir = manifestTestDir();
    config(['ai.fixtures.source_dir' => $sourceDir]);
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$sourceDir}/EST-XLSX-KS2-001.xlsx", $content);
    $shortlistPath = "{$sourceDir}/shortlist.json";
    file_put_contents(
        $shortlistPath,
        json_encode([manifestShortlistRow('EST-XLSX-KS2-001', $content, ['gold_source' => 'deterministic'])], JSON_THROW_ON_ERROR),
    );
    $manifestPath = "{$sourceDir}/manifest.json";

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])
        ->assertExitCode(0);

    $manifest = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['fixtures'][0]['gold_source'])->toBe('deterministic')
        ->and($manifest['fixtures'][0]['gold_path'])->toBeNull();

    File::deleteDirectory($sourceDir);
});

test('повторная генерация идемпотентна', function (): void {
    $sourceDir = manifestTestDir();
    config(['ai.fixtures.source_dir' => $sourceDir]);
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$sourceDir}/EST-XLSX-KS2-001.xlsx", $content);
    $shortlistPath = "{$sourceDir}/shortlist.json";
    file_put_contents($shortlistPath, json_encode([manifestShortlistRow('EST-XLSX-KS2-001', $content)], JSON_THROW_ON_ERROR));
    $manifestPath = "{$sourceDir}/manifest.json";

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])->assertExitCode(0);
    $first = file_get_contents($manifestPath);

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])->assertExitCode(0);
    $second = file_get_contents($manifestPath);

    expect($second)->toBe($first);

    File::deleteDirectory($sourceDir);
});

test('ручная правка существующей записи не затирается без --force', function (): void {
    $sourceDir = manifestTestDir();
    config(['ai.fixtures.source_dir' => $sourceDir]);
    $content = 'XLSX-DUMMY-CONTENT';
    file_put_contents("{$sourceDir}/EST-XLSX-KS2-001.xlsx", $content);
    $shortlistPath = "{$sourceDir}/shortlist.json";
    file_put_contents($shortlistPath, json_encode([manifestShortlistRow('EST-XLSX-KS2-001', $content)], JSON_THROW_ON_ERROR));
    $manifestPath = "{$sourceDir}/manifest.json";

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])->assertExitCode(0);

    $manifest = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
    $manifest['fixtures'][0]['notes'] = 'ручная правка оператора';
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    $this->artisan('estimate:fixtures:manifest', ['--from-shortlist' => $shortlistPath, '--manifest' => $manifestPath])->assertExitCode(0);

    $reread = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($reread['fixtures'][0]['notes'])->toBe('ручная правка оператора');

    File::deleteDirectory($sourceDir);
});
