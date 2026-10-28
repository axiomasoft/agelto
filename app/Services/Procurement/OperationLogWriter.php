<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\OperationLogLevel;
use App\Models\Procurement\OperationLog;
use App\Support\Telemetry\TelemetrySanitizer;

/**
 * Legacy журнал callers старого runtime до их cutover (P3.16–P3.19). Не второй источник истины
 * новых work transitions: их единственный writer — WorkFinalizer. Любой текст исключения и detail
 * проходит общий TelemetrySanitizer, а не локальный regex.
 */
final readonly class OperationLogWriter
{
    public function listingRowFailed(int $runId, ?string $registryNumber, \Throwable $exception): OperationLog
    {
        return $this->write('listing', 'crawler_run', $runId, 'Ошибка строки листинга'.($registryNumber === null ? '' : " закупки {$registryNumber}"), $exception->getMessage());
    }

    public function detailFailed(int $procurementId, \Throwable $exception): OperationLog
    {
        return $this->write('detail', 'procurement', $procurementId, 'Снятие карточки не удалось', $exception->getMessage());
    }

    public function documentFailed(int $documentId, \Throwable $exception): OperationLog
    {
        return $this->write('document', 'document', $documentId, 'Скачивание документа не удалось', $exception->getMessage());
    }

    public function budgetExhausted(?int $procurementId): OperationLog
    {
        return $this->write('budget', 'procurement', $procurementId, 'Бюджет запросов исчерпан', null);
    }

    public function proxyDisabled(int $proxyId, string $reason): OperationLog
    {
        return $this->write('proxy', 'proxy', $proxyId, 'Прокси отключён автоматически', $reason);
    }

    private function write(string $stage, string $entityType, ?int $entityId, string $message, ?string $detail): OperationLog
    {
        return OperationLog::create(['level' => OperationLogLevel::Warning, 'stage' => $stage, 'source' => self::class, 'entity_type' => $entityType, 'entity_id' => $entityId, 'message' => $message, 'context' => $detail === null ? null : ['error' => (new TelemetrySanitizer)->text($detail)], 'created_at' => now()]);
    }
}
