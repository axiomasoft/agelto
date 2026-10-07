<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Классификация отказа блока листинга: прокси / площадка / парсер.
 *
 * Классификатор — чистая функция над тем, что уже известно после запроса (HTTP-статус,
 * число записей, результат маппинга) или над исключением транспорта. HTTP-ретраи с прокси
 * к этому моменту уже исчерпаны (`CsvSourceMethod::requestWithRetries`), поэтому отказ —
 * факт про блок, а не про одну попытку.
 */
final readonly class ListingFailureClassifier
{
    /**
     * @param  int  $records  записей в ответе источника
     * @param  int  $mapped  записей, ставших снимками
     * @param  int  $rowFailures  записей, не прошедших маппинг или инжест
     */
    public function classifyResponse(int $httpStatus, int $records, int $mapped, int $rowFailures): ?ListingFailureKind
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return $this->classifyStatus(status: $httpStatus);
        }

        if ($records > 0 && $mapped === 0) {
            return ListingFailureKind::UnrecognizedFormat;
        }

        return $rowFailures > 0 ? ListingFailureKind::RowErrors : null;
    }

    public function classifyStatus(int $status): ListingFailureKind
    {
        return match (true) {
            $status === 407 => ListingFailureKind::ProxyFailure,
            in_array($status, [401, 403, 429, 451], true) => ListingFailureKind::SourceBlocked,
            $status === 408, $status >= 500 => ListingFailureKind::SourceUnavailable,
            default => ListingFailureKind::UnexpectedStatus,
        };
    }

    /**
     * Транспортный отказ без HTTP-ответа. `null` — исключение не про источник (ошибка кода,
     * БД, lease) и должно подниматься дальше как есть.
     */
    public function classifyException(Throwable $exception): ?ListingFailureKind
    {
        if ($exception instanceof NoProxyAvailableException) {
            return ListingFailureKind::ProxyPoolExhausted;
        }

        if (! $exception instanceof ConnectionException) {
            return null;
        }

        // cURL различает отказ прокси-туннеля (CONNECT/407, «proxy») и отказ самой цели.
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'proxy') || str_contains($message, 'connect tunnel')
            ? ListingFailureKind::ProxyFailure
            : ListingFailureKind::SourceUnavailable;
    }
}
