<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Models\Procurement\Estimate\Item;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function requiredFieldsAuditGoldDirectory(): string
{
    $directory = sys_get_temp_dir().'/required-fields-audit-'.Str::uuid();
    File::ensureDirectoryExists($directory);
    config(['ai.fixtures.gold_dir' => $directory]);

    return $directory;
}

/** @return array<string, array{items: int, fields: array<string, array{filled: int, completeness: float}>}> */
function requiredFieldsGoldTree(): array
{
    $fields = ['source_position_number', 'code', 'name', 'unit', 'quantity'];
    $tree = [];
    $paths = glob(base_path('estimates/gold/*.gold.json')) ?: [];
    sort($paths);

    foreach ($paths as $path) {
        $gold = json_decode((string) file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ((array) ($gold['estimates'] ?? []) as $estimate) {
            $type = $estimate['passport']['type'] ?? 'unspecified';

            foreach ((array) ($estimate['rows'] ?? []) as $row) {
                if (($row['row_type'] ?? null) !== 'item') {
                    continue;
                }

                $tree[$type] ??= ['items' => 0, 'fields' => array_fill_keys($fields, ['filled' => 0])];
                $tree[$type]['items']++;

                foreach ($fields as $field) {
                    if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') {
                        $tree[$type]['fields'][$field]['filled']++;
                    }
                }
            }
        }
    }

    foreach ($tree as &$branch) {
        foreach ($branch['fields'] as &$field) {
            $field['completeness'] = $field['filled'] / $branch['items'];
        }
    }

    return $tree;
}

test('ревизия gold-корпуса печатает машинное дерево заполненности', function (): void {
    $directory = requiredFieldsAuditGoldDirectory();

    try {
        file_put_contents("{$directory}/EST-XLSX-AUDIT-001.gold.json", json_encode([
            'estimates' => [[
                'passport' => ['type' => 'act'],
                'rows' => [
                    ['row_type' => 'section_header'],
                    [
                        'row_type' => 'item',
                        'source_position_number' => '1',
                        'code' => '',
                        'name' => 'Позиция',
                        'unit' => 'м2',
                        'quantity' => '2',
                    ],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('estimate:fields:audit', ['--source' => 'gold', '--json' => true])
            ->expectsOutputToContain('"source": "gold"')
            ->assertExitCode(0);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('ревизия database читает позиции без записи в БД', function (): void {
    $estimate = Estimate::factory()->create(['passport' => ['type' => 'local_estimate']]);
    Item::factory()->for($estimate)->create([
        'source_position_number' => '1',
        'code' => null,
        'name' => 'Позиция',
        'unit' => 'м3',
        'quantity' => '2.000000',
    ]);

    $this->artisan('estimate:fields:audit', ['--source' => 'database', '--json' => true])
        ->expectsOutputToContain('"source": "database"')
        ->assertExitCode(0);

    expect(Item::query()->count())->toBe(1);
});

test('ревизия отклоняет неизвестный источник', function (): void {
    $this->artisan('estimate:fields:audit', ['--source' => 'unknown'])
        ->expectsOutput('Источник должен быть gold или database.')
        ->assertExitCode(1);
});

test('gold-корпус не опускается ниже зафиксированного замера P7.4 от 2026-08-08', function (): void {
    $artifact = json_decode((string) file_get_contents(base_path('plans/archive/2026.08.06-CRAWL-HARDEN/artifacts/P7.4-fields-audit.json')), associative: true, flags: JSON_THROW_ON_ERROR);
    $actualTree = requiredFieldsGoldTree();

    expect($artifact['command'])->toBe('docker compose exec app php artisan estimate:fields:audit --source=gold --json')
        ->and($artifact['measured_at'])->toBe('2026-08-08');

    foreach ($artifact['tree'] as $type => $branch) {
        foreach ($branch['fields'] as $field => $metrics) {
            expect($actualTree[$type]['fields'][$field]['completeness'])
                ->toBeGreaterThanOrEqual($metrics['completeness']);
        }
    }
});
