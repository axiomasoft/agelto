<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Применяет MinIO lifecycle rules из `partitioning.minio_lifecycle` для S3 disk.
 * Префикс ai/llm-calls/ содержит request/response blobs вызовов LLM и истекает по возрасту.
 *
 * Использует mc CLI. Перед mc ilm rule add читает существующие правила через
 * mc ilm rule ls --json и пропускает уже зарегистрированный prefix, поскольку само add
 * при повторе создаёт отдельное правило.
 */
final readonly class MinioLifecycleManager
{
    private const string ALIAS = 'agelto-storage-lifecycle';

    /**
     * @return list<array{key: string, prefix: string, expire_days: int, applied: bool}>
     */
    public function apply(): array
    {
        $disk = $this->s3Disk();
        $target = self::ALIAS.'/'.$disk['bucket'];

        $existingPrefixes = $this->existingRulePrefixes($target);
        $report = [];

        foreach ($this->lifecycleRules() as $ruleKey => $rule) {
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
        $payload = json_decode($result->output(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('mc ilm rule ls returned invalid JSON.');
        }

        if (($payload['status'] ?? null) === 'success') {
            $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
            $rules = $config['Rules'] ?? [];

            if (! is_array($rules)) {
                throw new RuntimeException('mc ilm rule ls returned an invalid Rules list.');
            }

            $prefixes = [];

            foreach ($rules as $rule) {
                if (! is_array($rule) || ! is_array($rule['Filter'] ?? null)) {
                    continue;
                }

                $prefix = $rule['Filter']['Prefix'] ?? null;

                if (is_string($prefix)) {
                    $prefixes[] = $prefix;
                }
            }

            return $prefixes;
        }

        // Бакет без ЛЮБОЙ lifecycle-конфигурации (первый запуск на свежем бакете) —
        // легитимный старт, не ошибка; любой другой код ошибки — реальная проблема (bucket
        // не существует, авторизация не прошла и т.п.), её нужно видеть.
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $cause = is_array($error['cause'] ?? null) ? $error['cause'] : [];
        $nestedError = is_array($cause['error'] ?? null) ? $cause['error'] : [];
        $code = $nestedError['Code'] ?? null;

        if ($code === 'NoSuchLifecycleConfiguration') {
            return [];
        }

        throw new RuntimeException(
            'mc ilm rule ls: '.(is_string($error['message'] ?? null) ? $error['message'] : trim($result->errorOutput())),
        );
    }

    /**
     * @param  list<string>  $args
     */
    private function mc(array $args): void
    {
        $result = Process::env([
            'MC_HOST_'.self::ALIAS => $this->aliasUrl(),
        ])->run(array_merge(['mc'], $args));

        if ($result->failed()) {
            throw new RuntimeException('mc '.implode(' ', $args).': '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    private function aliasUrl(): string
    {
        $disk = $this->s3Disk();
        $endpoint = rtrim($disk['endpoint'], '/');

        if ($endpoint === '') {
            throw new RuntimeException('S3 endpoint is required for MinIO lifecycle management.');
        }

        $parts = parse_url($endpoint);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('S3 endpoint must be an absolute URL for MinIO lifecycle management.');
        }

        $port = isset($parts['port']) ? ':'.$this->intValue($parts['port'], 'endpoint port') : '';
        $path = is_string($parts['path'] ?? null) ? $parts['path'] : '';
        $key = rawurlencode($disk['key']);
        $secret = rawurlencode($disk['secret']);

        return "{$parts['scheme']}://{$key}:{$secret}@{$parts['host']}{$port}{$path}";
    }

    /** @return array{bucket: string, endpoint: string, key: string, secret: string} */
    private function s3Disk(): array
    {
        $disk = config('filesystems.disks.s3');

        if (! is_array($disk)) {
            throw new RuntimeException('S3 disk configuration is required for MinIO lifecycle management.');
        }

        return [
            'bucket' => $this->stringValue($disk['bucket'] ?? null, 'S3 bucket'),
            'endpoint' => $this->stringValue($disk['endpoint'] ?? null, 'S3 endpoint'),
            'key' => $this->stringValue($disk['key'] ?? null, 'S3 access key'),
            'secret' => $this->stringValue($disk['secret'] ?? null, 'S3 secret key'),
        ];
    }

    /** @return array<string, array{prefix: string, expire_days: int}> */
    private function lifecycleRules(): array
    {
        $rules = config('partitioning.minio_lifecycle');

        if (! is_array($rules)) {
            throw new RuntimeException('MinIO lifecycle configuration must be an object.');
        }

        $result = [];

        foreach ($rules as $key => $rule) {
            if (! is_string($key) || ! is_array($rule)) {
                throw new RuntimeException('MinIO lifecycle rule must have a string key and object value.');
            }

            $result[$key] = [
                'prefix' => $this->stringValue($rule['prefix'] ?? null, "MinIO lifecycle rule {$key}.prefix"),
                'expire_days' => $this->intValue($rule['expire_days'] ?? null, "MinIO lifecycle rule {$key}.expire_days"),
            ];
        }

        return $result;
    }

    private function stringValue(mixed $value, string $context): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("{$context} must be a non-empty string.");
        }

        return $value;
    }

    private function intValue(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("{$context} must be an integer.");
    }
}
