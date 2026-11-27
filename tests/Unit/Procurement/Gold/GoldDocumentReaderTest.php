<?php

declare(strict_types=1);

use App\Exceptions\Procurement\Ai\GoldFileUnreadable;
use App\Models\Procurement\Ai\Fixture;
use App\Services\Procurement\Gold\GoldDocumentReader;

beforeEach(function (): void {
    $this->goldDirectory = sys_get_temp_dir().'/gold-reader-'.uniqid();
    mkdir($this->goldDirectory);
    config(['ai.fixtures.gold_dir' => $this->goldDirectory]);
});

afterEach(function (): void {
    foreach (glob($this->goldDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->goldDirectory);
});

function goldDocumentPayload(): array
{
    return [
        'estimates' => [[
            'passport' => ['address' => 'Тестовый адрес'],
            'estimate_number' => 'ЛС-1',
            'totals' => ['footer' => [['label' => 'Итого', 'value' => '120.00']]],
            'rows' => [[
                'row_index' => 1,
                'row_type' => 'item',
                'name' => 'Работа',
                'section_title' => 'Раздел 1',
                'resources' => [[
                    'resource_type' => 'material',
                    'name' => 'Материал',
                    'quantity' => '2',
                ]],
            ]],
        ]],
    ];
}

test('читает gold через контрактные DTO', function (): void {
    $fixture = Fixture::factory()->make(['code' => 'EST-XLSX-KS2-001']);
    file_put_contents($this->goldDirectory.'/EST-XLSX-KS2-001.gold.json', json_encode(goldDocumentPayload(), JSON_THROW_ON_ERROR));

    $document = app(GoldDocumentReader::class)->read($fixture);

    expect($document)->not->toBeNull()
        ->and($document->estimates[0]['passport']->estimate_number)->toBe('ЛС-1')
        ->and($document->estimates[0]['rows'][0]->name)->toBe('Работа')
        ->and($document->estimates[0]['rows'][0]->resources[0]->name)->toBe('Материал');
});

test('отсутствующее золото возвращает без исключения', function (): void {
    $fixture = Fixture::factory()->make(['code' => 'EST-XLSX-KS2-001']);

    expect(app(GoldDocumentReader::class)->read($fixture))->toBeNull();
});

test('небезопасный код отказывается читать', function (): void {
    $fixture = Fixture::factory()->make(['code' => '../.env']);

    expect(fn () => app(GoldDocumentReader::class)->read($fixture))
        ->toThrow(GoldFileUnreadable::class, 'небезопасный код');
});

test('неконтрактный gold объясняет ошибку чтения', function (): void {
    $fixture = Fixture::factory()->make(['code' => 'EST-XLSX-KS2-001']);
    file_put_contents($this->goldDirectory.'/EST-XLSX-KS2-001.gold.json', '{bad json');

    expect(fn () => app(GoldDocumentReader::class)->read($fixture))
        ->toThrow(GoldFileUnreadable::class, 'не распарсен');
});
