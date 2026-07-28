<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Gold\GoldFormProfile;
use App\Services\Procurement\Gold\FormProfileReader;

/**
 * Контракт `estimates/gold/<CODE>.profile.json` (version 2, P5.2, D117) — чтение отклоняет
 * разметку, недоказанную СТРУКТУРНО (без книги): неизвестный ключ на любом уровне; версия ≠ 2;
 * `header_cells` отсутствует у объявленной колонки; адрес цитаты вне `header_rows`; ни одна часть
 * цитаты не лежит в своей колонке; `data_row_start` ≤ последней строке `header_rows`; `ordinal`
 * без дыр; `resource_type`/`role` вне enum.
 */
function validProfilePayload(array $overrides = []): array
{
    $estimate = array_merge([
        'ordinal' => 1,
        'sheet' => 'Смета',
        'header_rows' => [1, 1],
        'data_row_start' => 2,
        'data_row_end' => null,
        'columns' => [
            'name' => 'B',
            'quantity' => 'C',
        ],
        'header_cells' => [
            'name' => [['cell' => 'B1', 'text' => 'Наименование']],
            'quantity' => [['cell' => 'C1', 'text' => 'Количество']],
        ],
        'row_rules' => [
            'item_number_column' => 'A',
        ],
        'note' => 'тестовый профиль',
    ], $overrides['estimate'] ?? []);

    return [
        'version' => $overrides['version'] ?? 2,
        'code' => $overrides['code'] ?? 'EST-XLSX-TEST-001',
        'estimates' => $overrides['estimates'] ?? [$estimate],
    ];
}

test('валидный профиль читается без ошибок', function () {
    $profile = (new FormProfileReader)->fromDecoded(validProfilePayload(), 'test');

    expect($profile)->toBeInstanceOf(GoldFormProfile::class)
        ->and($profile->version)->toBe(2)
        ->and($profile->code)->toBe('EST-XLSX-TEST-001')
        ->and($profile->estimates)->toHaveCount(1)
        ->and($profile->estimates[0]->columns->name)->toBe('B');
});

test('неизвестный ключ верхнего уровня отклоняется', function () {
    $payload = validProfilePayload();
    $payload['unexpected'] = 'x';

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'неизвестный ключ');

test('версия не равная 2 отклоняется', function () {
    (new FormProfileReader)->fromDecoded(validProfilePayload(['version' => 1]), 'test');
})->throws(RuntimeException::class, 'версия профиля');

test('неизвестный ключ сметы отклоняется', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['unexpected'] = 'x';

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'неизвестный ключ');

test('неизвестный ключ columns отклоняется', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['columns']['unexpected'] = 'Z';

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'неизвестный ключ');

test('header_cells отсутствует у объявленной колонки — отклонено', function () {
    $payload = validProfilePayload();
    unset($payload['estimates'][0]['header_cells']['quantity']);

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'отсутствует у объявленной колонки');

test('адрес цитаты лежит вне header_rows — чтение профиля отклонено', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['header_cells']['name'] = [['cell' => 'B10', 'text' => 'Наименование']];

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'лежит вне header_rows');

test('ни одна часть цитаты не лежит в самой колонке поля — отклонено', function () {
    $payload = validProfilePayload();
    // колонка quantity объявлена «C», но обе части цитаты указывают на колонку B.
    $payload['estimates'][0]['header_cells']['quantity'] = [['cell' => 'B1', 'text' => 'Количество']];

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'не лежит в самой колонке');

test('data_row_start не больше последней строки header_rows — отклонено', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['header_rows'] = [1, 5];
    $payload['estimates'][0]['data_row_start'] = 3;

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'data_row_start');

test('ordinal без дыр — две сметы 1 и 3 отклонены', function () {
    $payload = validProfilePayload();
    $second = $payload['estimates'][0];
    $second['ordinal'] = 3;
    $payload['estimates'][] = $second;

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'ordinal обязан образовывать 1..N');

test('resource_type вне enum ResourceType — отклонено', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['row_rules']['resource_groups'] = [
        ['label' => 'ОТ(ЗТ)', 'resource_type' => 'не_существует'],
    ];

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'вне enum ResourceType');

test('role вне enum (RowType ∪ ignored) — отклонено', function () {
    $payload = validProfilePayload();
    $payload['estimates'][0]['row_rules']['explicit_rows'] = [
        ['row' => 3, 'role' => 'не_существует', 'text' => 'x'],
    ];

    (new FormProfileReader)->fromDecoded($payload, 'test');
})->throws(RuntimeException::class, 'вне enum (RowType');
