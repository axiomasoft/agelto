<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\OperationLogLevel;
use App\Models\Procurement\OperationLog;
use App\Support\SecretRedactor;

final readonly class OperationLogWriter
{
    public function listingRowFailed(int $runId, ?string $registryNumber, \Throwable $exception): OperationLog
    {
        return $this->write('listing', 'crawler_run', $runId, 'Ошибка строки листинга'.($registryNumber === null ? '' : " закупки {$registryNumber}"), SecretRedactor::text($exception->getMessage()));
    }

    public function detailFailed(int $procurementId, \Throwable $exception): OperationLog
    {
        return $this->write('detail', 'procurement', $procurementId, 'Снятие карточки не удалось', SecretRedactor::text($exception->getMessage()));
    }

    public function documentFailed(int $documentId, \Throwable $exception): OperationLog
    {
        return $this->write('document', 'document', $documentId, 'Скачивание документа не удалось', SecretRedactor::text($exception->getMessage()));
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
        return OperationLog::create(['level' => OperationLogLevel::Warning, 'stage' => $stage, 'source' => self::class, 'entity_type' => $entityType, 'entity_id' => $entityId, 'message' => $message, 'context' => $detail === null ? null : ['error' => $detail], 'created_at' => now()]);
    }
}
