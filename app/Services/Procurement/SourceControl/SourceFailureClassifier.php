<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Procurement\Exceptions\CardPageMismatchException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Единственное место, где отказ получает вид (`SourceFailureKind`). Только детерминированные
 * признаки: тип исключения, HTTP-статус, проверка формата. Текст ошибок и «похожесть» тела не
 * анализируются.
 *
 * Ревьюеру: если какой-то статус должен реагировать иначе (например, 404 — это наш неверный
 * URL, а не площадка), оспорьте маппинг ниже.
 */
final readonly class SourceFailureClassifier
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

    public function classifyStatus(int $status): SourceFailureKind
    {
        return match (true) {
            $status === 407 => SourceFailureKind::ProxyUnavailable,
            in_array($status, [401, 403, 429, 451], true) => SourceFailureKind::SourceBlocked,
            $status === 408, $status >= 500 => SourceFailureKind::SourceUnavailable,
            default => SourceFailureKind::UnexpectedStatus,
        };
    }

    /**
     * Исключение при скачивании блока листинга. `null` — не сетевой отказ, а ошибка нашего
     * кода/БД: прогон падает, как раньше (скрывать её за пропуском блока нельзя).
     */
    public function classifyListingException(Throwable $exception): ?SourceFailureKind
    {
        return match (true) {
            $exception instanceof NoProxyAvailableException => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException => SourceFailureKind::SourceUnavailable,
            default => null,
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
            $exception instanceof NoProxyAvailableException => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException, $exception instanceof CardPageMismatchException => SourceFailureKind::SourceUnavailable,
            default => SourceFailureKind::CodeError,
        };
    }
}
