<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Enums\Procurement\Process\ClaimStatus;
use Illuminate\Support\Facades\Log;

/**
 * Sanitized non-throwing telemetry Process runtime. Пишет только bounded коды и IDs: класс
 * исключения и SQLSTATE, но не message, SQL bindings, payload и secrets. Ошибка logger не
 * ломает ни ACK job, ни committed ledger state, и ничего в authority не меняет.
 */
final class ProcessLog
{
    public static function dependencyUnavailable(string $dependency, \Throwable $failure): void
    {
        self::write('warning', 'process.dependency_unavailable', ['dependency' => $dependency, ...self::failure($failure)]);
    }

    public static function jobFailed(string $workId, int $dispatchGeneration, ?\Throwable $failure): void
    {
        self::write('error', 'process.job_failed', [
            'work_id' => $workId, 'dispatch_generation' => $dispatchGeneration, ...($failure === null ? [] : self::failure($failure)),
        ]);
    }

    /** Best-effort delivery_rejected: arrival counter диагностики, не durable work counter. */
    public static function deliveryRejected(string $workId, int $dispatchGeneration, ClaimStatus $reason): void
    {
        self::write('info', 'process.delivery_rejected', ['work_id' => $workId, 'dispatch_generation' => $dispatchGeneration, 'reason' => $reason->value]);
    }

    /** @param array<string, int|string> $context */
    public static function quarantined(string $code, array $context): void
    {
        self::write('error', 'process.quarantined', ['code' => $code, ...$context]);
    }

    /** @param array<string, int|string> $context */
    public static function daemonError(string $daemon, string $code, ?\Throwable $failure = null, array $context = []): void
    {
        self::write('error', 'process.daemon_error', ['daemon' => $daemon, 'code' => $code, ...$context, ...($failure === null ? [] : self::failure($failure))]);
    }

    /** Optional sink упал после commit: видимо, но не меняет исход работы и не повторяет handler. */
    public static function sinkFailed(string $sink, \Throwable $failure): void
    {
        self::write('warning', 'process.sink_failed', ['sink' => $sink, ...self::failure($failure)]);
    }

    /** @return array{exception: class-string<\Throwable>, sqlstate?: string} */
    private static function failure(\Throwable $failure): array
    {
        $context = ['exception' => $failure::class];
        if ($failure instanceof \PDOException) {
            $state = $failure->errorInfo[0] ?? $failure->getCode();
            if (is_string($state) && strlen($state) === 5) {
                $context['sqlstate'] = $state;
            }
        }

        return $context;
    }

    /** @param array<string, mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            // Telemetry никогда не меняет исход delivery или tick.
        }
    }
}
