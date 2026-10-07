<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use RuntimeException;

/**
 * Блок листинга нельзя считать прочитанным: источник вернул не-2xx ответ либо ни одна запись
 * непустого блока не распознана маппером. Курсор прогона остаётся на этом блоке.
 */
final class ListingBlockRejectedException extends RuntimeException
{
    public static function forHttpStatus(int $status): self
    {
        return new self("Блок листинга отклонён: источник ответил HTTP {$status}. Курсор сохранён для повтора.");
    }

    public static function forUnrecognizedFormat(int $records): self
    {
        return new self("Блок листинга отклонён: ни одна из {$records} записей не распознана — вероятно, изменился формат источника. Курсор сохранён для повтора.");
    }
}
