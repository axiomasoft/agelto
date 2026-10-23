<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Exceptions\Procurement\Crawler\InvalidListingResponse;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Единственное место, где отказ получает вид (`SourceFailureKind`). Только детерминированные
 * признаки: тип исключения, HTTP-статус, проверка формата. Текст ошибок и «похожесть» тела не
 * анализируются.
 *
 * Статус → вид задаёт конфиг (`crawler.source_control.http_status_kinds`), а не этот класс.
 */
final readonly class SourceFailureClassifier
{
    public function __construct(private SourceControlPolicy $policy) {}

    /**
     * Ответ блока листинга. `null` — блок принят без замечаний.
     *
     * @param  list<string>  $missingColumns
     */
    public function classifyResponse(int $httpStatus, int $records, int $mapped, int $rowFailures, array $missingColumns = [], bool $emptyBody = false): ?SourceFailureKind
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return $this->classifyStatus(status: $httpStatus);
        }

        return match (true) {
            $emptyBody => SourceFailureKind::EmptyBody,
            $missingColumns !== [] => SourceFailureKind::LayoutDrift,
            $records > 0 && $mapped === 0 => SourceFailureKind::InvalidContent,
            $rowFailures > 0 => SourceFailureKind::RowErrors,
            default => null,
        };
    }

    /** Не-2xx → вид отказа по `crawler.source_control.http_status_kinds`. */
    public function classifyStatus(int $status): SourceFailureKind
    {
        return $this->policy->kindForStatus($status);
    }

    /**
     * Исключение при скачивании/записи блока листинга. Не сеть и не статус — ошибка нашего
     * кода (`CodeError`); что с ней делать, решает конфиг (по умолчанию — пропуск с записью).
     */
    public function classifyListingException(Throwable $exception): SourceFailureKind
    {
        return match (true) {
            $exception instanceof InvalidListingResponse => match (true) {
                $exception->batch->httpStatus < 200 || $exception->batch->httpStatus >= 300 => $this->classifyStatus($exception->batch->httpStatus),
                $exception->batch->emptyBody => SourceFailureKind::EmptyBody,
                $exception->reason === 'invalid_csv_header' => SourceFailureKind::LayoutDrift,
                default => SourceFailureKind::InvalidContent,
            },
            $exception instanceof SourceResponseLimitExceeded => SourceFailureKind::InvalidContent,
            $exception instanceof NoProxyAvailableException => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException => SourceFailureKind::SourceUnavailable,
            $exception instanceof RequestException => $this->classifyStatus($exception->response->status()),
            default => SourceFailureKind::CodeError,
        };
    }

    /**
     * Исключение при обработке карточки. Сеть/прокси/чужая страница (после штатных ретраев
     * `requestWithRetries` и ротации прокси резолвером) — недоступность площадки; всё
     * остальное — ошибка нашего кода, брейкер её не считает.
     */
    public function classifyCardException(Throwable $exception): SourceFailureKind
    {
        return match (true) {
            $exception instanceof SourceResponseLimitExceeded => SourceFailureKind::InvalidContent,
            $exception instanceof NoProxyAvailableException => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException => SourceFailureKind::SourceUnavailable,
            $exception instanceof RequestException => $this->classifyStatus($exception->response->status()),
            // Не-2xx — по статусу (404 → NotFound); 2xx без номера — «чужая страница» (Д-8).
            $exception instanceof CardPageMismatchException => $exception->status >= 300
                ? $this->classifyStatus($exception->status)
                : SourceFailureKind::SourceUnavailable,
            default => SourceFailureKind::CodeError,
        };
    }
}
