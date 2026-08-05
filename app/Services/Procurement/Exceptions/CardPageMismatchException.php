<?php

declare(strict_types=1);

namespace App\Services\Procurement\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Ответ вкладки карточки закупки ЕИС не является запрошенной страницей: HTTP-статус
 * означает отказ ({@see Response::failed()}) либо тело не содержит
 * запрошенный реестровый номер (Д-8 — транспорт стирает query-строку карточного URL,
 * источник отдаёт «Страница не найдена» с кодом 200). Бросается только для `common-info` —
 * без неё карточка не считается снятой.
 */
final class CardPageMismatchException extends RuntimeException
{
    public static function forTab(string $tab, string $url, int $status, int $bodyLength): self
    {
        return new self(
            message: "Card tab [{$tab}] at [{$url}] returned a mismatched page "
                ."(status={$status}, body_length={$bodyLength}): requested registry number not found in body.",
        );
    }
}
