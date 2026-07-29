<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Storage;

// P9.3: экспорт gold-датасета в SFT/DPO JSONL (D15/D16). `--path` фиксирован в тестах ради
// детерминизма (по умолчанию команда подставляет timestamp).

function exportGoldRow(string $name = 'Позиция 1'): array
{
    return [
        'tier' => 1,
        'estimates' => [
            [
                'estimate_number' => '1',
                'passport' => ['type' => 'local_estimate'],
                'currency' => 'RUB',
                'base_year' => null,
                'price_level' => null,
                'rows_expected' => 1,
                'totals' => ['footer' => []],
                'ordinal' => 1,
                'rows' => [
                    ['row_index' => 0, 'row_type' => 'item', 'name' => $name, 'section_title' => null, 'code' => null, 'unit' => null, 'quantity' => null, 'unit_price' => null, 'total_price' => null, 'resources' => [], 'modifiers' => []],
                ],
            ],
        ],
    ];
}

test('ai:dataset:export --format=sft пишет JSONL (system/user/assistant) и манифест', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create(['filename' => 'estimate.pdf']);
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'gold' => exportGoldRow('Позиция для SFT'),
        'source' => 'llm',
    ]);

    $this->artisan('ai:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    Storage::disk('s3')->assertExists('exports/sft.jsonl');
    Storage::disk('s3')->assertExists('exports/sft.manifest.json');

    $lines = array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/sft.jsonl')));
    expect($lines)->toHaveCount(1);

    $decoded = json_decode((string) array_values($lines)[0], associative: true, flags: JSON_THROW_ON_ERROR);
    $roles = array_column($decoded['messages'], 'role');

    expect($roles)->toBe(['system', 'user', 'assistant']);

    $assistant = json_decode((string) $decoded['messages'][2]['content'], associative: true, flags: JSON_THROW_ON_ERROR);
    expect($assistant['rows'][0]['name'])->toBe('Позиция для SFT');

    $userContent = $decoded['messages'][1]['content'];
    expect($userContent)->toContain((string) $version->id)
        ->and($userContent)->toContain('estimate.pdf');

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['format'])->toBe('sft')
        ->and($manifest['examples'])->toBe(1)
        ->and($manifest['sha256'])->not->toBeEmpty();
});

test('ai:dataset:export --format=dpo отдаёт пару chosen/rejected только когда сырой результат анализа отличался от gold', function (): void {
    Storage::fake('s3');

    $changedVersion = DocumentVersion::factory()->create();
    $parent = Analysis::factory()->create([
        'document_version_id' => $changedVersion->id,
        'step' => AnalysisStep::Extraction,
        'superseded_at' => now(),
        'result' => exportGoldRow('Позиция (сырая)'),
    ]);
    Analysis::factory()->create([
        'document_version_id' => $changedVersion->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $parent->id,
    ]);
    DatasetExample::factory()->create([
        'document_version_id' => $changedVersion->id,
        'task' => 'extraction',
        'gold' => exportGoldRow('Позиция (исправлена)'),
        'source' => 'corrected',
    ]);

    $unchangedVersion = DocumentVersion::factory()->create();
    $unchangedParent = Analysis::factory()->create([
        'document_version_id' => $unchangedVersion->id,
        'step' => AnalysisStep::Extraction,
        'superseded_at' => now(),
        'result' => exportGoldRow('Позиция (без изменений)'),
    ]);
    Analysis::factory()->create([
        'document_version_id' => $unchangedVersion->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $unchangedParent->id,
    ]);
    DatasetExample::factory()->create([
        'document_version_id' => $unchangedVersion->id,
        'task' => 'extraction',
        'gold' => exportGoldRow('Позиция (без изменений)'),
        'source' => 'llm',
    ]);

    $this->artisan('ai:dataset:export', ['--format' => 'dpo', '--path' => 'exports/dpo.jsonl'])
        ->assertExitCode(0);

    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/dpo.jsonl'))));
    expect($lines)->toHaveCount(1);

    $pair = json_decode($lines[0], associative: true, flags: JSON_THROW_ON_ERROR);
    expect($pair['prompt'])->toContain((string) $changedVersion->id)
        ->and($pair['chosen'])->toContain('Позиция (исправлена)')
        ->and($pair['rejected'])->toContain('Позиция (сырая)');

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/dpo.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['examples'])->toBe(1);
});
