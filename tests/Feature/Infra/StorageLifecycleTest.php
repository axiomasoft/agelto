<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;

/**
 * `mc ilm rule add` сам по себе НЕ идемпотентен (повтор с теми же параметрами создаёт
 * ВТОРОЕ правило) — идемпотентность держит сама команда (`StorageLifecycleCommand`).
 * Тест бьёт в РЕАЛЬНЫЙ dev-MinIO (тот же приём, что `PdfPageSlicerTest` — реальный
 * `qpdf`, не мок) с ОТДЕЛЬНЫМ тестовым префиксом, не пересекающимся с боевым
 * `partitioning.minio_lifecycle` — `afterEach` подчищает созданные правила.
 */
function storageLifecycleTarget(): string
{
    return 'agelto-storage-lifecycle/'.config('filesystems.disks.s3.bucket');
}

/**
 * @return array<int, array{ID: string, Filter: array{Prefix: string}}>
 */
function storageLifecycleRulesByPrefix(string $prefix): array
{
    $result = Process::run(['mc', 'ilm', 'rule', 'ls', storageLifecycleTarget(), '--json']);
    $payload = json_decode($result->output(), true) ?? [];

    if (($payload['status'] ?? null) !== 'success') {
        return [];
    }

    return array_values(array_filter(
        $payload['config']['Rules'] ?? [],
        fn (array $rule): bool => ($rule['Filter']['Prefix'] ?? null) === $prefix,
    ));
}

afterEach(function (): void {
    foreach (storageLifecycleRulesByPrefix('ai/llm-calls-test/') as $rule) {
        Process::run(['mc', 'ilm', 'rule', 'remove', storageLifecycleTarget(), '--id', $rule['ID']]);
    }
});

test('ai:storage:lifecycle применяет ILM-правило на пустом бакете (первый прогон, нет lifecycle-конфигурации)', function (): void {
    Config::set('partitioning.minio_lifecycle', [
        'test_rule' => ['prefix' => 'ai/llm-calls-test/', 'expire_days' => 90],
    ]);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);

    $rules = storageLifecycleRulesByPrefix('ai/llm-calls-test/');

    expect($rules)->toHaveCount(1);
    expect($rules[0]['Expiration']['Days'] ?? null)->toBe(90);
});

test('ai:storage:lifecycle идемпотентна — повторный вызов не плодит дублирующихся правил', function (): void {
    Config::set('partitioning.minio_lifecycle', [
        'test_rule' => ['prefix' => 'ai/llm-calls-test/', 'expire_days' => 90],
    ]);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);

    expect(storageLifecycleRulesByPrefix('ai/llm-calls-test/'))->toHaveCount(1);
});

test('ai:storage:lifecycle без правил в конфиге — no-op, успешный код возврата', function (): void {
    Config::set('partitioning.minio_lifecycle', []);

    expect(Artisan::call('ai:storage:lifecycle'))->toBe(0);
});
