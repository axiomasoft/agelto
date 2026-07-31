<?php

declare(strict_types=1);

use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\RepresentationKind;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Support\Facades\Storage;

// P7.1/D56: экспорт gold-датасета в SFT/DPO JSONL с РЕАЛЬНЫМ текстом представления во входе,
// основанием доверия, DPO-парой по сохранённому analysis_id (не по активному анализу). `--path`
// фиксирован в тестах ради детерминизма (по умолчанию команда подставляет timestamp).

function exportGoldRow(string $name = 'Позиция 1'): array
{
    return [
        'tier' => 1,
        'estimates' => [
            [
                'estimate_number' => '1',
                'ordinal' => 1,
                'passport' => ['type' => 'local_estimate'],
                'currency' => 'RUB',
                'base_year' => null,
                'price_level' => null,
                'rows_expected' => 1,
                'totals' => ['footer' => []],
                'rows' => [
                    ['row_index' => 0, 'row_type' => 'item', 'name' => $name, 'section_title' => null, 'code' => null, 'unit' => null, 'quantity' => null, 'unit_price' => null, 'total_price' => null, 'resources' => [], 'modifiers' => []],
                ],
            ],
        ],
    ];
}

function putTextRepresentation(DocumentVersion $version, string $text, RepresentationKind $kind = RepresentationKind::Text): void
{
    $path = "documents/aa/{$version->id}-{$kind->value}.txt";
    Storage::disk('s3')->put($path, $text);

    DocumentRepresentation::factory()->create([
        'document_version_id' => $version->id,
        'kind' => $kind,
        'storage_path' => $path,
        'disk' => null,
        'mime' => 'text/plain',
    ]);
}

/**
 * Файл разбиения `estimates/eval-splits.json` в ИЗОЛИРОВАННОМ временном пути (config-override,
 * тот же приём, что `tests/Feature/Ai/EvalRunTest.php`).
 *
 * @param  list<string>  $holdout
 * @param  list<string>  $tune
 */
function exportWriteSplitsFile(array $holdout, array $tune = []): void
{
    $path = storage_path('framework/testing/eval-splits-'.uniqid().'.json');

    file_put_contents($path, json_encode([
        'generated_at' => '2026-07-31T00:00:00+00:00',
        'rule' => 'test-fixture',
        'holdout' => $holdout,
        'tune' => $tune,
    ], JSON_UNESCAPED_UNICODE));

    config(['ai.fixtures.splits_path' => $path]);
}

test('SFT-строка несёт ТЕКСТ представления, а не ссылку на документ', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create(['filename' => 'estimate.pdf']);
    putTextRepresentation($version, 'СТРОКА 1 | СТРОКА 2 | ИТОГО');

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'gold' => exportGoldRow('Позиция для SFT'),
        'source' => 'llm',
        'verified_at' => now(),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
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
    expect($userContent)->toContain('СТРОКА 1 | СТРОКА 2 | ИТОГО')
        ->and($userContent)->not->toContain('storage_path=')
        ->and($userContent)->not->toContain('document_version_id=');

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['format'])->toBe('sft')
        ->and($manifest['schema'])->toBe('sft-messages')
        ->and($manifest['examples'])->toBe(1)
        ->and($manifest['by_representation_kind']['text'])->toBe(1)
        ->and($manifest['sha256'])->not->toBeEmpty();
});

test('запись без текстового представления пропущена с причиной no_textual_representation', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'gold' => exportGoldRow(),
        'source' => 'deterministic',
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['examples'])->toBe(0)
        ->and($manifest['skipped']['no_textual_representation'])->toBe(1);
});

test('запись source=llm с пустым verified_at не экспортируется (not_trusted)', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'текст документа');

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'gold' => exportGoldRow(),
        'source' => 'llm',
        'verified_at' => null,
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['examples'])->toBe(0)
        ->and($manifest['skipped']['not_trusted'])->toBe(1);
});

test('запись source=deterministic без verified_at экспортируется', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'текст документа');

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'gold' => exportGoldRow(),
        'source' => 'deterministic',
        'verified_at' => null,
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['examples'])->toBe(1);
});

test('при двух версиях gold одной версии документа в SFT уходит только последняя', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'текст документа');

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'version' => 1,
        'gold' => exportGoldRow('Версия 1'),
        'source' => 'llm',
        'verified_at' => now(),
    ]);
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'version' => 2,
        'gold' => exportGoldRow('Версия 2'),
        'source' => 'llm',
        'verified_at' => now(),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/sft.jsonl'))));
    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], associative: true, flags: JSON_THROW_ON_ERROR);
    $assistant = json_decode((string) $decoded['messages'][2]['content'], associative: true, flags: JSON_THROW_ON_ERROR);
    expect($assistant['rows'][0]['name'])->toBe('Версия 2')
        ->and($decoded['meta']['example_version'])->toBe(2);
});

test('DPO-пара строится по analysis_id записи, а не по активному анализу — две версии gold с разными предшественниками', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'текст документа');

    $parent0 = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'result' => exportGoldRow('RAW-0'),
        'superseded_at' => now(),
    ]);
    $child1 = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $parent0->id,
        'result' => exportGoldRow('RAW-1'),
        'superseded_at' => now(),
    ]);
    $child2 = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $child1->id,
    ]);

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'analysis_id' => $child1->id,
        'task' => 'extraction',
        'version' => 1,
        'source' => 'corrected',
        'verified_at' => now(),
        'gold' => exportGoldRow('CORRECTED-1'),
    ]);
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'analysis_id' => $child2->id,
        'task' => 'extraction',
        'version' => 2,
        'source' => 'corrected',
        'verified_at' => now(),
        'gold' => exportGoldRow('CORRECTED-2'),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'dpo', '--path' => 'exports/dpo.jsonl'])
        ->assertExitCode(0);

    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/dpo.jsonl'))));
    expect($lines)->toHaveCount(2);

    $pairs = array_map(fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);
    $byVersion = collect($pairs)->keyBy(fn (array $pair): int => $pair['meta']['example_version']);

    expect($byVersion[1]['chosen'][0]['content'])->toContain('CORRECTED-1')
        ->and($byVersion[1]['rejected'][0]['content'])->toContain('RAW-0')
        ->and($byVersion[2]['chosen'][0]['content'])->toContain('CORRECTED-2')
        ->and($byVersion[2]['rejected'][0]['content'])->toContain('RAW-1');
});

test('--dpo-schema=standard даёт три плоские строки', function (): void {
    Storage::fake('s3');

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'текст документа');

    $parent = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'result' => exportGoldRow('RAW'),
        'superseded_at' => now(),
    ]);
    $child = Analysis::factory()->create([
        'document_version_id' => $version->id,
        'step' => AnalysisStep::Extraction,
        'parent_analysis_id' => $parent->id,
    ]);

    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'analysis_id' => $child->id,
        'task' => 'extraction',
        'source' => 'corrected',
        'verified_at' => now(),
        'gold' => exportGoldRow('CORRECTED'),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'dpo', '--dpo-schema' => 'standard', '--path' => 'exports/dpo.jsonl'])
        ->assertExitCode(0);

    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/dpo.jsonl'))));
    $pair = json_decode($lines[0], associative: true, flags: JSON_THROW_ON_ERROR);

    expect($pair['prompt'])->toBeString()
        ->and($pair['chosen'])->toBeString()->toContain('CORRECTED')
        ->and($pair['rejected'])->toBeString()->toContain('RAW');

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/dpo.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['schema'])->toBe('dpo-standard');
});

test('--split=holdout отбирает только коды holdout', function (): void {
    Storage::fake('s3');

    $holdoutFixture = Fixture::factory()->create(['code' => 'EST-XLSX-AAA-001', 'type_profile' => ['class' => FixtureClass::Estimate->value, 'form' => 'AAA', 'gold_source' => 'deterministic']]);
    $tuneFixture = Fixture::factory()->create(['code' => 'EST-XLSX-BBB-001', 'type_profile' => ['class' => FixtureClass::Estimate->value, 'form' => 'BBB', 'gold_source' => 'deterministic']]);
    exportWriteSplitsFile(holdout: [$holdoutFixture->code], tune: [$tuneFixture->code]);

    $holdoutVersion = DocumentVersion::factory()->create();
    putTextRepresentation($holdoutVersion, 'holdout-текст');
    DatasetExample::factory()->create([
        'document_version_id' => $holdoutVersion->id,
        'fixture_id' => $holdoutFixture->id,
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => exportGoldRow('Holdout'),
    ]);

    $tuneVersion = DocumentVersion::factory()->create();
    putTextRepresentation($tuneVersion, 'tune-текст');
    DatasetExample::factory()->create([
        'document_version_id' => $tuneVersion->id,
        'fixture_id' => $tuneFixture->id,
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => exportGoldRow('Tune'),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--split' => 'holdout', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $lines = array_values(array_filter(explode("\n", (string) Storage::disk('s3')->get('exports/sft.jsonl'))));
    expect($lines)->toHaveCount(1);

    $decoded = json_decode($lines[0], associative: true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['meta']['fixture_code'])->toBe('EST-XLSX-AAA-001')
        ->and($decoded['meta']['split'])->toBe('holdout');

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['by_split'])->toBe(['holdout' => 1]);
});

test('повторный прогон на неизменённых данных даёт байт-идентичный JSONL', function (): void {
    Storage::fake('s3');
    $this->travelTo(now()->setDateTime(2026, 7, 31, 12, 0, 0));

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, 'стабильный текст документа');
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => exportGoldRow('Стабильная позиция'),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/run1.jsonl'])->assertExitCode(0);
    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/run2.jsonl'])->assertExitCode(0);

    expect(Storage::disk('s3')->get('exports/run1.jsonl'))->toBe(Storage::disk('s3')->get('exports/run2.jsonl'));

    $this->travelBack();
});

test('вход длиннее потолка пропущен с причиной input_too_large', function (): void {
    Storage::fake('s3');
    config(['ai.dataset_export.max_input_chars' => 10]);

    $version = DocumentVersion::factory()->create();
    putTextRepresentation($version, str_repeat('А', 20));
    DatasetExample::factory()->create([
        'document_version_id' => $version->id,
        'task' => 'extraction',
        'source' => 'deterministic',
        'gold' => exportGoldRow(),
    ]);

    $this->artisan('estimate:dataset:export', ['--format' => 'sft', '--path' => 'exports/sft.jsonl'])
        ->assertExitCode(0);

    $manifest = json_decode((string) Storage::disk('s3')->get('exports/sft.manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['examples'])->toBe(0)
        ->and($manifest['skipped']['input_too_large'])->toBe(1);
});
