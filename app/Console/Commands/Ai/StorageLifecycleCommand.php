<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use Illuminate\Console\Command;
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
 * а не S3 SDK: `aws-sdk-php`/`league/flysystem-aws-s3-v3` в проекте не установлены
 * (`s3`-диск подключён напрямую через Laravel-конфиг), Code Guidance item'а прямо
 * допускает «MinIO ILM — через S3 API ИЛИ mc в отдельном init-контейнере». Бинарник
 * `mc` — в `docker/php/Dockerfile` (multi-stage `COPY --from=minio/mc`, вне буквального
 * Files-списка — необходимая проводка, прецедент qpdf/mpdf в предыдущих item'ах фазы).
 *
 * `mc ilm rule add` САМ по себе НЕ идемпотентен — повторный вызов с теми же `--prefix`/
 * `--expire-days` создаёт ВТОРОЕ правило с новым ID (проверено эмпирически против
 * реального MinIO). Идемпотентность — на уровне этой команды: перед добавлением
 * правила читаем текущие (`mc ilm rule ls --json`) и пропускаем те, чей `prefix` уже
 * зарегистрирован.
 */
final class StorageLifecycleCommand extends Command
{
    protected $signature = 'ai:storage:lifecycle';

    protected $description = 'Идемпотентно применяет MinIO ILM-правила (config(partitioning.minio_lifecycle)) на бакет s3-диска';

    private const string ALIAS = 'agelto-storage-lifecycle';

    public function handle(): int
    {
        $disk = (array) config('filesystems.disks.s3');
        $target = self::ALIAS.'/'.$disk['bucket'];

        $this->mc(['alias', 'set', self::ALIAS, $disk['endpoint'], $disk['key'], $disk['secret']]);

        $existingPrefixes = $this->existingRulePrefixes($target);

        foreach ((array) config('partitioning.minio_lifecycle') as $ruleKey => $rule) {
            if (in_array($rule['prefix'], $existingPrefixes, true)) {
                $this->info("Правило «{$ruleKey}» (префикс {$rule['prefix']}) уже существует — пропуск (идемпотентность).");

                continue;
            }

            $this->mc([
                'ilm', 'rule', 'add', $target,
                '--prefix', $rule['prefix'],
                '--expire-days', (string) $rule['expire_days'],
            ]);

            $this->info("Правило «{$ruleKey}» добавлено: {$rule['prefix']} → expire {$rule['expire_days']}д.");
        }

        return self::SUCCESS;
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

        // Бакет без ЛЮБОЙ lifecycle-конфигурации (первый запуск команды на свежем бакете) —
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
