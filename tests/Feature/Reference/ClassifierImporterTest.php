<?php

declare(strict_types=1);

use App\Services\Reference\ClassifierImporter;

test('ClassifierImporter пропускает пустую строку и отдаёт обе строки данных', function () {
    $importer = app(ClassifierImporter::class);
    $path = tempnam(sys_get_temp_dir(), 'clf_blank_');
    assert(is_string($path));

    try {
        file_put_contents($path, implode("\n", [
            'code,parent_code,level,name',
            '41.20,41,2,Строительство',
            '',
            '42.11,42,2,Дороги',
            '',
        ]));

        $rows = iterator_to_array($importer->rows($path));

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['code'])->toBe('41.20')
            ->and($rows[0]['name'])->toBe('Строительство')
            ->and($rows[1]['code'])->toBe('42.11')
            ->and($rows[1]['name'])->toBe('Дороги');
    } finally {
        @unlink($path);
    }
});
