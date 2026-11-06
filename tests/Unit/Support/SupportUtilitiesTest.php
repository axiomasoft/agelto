<?php

declare(strict_types=1);

use App\Enums\Telemetry\LogChannel;
use App\Services\Procurement\Prepare\ToolchainProbe;
use App\Services\Storage\MinioLifecycleManager;
use App\Support\Files\MimeDetector;
use App\Support\Files\ShardedBlobPath;
use App\Support\SecretValue;
use Illuminate\Support\Facades\Process;

test('SecretValue разрешает секрет из файла _FILE, переменной окружения или значения по умолчанию', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'secret-unit-');
    expect($file)->toBeString();

    try {
        file_put_contents($file, "file-secret-value\r\n");
        putenv("AGELTO_UNIT_SECRET_FILE={$file}");
        putenv('AGELTO_UNIT_SECRET=env-secret-value');

        expect(SecretValue::resolve('AGELTO_UNIT_SECRET', 'fallback'))->toBe('file-secret-value');

        putenv('AGELTO_UNIT_SECRET_FILE');
        expect(SecretValue::resolve('AGELTO_UNIT_SECRET', 'fallback'))->toBe('env-secret-value');

        putenv('AGELTO_UNIT_SECRET');
        expect(SecretValue::resolve('AGELTO_UNIT_SECRET', 'fallback'))->toBe('fallback');
    } finally {
        putenv('AGELTO_UNIT_SECRET_FILE');
        putenv('AGELTO_UNIT_SECRET');
        @unlink($file);
    }
});

test('ShardedBlobPath строит шардированный путь documents по первым двум символам SHA-256', function (): void {
    $sha = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    expect(ShardedBlobPath::for($sha))->toBe("documents/ab/{$sha}");
});

test('MimeDetector определяет MIME-тип по байтам содержимого', function (): void {
    expect(MimeDetector::of(''))->toBe('application/x-empty')
        ->and(MimeDetector::of("%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"))->toContain('pdf');
});

test('MinioLifecycleManager передаёт MC_HOST окружение при чтении существующих правил и добавляет только отсутствующие префиксы', function (): void {
    config([
        'filesystems.disks.s3' => [
            'bucket' => 'agelto-test',
            'endpoint' => 'http://minio.internal:9000',
            'key' => 'minio-user',
            'secret' => 'minio-secret',
        ],
        'partitioning.minio_lifecycle' => [
            'llm_calls' => ['prefix' => 'ai/llm-calls/', 'expire_days' => 30],
            'exports' => ['prefix' => 'exports/tmp/', 'expire_days' => 7],
        ],
    ]);

    Process::fake([
        '*ilm*ls*' => Process::result(output: json_encode([
            'status' => 'success',
            'config' => [
                'Rules' => [
                    ['Filter' => ['Prefix' => 'ai/llm-calls/']],
                ],
            ],
        ], JSON_THROW_ON_ERROR)),
        '*ilm*add*' => Process::result(output: '{"status":"success"}'),
    ]);

    $report = app(MinioLifecycleManager::class)->apply();

    expect($report)->toBe([
        ['key' => 'llm_calls', 'prefix' => 'ai/llm-calls/', 'expire_days' => 30, 'applied' => false],
        ['key' => 'exports', 'prefix' => 'exports/tmp/', 'expire_days' => 7, 'applied' => true],
    ]);

    Process::assertRan(fn ($process): bool => in_array('ls', $process->command, true)
        && ($process->environment['MC_HOST_agelto-storage-lifecycle'] ?? null) === 'http://minio-user:minio-secret@minio.internal:9000');
});

test('ToolchainProbe разбирает первую строку версии каждого системного инструмента', function (): void {
    Process::fake([
        '*' => Process::result(output: "Tool v1.2.3\nSecond line ignored\n"),
    ]);

    $rows = app(ToolchainProbe::class)->probeAll();

    expect($rows)->toHaveKeys(['soffice_bin', 'sevenzip_bin', 'unar_bin', 'tesseract_bin', 'ocrmypdf_bin', 'pdftotext_bin', 'pdfinfo_bin', 'gs_bin'])
        ->and($rows['soffice_bin']['found'])->toBeTrue()
        ->and($rows['soffice_bin']['version'])->toBe('Tool v1.2.3');
});

test('LogChannel ранжирует уровни логирования по критичности и сопоставляет агрегированные каналы', function (): void {
    expect(LogChannel::rankForLevel('info'))->toBe(LogChannel::RANK_TELEMETRY)
        ->and(LogChannel::rankForLevel('debug'))->toBe(LogChannel::RANK_TELEMETRY)
        ->and(LogChannel::rankForLevel('warning'))->toBe(LogChannel::RANK_DEGRADATION)
        ->and(LogChannel::rankForLevel('error'))->toBe(LogChannel::RANK_INCIDENT)
        ->and(LogChannel::rankForLevel('critical'))->toBe(LogChannel::RANK_INCIDENT)
        ->and(LogChannel::rankedChannelForLevel('info'))->toBeNull()
        ->and(LogChannel::rankedChannelForLevel('warning'))->toBe(LogChannel::WARNINGS_CHANNEL)
        ->and(LogChannel::rankedChannelForLevel('error'))->toBe(LogChannel::ERRORS_CHANNEL);
});
