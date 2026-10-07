<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Классификация отказа блока листинга. Только детерминированные признаки: тип исключения,
 * HTTP-статус, проверка формата (обязательные колонки, разобранные записи). Текст ошибок и
 * «похожесть» тела на HTML не анализируются: HTML-заглушка вместо CSV и так не проходит
 * проверку заголовка и попадает в `InvalidContent`.
 *
 * Ревьюеру: если какой-то класс отказа должен реагировать иначе (например, 404 — это не
 * проблема площадки, а наш неверный URL), оспорьте маппинг статусов ниже.
 */
final readonly class ListingFailureClassifier
{
    /**
     * `null` — блок принят без замечаний.
     *
     * @param  list<string>  $missingColumns
     */
    public function classifyResponse(int $httpStatus, int $records, int $mapped, int $rowFailures, array $missingColumns = []): ?SourceFailureKind
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return $this->classifyStatus(status: $httpStatus);
        }

        if ($missingColumns !== [] || ($records > 0 && $mapped === 0)) {
            return SourceFailureKind::InvalidContent;
        }

        return $rowFailures > 0 ? SourceFailureKind::RowErrors : null;
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
     * Исключение при скачивании. `null` — не сетевой отказ, а ошибка нашего кода/БД: её нельзя
     * прятать за пропуском блока, вызывающий пробрасывает её дальше.
     */
    public function classifyException(Throwable $exception): ?SourceFailureKind
    {
        return match (true) {
            $exception instanceof NoProxyAvailableException => SourceFailureKind::ProxyUnavailable,
            $exception instanceof ConnectionException => SourceFailureKind::SourceUnavailable,
            default => null,
        };
    }
}
