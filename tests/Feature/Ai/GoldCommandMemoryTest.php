<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

test('сборка и импорт gold восстанавливают лимит памяти после успеха и ошибки', function (string $command, string $memoryConfig, array $options): void {
    $original = ini_get('memory_limit');
    ini_set('memory_limit', '1G');

    try {
        config()->set($memoryConfig, '512M');
        config()->set('ai.fixtures.gold_dir', sys_get_temp_dir());

        expect(Artisan::call($command, $options))->toBe(0)
            ->and(ini_get('memory_limit'))->toBe('1G');

        config()->set('ai.fixtures.gold_dir', null);
        expect(fn (): int => Artisan::call($command, $options))->toThrow(UnexpectedValueException::class)
            ->and(ini_get('memory_limit'))->toBe('1G');
    } finally {
        if ($original !== false) {
            ini_set('memory_limit', $original);
        }
    }
})->with([
    ['estimate:gold:build', 'ai.prepare.xlsx_convert_memory_limit', ['--screening' => true, '--dry-run' => true, '--code' => ['ABSENT']]],
    ['estimate:gold:import', 'ai.prepare.gold_import_memory_limit', ['--dry-run' => true, '--code' => ['ABSENT']]],
]);
