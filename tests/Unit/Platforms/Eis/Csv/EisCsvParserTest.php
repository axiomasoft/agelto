<?php

declare(strict_types=1);

use App\Services\Platforms\Eis\Csv\EisCsvParser;

test('потоковый CSV сохраняет 500 записей, CR/LF, разделители и экранированные кавычки', function (string $newline) {
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['Реестровый номер закупки', 'Дата размещения', 'Дата обновления', 'Название'], ';', '"', '', $newline);
    $title = "Ремонт\r\nпомещения\nэтаж; \"первый\"\rконец";
    for ($i = 1; $i <= 500; $i++) {
        fputcsv($stream, [(string) $i, '01.01.2025', '02.01.2025', $title], ';', '"', '', $newline);
    }
    rewind($stream);
    $rows = (new EisCsvParser)->parse(stream_get_contents($stream));
    fclose($stream);

    expect($rows)->toHaveCount(500)
        ->and($rows[249]['Реестровый номер закупки'])->toBe('250')
        ->and($rows[249]['Название'])->toBe($title);
})->with(["\n", "\r\n", "\r"]);

test('валидный CSV только с заголовком означает пустую выдачу', function () {
    expect((new EisCsvParser)->parse('Реестровый номер закупки;Дата размещения;Дата обновления'))->toBe([]);
});

test('повреждённая структура CSV не превращается в неполную или пустую выдачу', function (string $body) {
    expect(fn () => (new EisCsvParser)->parse($body))->toThrow(UnexpectedValueException::class);
})->with([
    'empty' => [''],
    'html' => ['<html>Access denied</html>'],
    'missing date header' => ['Реестровый номер закупки;Дата размещения'],
    'duplicate header' => ['Реестровый номер закупки;Дата размещения;Дата обновления;Дата обновления'],
    'short row' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n1;01.01.2025"],
    'blank record' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n\n"],
    'long row' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n1;01.01.2025;02.01.2025;лишнее"],
    'open quote' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n1;\"01.01.2025;02.01.2025"],
    'quote in plain field' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n1;01\".01.2025;02.01.2025"],
    'garbage after quote' => ["Реестровый номер закупки;Дата размещения;Дата обновления\n1;\"01.01.2025\"x;02.01.2025"],
]);
