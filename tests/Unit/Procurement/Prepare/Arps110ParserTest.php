<?php

declare(strict_types=1);

use App\Services\Procurement\Prepare\Arps\Arps110Parser;

/** @param array<int, string> $values */
function arpsRecord(string $type, array $values = []): string
{
    $fields = array_fill(0, 27, '');
    $fields[0] = $type;
    foreach ($values as $index => $value) {
        $fields[$index] = $value;
    }

    return implode('#', $fields);
}

function arpsFixture(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'arps_');
    if ($path === false || file_put_contents($path, $bytes) === false) {
        throw new RuntimeException('Cannot create ARPS test fixture.');
    }

    return $path;
}

test('разбирает паспорт разделы позиции и подчинённые записи в русских кодировках', function (string $encoding): void {
    $lines = [
        '1#АРПС 1.10#',
        '0#Комментарий',
        arpsRecord('3', [1 => 'ЛСР-1', 2 => 'Объект']),
        arpsRecord('10', [1 => '1', 2 => 'Земляные работы']),
        arpsRecord('20', [
            2 => 'ФЕР01', 3 => '1000 м2', 4 => 'Разработка', 15 => '1200,00', 16 => '300,00',
            17 => '50,00', 18 => '10,00', 19 => '840,00', 23 => '4,5', 24 => '0,5', 26 => '0,00355',
        ]),
        arpsRecord('20', [
            2 => 'М-1', 3 => 'т', 4 => 'Кирпич', 17 => '0,00', 19 => '75,00', 23 => '0', 24 => '', 26 => '2',
        ]),
        arpsRecord('25', [2 => 'К-1', 4 => 'Коэффициент']),
        arpsRecord('30', [2 => 'Р-1', 3 => 'м3', 4 => 'Дополнительный ресурс']),
        arpsRecord('20', [2 => 'ФЕР02', 3 => 'м3', 4 => 'Вторая позиция', 17 => '1', 19 => '25', 26 => '3']),
    ];
    $utf8 = implode("\r\n", $lines)."\r\n";
    $bytes = $encoding === 'UTF-8' ? $utf8 : mb_convert_encoding($utf8, $encoding, 'UTF-8');
    $path = arpsFixture($bytes);
    try {
        $parsed = (new Arps110Parser)->parse($path);
        expect($parsed['passport']['fields'][1])->toBe('ЛСР-1')
            ->and($parsed['sections'])->toHaveCount(1)
            ->and($parsed['sections'][0]['name'])->toBe('Земляные работы')
            ->and($parsed['items'])->toHaveCount(2)
            ->and($parsed['items'][0]['code'])->toBe('ФЕР01')
            ->and($parsed['items'][0]['unit'])->toBe('1000 м2')
            ->and($parsed['items'][0]['quantity'])->toBe('0,00355')
            ->and($parsed['items'][0]['direct_cost'])->toBe('1200,00')
            ->and($parsed['items'][0]['section_num'])->toBe('1')
            ->and($parsed['items'][0]['resources'])->toHaveCount(2)
            ->and($parsed['items'][0]['resources'][0]['code'])->toBe('М-1')
            ->and($parsed['items'][0]['resources'][0]['materials_cost'])->toBe('75,00')
            ->and($parsed['items'][0]['resources'][1]['record_type'])->toBe('30')
            ->and($parsed['items'][0]['coefficients'][0]['record_type'])->toBe('25')
            ->and($parsed['items'][1]['code'])->toBe('ФЕР02');
    } finally {
        unlink($path);
    }
})->with([
    'UTF-8' => ['UTF-8'],
    'Windows-1251' => ['Windows-1251'],
    'CP866' => ['CP866'],
]);

test('считает материальную строку без родителя самостоятельной позицией', function (): void {
    $path = arpsFixture("1#АРПС 1.10#\n".arpsRecord('20', [2 => 'М-1', 17 => '0', 19 => '10', 23 => '', 24 => '0'])."\n");
    try {
        $parsed = (new Arps110Parser)->parse($path);
        expect($parsed['items'])->toHaveCount(1)
            ->and($parsed['items'][0]['code'])->toBe('М-1')
            ->and($parsed['items'][0]['resources'])->toBe([]);
    } finally {
        unlink($path);
    }
});

test('закрывает текущую позицию при переходе к следующему разделу', function (): void {
    $lines = [
        '1#АРПС 1.10#',
        arpsRecord('10', [1 => '1', 2 => 'Первый раздел']),
        arpsRecord('20', [2 => 'ФЕР01', 4 => 'Первая позиция']),
        arpsRecord('10', [1 => '2', 2 => 'Второй раздел']),
        arpsRecord('20', [2 => 'ФЕР02', 4 => 'Вторая позиция']),
        arpsRecord('30', [2 => 'Р-2', 4 => 'Ресурс второго раздела']),
    ];
    $path = arpsFixture(implode("\n", $lines)."\n");
    try {
        $parsed = (new Arps110Parser)->parse($path);
        expect($parsed['sections'])->toHaveCount(2)
            ->and($parsed['items'])->toHaveCount(2)
            ->and($parsed['items'][0]['section_num'])->toBe('1')
            ->and($parsed['items'][0]['resources'])->toBe([])
            ->and($parsed['items'][1]['section_num'])->toBe('2')
            ->and($parsed['items'][1]['resources'][0]['code'])->toBe('Р-2');
    } finally {
        unlink($path);
    }
});

test('отклоняет отсутствие сигнатуры в первой непустой строке', function (string $contents): void {
    $path = arpsFixture($contents);
    try {
        expect(fn () => (new Arps110Parser)->parse($path))->toThrow(RuntimeException::class);
    } finally {
        unlink($path);
    }
})->with([
    "0#Комментарий\n1#АРПС 1.10#\n",
    "1#АРПС 1.09#\n",
    "\n\n",
]);

test('отклоняет подчинённую запись без текущей позиции и небезопасный путь', function (): void {
    $path = arpsFixture("1#АРПС 1.10#\n".arpsRecord('30', [2 => 'Р-1'])."\n");
    try {
        expect(fn () => (new Arps110Parser)->parse($path))->toThrow(RuntimeException::class, 'no current position')
            ->and(fn () => (new Arps110Parser)->parse('phar://'.$path))->toThrow(RuntimeException::class, 'local file');
    } finally {
        unlink($path);
    }
});

test('отклоняет слишком длинную строку без загрузки всего файла', function (): void {
    $path = arpsFixture("1#АРПС 1.10#\n20#".str_repeat('x', 65536)."\n");
    try {
        expect(fn () => (new Arps110Parser)->parse($path))->toThrow(RuntimeException::class, 'size limit');
    } finally {
        unlink($path);
    }
});
