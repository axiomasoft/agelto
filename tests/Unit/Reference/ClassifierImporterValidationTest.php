<?php

declare(strict_types=1);

use App\Services\Reference\ClassifierImporter;

test('строгая проверка выгрузки отклоняет некорректный заголовок или строку', function (string $csv, string $message): void {
    $path = tempnam(sys_get_temp_dir(), 'classifier_');
    assert(is_string($path));

    try {
        file_put_contents($path, $csv);
        expect(fn () => iterator_to_array((new ClassifierImporter)->rows($path)))->toThrow(RuntimeException::class, $message);
    } finally {
        unlink($path);
    }
})->with([
    'пустой код' => ["code,level,name\n,1,Строка\n", 'отсутствует код'],
    'код из пробелов' => ["code,level,name\n\"   \",1,Строка\n", 'отсутствует код'],
    'пустой уровень' => ["code,level\n62.01,\n", 'Некорректный level'],
    'уровень с суффиксом' => ["code,level\n62.01,12x\n", 'Некорректный level'],
    'уровень вне диапазона integer' => ["code,level\n62.01,2147483648\n", 'Некорректный level'],
    'несуществующий день' => ["code,valid_from\n62.01,2025-02-29\n", 'Некорректная дата valid_from'],
    'дата без ведущего нуля' => ["code,valid_to\n62.01,2024-2-29\n", 'Некорректная дата valid_to'],
    'невалидная кодировка заголовка' => ["code,\xFF\n62.01,Строка\n", 'Заголовок выгрузки содержит некорректный UTF-8'],
    'невалидная кодировка неизвестной колонки' => ["code,extra\n62.01,\xC3\n", 'Строка выгрузки содержит некорректный UTF-8'],
]);

test('обратная косая черта перед закрывающей кавычкой остаётся частью значения CSV', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'classifier_');
    assert(is_string($path));

    try {
        file_put_contents($path, "\xEF\xBB\xBFcode,level,name,valid_from,valid_to\n62.01,002,\"Путь\\\",2024-02-29,\n");
        $rows = iterator_to_array((new ClassifierImporter)->rows($path));

        expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray([
            'code' => '62.01', 'level' => 2, 'name' => 'Путь\\', 'valid_from' => '2024-02-29', 'valid_to' => null,
        ]);
    } finally {
        unlink($path);
    }
});
