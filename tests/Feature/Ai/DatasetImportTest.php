<?php

declare(strict_types=1);

use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\StoredDocumentFixtures;

/**
 * §8 `docs/dev/ai/estimate-extraction.md` — формат папки эталонов (`<name>.<ext>` +
 * `<name>.gold.json`), валидация контрактом , персистентность вне закупки.
 */
beforeEach(function (): void {
    StoredDocumentFixtures::reset();
    Platform::query()->firstOrCreate(['code' => Platform::TRAINING_CODE], Platform::factory()->training()->raw());
});

function datasetFixtureFolder(): string
{
    $folder = sys_get_temp_dir().'/dataset-import-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

function validGoldJson(string $estimateNumber = '1'): string
{
    return json_encode([
        'estimates' => [
            [
                'estimate_number' => $estimateNumber,
                'passport' => ['type' => 'local_estimate'],
                'currency' => 'RUB',
                'base_year' => 2001,
                'price_level' => null,
                'rows_expected' => 1,
                'totals' => ['footer' => [['label' => 'Итого', 'value' => '200.00']]],
                'rows' => [
                    [
                        'row_index' => 1,
                        'row_type' => 'item',
                        'name' => 'Устройство ввода',
                        'code' => '111-222',
                        'unit' => 'шт',
                        'quantity' => '2',
                        'unit_price' => '100.00',
                        'total_price' => '200.00',
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

test('импорт валидной пары создаёт Document/DocumentVersion вне закупки и DatasetExample с gold', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/estimate-1.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/estimate-1.gold.json", validGoldJson());

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->assertExitCode(0);

    $document = Document::query()->where('external_id', 'gold:estimate-1')->sole();
    expect($document->procurement_id)->toBeNull()
        ->and($document->status)->toBe(DocumentStatus::Downloaded);

    $version = DocumentVersion::query()->where('document_id', $document->id)->sole();
    expect($version->filename)->toBe('estimate-1.pdf');

    $example = DatasetExample::query()->where('document_version_id', $version->id)->sole();
    expect($example->task)->toBe('extraction')
        ->and($example->source)->toBe('manual')
        ->and($example->version)->toBe(1)
        ->and($example->gold['estimates'][0]['rows'][0]['name'])->toBe('Устройство ввода')
        ->and($example->gold['estimates'][0]['rows'][0]['row_type'])->toBe('item');

    File::deleteDirectory($folder);
});

test('повторный импорт той же пары идемпотентен — обновляет gold, не плодит Document/DatasetExample', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/estimate-1.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/estimate-1.gold.json", validGoldJson(estimateNumber: '1'));

    $this->artisan('estimate:dataset:import', ['path' => $folder])->assertExitCode(0);

    file_put_contents("{$folder}/estimate-1.gold.json", validGoldJson(estimateNumber: '1-corrected'));
    $this->artisan('estimate:dataset:import', ['path' => $folder])->assertExitCode(0);

    expect(Document::query()->where('external_id', 'gold:estimate-1')->count())->toBe(1)
        ->and(DatasetExample::query()->count())->toBe(1);

    $example = DatasetExample::query()->sole();
    expect($example->gold['estimates'][0]['estimate_number'])->toBe('1-corrected');

    File::deleteDirectory($folder);
});

test('битый gold-эталон (строка без обязательного name) отклоняется с ошибками, ничего не персистится', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/broken.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/broken.gold.json", json_encode([
        'estimates' => [
            [
                'passport' => ['type' => 'local_estimate'],
                'rows' => [
                    ['row_index' => 1, 'row_type' => 'item'], // нет обязательного "name"
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->assertExitCode(1);

    expect(Document::query()->where('external_id', 'gold:broken')->exists())->toBeFalse()
        ->and(DatasetExample::query()->count())->toBe(0);

    File::deleteDirectory($folder);
});

/** Эталон с пометкой неопределённости описывает ответ модели, а не строку БД. */
function goldJsonWithQuality(array $quality): string
{
    $decoded = json_decode(validGoldJson(), associative: true, flags: JSON_THROW_ON_ERROR);
    $decoded['estimates'][0]['rows'][0]['quality'] = $quality;

    return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

test('эталон с пометкой неопределённости импортируется и пометка доезжает в gold как есть', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/marked.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/marked.gold.json", goldJsonWithQuality([
        'reason' => 'unreadable',
        'fields' => ['quantity', 'total_price_curr'],
    ]));

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->assertExitCode(0);

    $example = DatasetExample::query()->sole();

    // Порядок ключей внутри пометки задаёт jsonb (нормализует их сам), поэтому сверяются значения.
    expect($example->gold['estimates'][0]['rows'][0]['quality']['reason'])->toBe('unreadable')
        ->and($example->gold['estimates'][0]['rows'][0]['quality']['fields'])->toBe(['quantity', 'total_price_curr']);

    File::deleteDirectory($folder);
});

test('эталон БЕЗ пометки остаётся валидным — quality необязателен', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/clean.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/clean.gold.json", validGoldJson());

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->assertExitCode(0);

    $example = DatasetExample::query()->sole();

    expect($example->gold['estimates'][0]['rows'][0]['quality'])->toBeNull();

    File::deleteDirectory($folder);
});

test('битая пометка отклоняется поимённо — неизвестный ключ, неизвестная причина, причина не model-origin, пустой перечень полей', function (array $quality, string $expectedField) {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/broken-quality.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/broken-quality.gold.json", goldJsonWithQuality($quality));

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->expectsOutputToContain("estimates.0.rows.0.{$expectedField}")
        ->assertExitCode(1);

    expect(Document::query()->where('external_id', 'gold:broken-quality')->exists())->toBeFalse()
        ->and(DatasetExample::query()->count())->toBe(0);

    File::deleteDirectory($folder);
})->with([
    'неизвестный ключ внутри quality' => [['reason' => 'unreadable', 'fields' => ['quantity'], 'confidence' => 0.5], 'quality'],
    'неизвестная причина' => [['reason' => 'blurry', 'fields' => ['quantity']], 'quality.reason'],
    'причина вне model-origin подмножества' => [['reason' => 'arithmetic_mismatch', 'fields' => ['quantity']], 'quality.reason'],
    'пустой перечень полей' => [['reason' => 'unreadable', 'fields' => []], 'quality.fields'],
]);

test('осиротевший *.gold.json без файла-источника отклоняется без падения всего импорта', function () {
    Storage::fake('s3');
    $folder = datasetFixtureFolder();
    file_put_contents("{$folder}/orphan.gold.json", validGoldJson());
    file_put_contents("{$folder}/estimate-ok.pdf", 'PDF-DUMMY-CONTENT');
    file_put_contents("{$folder}/estimate-ok.gold.json", validGoldJson());

    $this->artisan('estimate:dataset:import', ['path' => $folder])
        ->assertExitCode(1);

    expect(Document::query()->where('external_id', 'gold:orphan')->exists())->toBeFalse()
        ->and(Document::query()->where('external_id', 'gold:estimate-ok')->exists())->toBeTrue();

    File::deleteDirectory($folder);
});
