<?php

declare(strict_types=1);

use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * §8 `docs/specs/v2/08-estimate-extraction.md` — формат папки эталонов (`<name>.<ext>` +
 * `<name>.gold.json`), валидация контрактом P2.2, персистентность вне закупки (D22).
 */
beforeEach(fn () => Platform::factory()->training()->create());

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
