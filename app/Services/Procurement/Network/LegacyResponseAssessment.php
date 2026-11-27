<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Exceptions\Procurement\Crawler\InvalidListingResponse;
use App\Exceptions\Procurement\Crawler\SourceResponseLimitExceeded;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Единственное место, где отказ получает вид (`SourceFailureKind`). Только детерминированные
 * признаки: тип исключения, HTTP-статус, проверка формата. Текст ошибок и «похожесть» тела не
 * анализируются.
 *
 * Это публичная классификация surviving callers; сетевое и adapter health она не меняет.
 */
final readonly class LegacyResponseAssessment
{
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

    /** Публичный вид не-2xx; health attribution остаётся в RequestClassifier. */
    public function classifyStatus(int $status): SourceFailureKind
    {
        return match (true) {
            $status === 407 => SourceFailureKind::ProxyUnavailable,
            in_array($status, [401, 403, 429, 451], true) => SourceFailureKind::SourceBlocked,
            $status === 404 => SourceFailureKind::NotFound,
            $status === 408 || $status >= 500 => SourceFailureKind::SourceUnavailable,
            default => SourceFailureKind::UnexpectedStatus,
        };
    }

    /**
     * Исключение при скачивании/записи блока листинга. Не сеть и не статус — ошибка нашего
     * кода (`CodeError`); повтором сохранённого входа владеет существующий прогон.
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
            $exception instanceof OutboundUnavailable => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException => SourceFailureKind::SourceUnavailable,
            $exception instanceof RequestException => $this->classifyStatus($exception->response->status()),
            default => SourceFailureKind::CodeError,
        };
    }

    /**
     * Исключение при обработке карточки. Сеть/прокси/чужая страница (после штатных ретраев
     * общего executor) — недоступность зависимости; остальные исключения обозначают
     * ошибку обработки. Этот классификатор не пишет состояние здоровья.
     */
    public function classifyCardException(Throwable $exception): SourceFailureKind
    {
        return match (true) {
            $exception instanceof SourceResponseLimitExceeded => SourceFailureKind::InvalidContent,
            $exception instanceof OutboundUnavailable => SourceFailureKind::ProxyUnavailable,
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
