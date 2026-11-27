<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use Carbon\CarbonImmutable;

/**
 * Временные семантические ограничения CSV-листинга, применяемые поверх сохранённого профиля.
 *
 * Носитель нужен активной проверке ОКПД2: она должна выполнить два одинаковых боевых запроса за
 * календарные сутки, различающихся только ОКПД2. Сохранённый профиль остаётся валидным и не
 * ослабляется пустым `okpd2Codes`; представление override в параметрах ЕИС остаётся в builder.
 */
final readonly class EisListingQueryOverrides
{
    public function __construct(
        public CarbonImmutable $publishedFrom,
        public CarbonImmutable $publishedTo,
        public bool $withoutOkpd2 = false,
        public bool $retryOnEmptyResponse = true,
        public ?int $requestAttempts = null,
    ) {}
}
