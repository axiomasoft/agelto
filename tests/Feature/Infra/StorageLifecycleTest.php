<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

/**
 * `mc ilm rule add` сам по себе НЕ идемпотентен (повтор с теми же параметрами создаёт
 * ВТОРОЕ правило) — идемпотентность держит сама команда (`StorageLifecycleCommand`).
 * Тест использует явный `Process::fake()`-эмулятор `mc`: глобальный guard не
 * допускает, чтобы feature-test неявно получил реальный S3/MinIO adapter.
 */
function storageLifecycleDisk(): array
{
    return [
        'bucket' => 'agelto-test-only',
        'endpoint' => 'http://minio.test:9000',
        'key' => 'test-key',
        'secret' => 'test-secret',
    ];
}

/** @param array<int, array{ID: string, Filter: array{Prefix: string}, Expiration: array{Days: int}}> $rules */
function fakeStorageLifecycleProcess(array &$rules): void
{
    Process::fake(function ($process) use (&$rules) {
        $command = $process->command;

        if ($command[0] !== 'mc' || $command[1] !== 'ilm' || $command[2] !== 'rule') {
            throw new RuntimeException('Unexpected process command in MinIO lifecycle test.');
        }

        if ($command[3] === 'ls') {
            return Process::result(output: json_encode([
                'status' => 'success',
                'config' => ['Rules' => $rules],
            ], JSON_THROW_ON_ERROR));
        }

        if ($command[3] === 'add') {
            $prefix = $command[array_search('--prefix', $command, true) + 1] ?? null;
            $days = $command[array_search('--expire-days', $command, true) + 1] ?? null;

            if (! is_string($prefix) || ! is_string($days)) {
                throw new RuntimeException('MinIO lifecycle add command is malformed.');
            }

            $rules[] = [
                'ID' => 'rule-'.count($rules),
                'Filter' => ['Prefix' => $prefix],
                'Expiration' => ['Days' => (int) $days],
            ];

            return Process::result();
        }

        throw new RuntimeException('Unexpected MinIO lifecycle operation.');
    });
}

beforeEach(function (): void {
    Config::set('filesystems.disks.s3', storageLifecycleDisk());
});

test('ai:storage:lifecycle применяет ILM-правило на пустом бакете (первый прогон, нет lifecycle-конфигурации)', function (): void {
    $rules = [];
    fakeStorageLifecycleProcess($rules);
    Config::set('partitioning.minio_lifecycle', [
        'test_rule' => ['prefix' => 'ai/llm-calls-test/', 'expire_days' => 90],
    ]);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);

    expect($rules)->toHaveCount(1);
    expect($rules[0]['Expiration']['Days'] ?? null)->toBe(90);
});

test('ai:storage:lifecycle идемпотентна — повторный вызов не плодит дублирующихся правил', function (): void {
    $rules = [];
    fakeStorageLifecycleProcess($rules);
    Config::set('partitioning.minio_lifecycle', [
        'test_rule' => ['prefix' => 'ai/llm-calls-test/', 'expire_days' => 90],
    ]);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);

    expect($rules)->toHaveCount(1);
});

test('ai:storage:lifecycle без правил в конфиге — no-op, успешный код возврата', function (): void {
    $rules = [];
    fakeStorageLifecycleProcess($rules);
    Config::set('partitioning.minio_lifecycle', []);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
    expect($rules)->toBeEmpty();
});

test('ai:storage:lifecycle не передаёт S3 credentials в аргументах mc', function (): void {
    $disk = (array) config('filesystems.disks.s3');
    $rules = [];
    fakeStorageLifecycleProcess($rules);
    Config::set('partitioning.minio_lifecycle', []);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);

    Process::assertRan(function ($process) use ($disk): bool {
        $command = $process->command;

        return ! in_array($disk['key'], $command, true)
            && ! in_array($disk['secret'], $command, true);
    });
});
