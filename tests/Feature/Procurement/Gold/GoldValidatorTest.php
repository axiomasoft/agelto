<?php

declare(strict_types=1);

use App\Services\Procurement\Gold\GoldValidator;
use Illuminate\Validation\ValidationException;

function validGoldPayload(): array
{
    return [
        'estimates' => [
            [
                'estimate_number' => 'ЛСР-01',
                'passport' => ['type' => 'local_estimate'],
                'currency' => 'RUB',
                'rows_expected' => 1,
                'rows' => [
                    ['row_index' => 1, 'row_type' => 'item', 'name' => 'Позиция'],
                ],
            ],
        ],
    ];
}

test('валидный gold проходит без ошибок', function () {
    expect((new GoldValidator)->validate(validGoldPayload()))->toBe([]);
});

test('верхний уровень без estimates отклоняется с сообщением из §8.2', function () {
    $errors = (new GoldValidator)->validate(['foo' => 'bar']);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('08-estimate-extraction.md §8.2');
});

test('estimates не являющийся списком объектов отклоняется', function () {
    $errors = (new GoldValidator)->validate(['estimates' => ['не объект']]);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toBe('estimates.0: ожидается объект');
});

test('строка без обязательного name отклоняется адресом estimates.<i>.rows.<j>.name', function () {
    $payload = validGoldPayload();
    unset($payload['estimates'][0]['rows'][0]['name']);

    $errors = (new GoldValidator)->validate($payload);

    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('estimates.0.rows.0.name');
});

test('неизвестный ключ attributes отклоняется тем же приёмом, что DatasetImportCommand', function () {
    $payload = validGoldPayload();
    $payload['estimates'][0]['rows'][0]['attributes'] = ['неизвестный_ключ' => 'x'];

    $errors = (new GoldValidator)->validate($payload);

    expect(implode(' ', $errors))->toContain('Неизвестный ключ attributes');
});

test('assertValid бросает ValidationException с ключом gold на битом payload', function () {
    (new GoldValidator)->assertValid(['foo' => 'bar']);
})->throws(ValidationException::class);

test('assertValid молчит на валидном payload', function () {
    (new GoldValidator)->assertValid(validGoldPayload());
})->throwsNoExceptions();

test('каждый пересобранный эталон корпуса проходит GoldValidator без ошибок', function () {
    $paths = glob(base_path('estimates/gold/*.gold.json'));

    expect($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        $gold = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $errors = (new GoldValidator)->validate($gold);

        expect($errors)->toBe([], basename($path).': '.implode(' | ', $errors));
    }
});
