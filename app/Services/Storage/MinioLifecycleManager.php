<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Идемпотентно применяет MinIO ILM (Object Lifecycle Management) правила из
 * `config('partitioning.minio_lifecycle')` (P7.7, D33) на бакет S3-диска —
 * expire по возрасту для `request_ref`/`response_ref`-блобов КАЖДОГО LLM-вызова
 * (`LlmCallRecorder::storeRaw()`, префикс `ai/llm-calls/`), которые иначе копятся
 * в MinIO без политики (research/L finding 4).
 *
 * Через `mc` CLI (Process-обвязка, тот же приём, что `PdfPageSlicer`/qpdf, P5.1),
 * а не S3 SDK: `aws-sdk-php`/`league/flysystem-aws-s3-v3` в проекте не установлены.
 *
 * `mc ilm rule add` САМ по себе НЕ идемпотентен — повторный вызов с теми же `--prefix`/
 * `--expire-days` создаёт ВТОРОЕ правило с новым ID (проверено эмпирически против
 * реального MinIO). Идемпотентность — на уровне этого сервиса: перед добавлением
 * правила читаем текущие (`mc ilm rule ls --json`) и пропускаем те, чей `prefix` уже
 * зарегистрирован.
 */
final readonly class MinioLifecycleManager
{
    private const string ALIAS = 'agelto-storage-lifecycle';

    /**
     * @return list<array{key: string, prefix: string, expire_days: int, applied: bool}>
     */
    public function apply(): array
    {
        $disk = (array) config('filesystems.disks.s3');
        $target = self::ALIAS.'/'.$disk['bucket'];

        $this->mc(['alias', 'set', self::ALIAS, $disk['endpoint'], $disk['key'], $disk['secret']]);

        $existingPrefixes = $this->existingRulePrefixes($target);
        $report = [];

        foreach ((array) config('partitioning.minio_lifecycle') as $ruleKey => $rule) {
            if (in_array($rule['prefix'], $existingPrefixes, true)) {
                $report[] = ['key' => (string) $ruleKey, 'prefix' => $rule['prefix'], 'expire_days' => $rule['expire_days'], 'applied' => false];

                continue;
            }

            $this->mc([
                'ilm', 'rule', 'add', $target,
                '--prefix', $rule['prefix'],
                '--expire-days', (string) $rule['expire_days'],
            ]);

            $report[] = ['key' => (string) $ruleKey, 'prefix' => $rule['prefix'], 'expire_days' => $rule['expire_days'], 'applied' => true];
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    private function existingRulePrefixes(string $target): array
    {
        $result = Process::run(['mc', 'ilm', 'rule', 'ls', $target, '--json']);
        $payload = json_decode($result->output(), true) ?? [];

        if (($payload['status'] ?? null) === 'success') {
            return array_values(array_map(
                static fn (array $rule): string => (string) ($rule['Filter']['Prefix'] ?? ''),
                $payload['config']['Rules'] ?? [],
            ));
        }

        // Бакет без ЛЮБОЙ lifecycle-конфигурации (первый запуск на свежем бакете) —
        // легитимный старт, не ошибка; любой другой код ошибки — реальная проблема (bucket
        // не существует, авторизация не прошла и т.п.), её нужно видеть.
        $code = $payload['error']['cause']['error']['Code'] ?? null;

        if ($code === 'NoSuchLifecycleConfiguration') {
            return [];
        }

        throw new RuntimeException(
            'mc ilm rule ls: '.($payload['error']['message'] ?? trim($result->errorOutput())),
        );
    }

    /**
     * @param  list<string>  $args
     */
    private function mc(array $args): void
    {
        $result = Process::run(array_merge(['mc'], $args));

        if ($result->failed()) {
            throw new RuntimeException('mc '.implode(' ', $args).': '.trim($result->errorOutput() ?: $result->output()));
        }
    }
}
