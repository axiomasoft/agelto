<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Профиль формы табличной книги `estimates/gold/<CODE>.profile.json`, version 2.
 * Описывает колонки, роли строк, ресурсы и пары базисных/текущих значений, из которых
 * `SheetGoldMapper` дословно строит gold. `FIELDS` задаёт допустимые ключи верхнего уровня;
 * `FormProfileReader` отклоняет неизвестные ключи.
 */
final class GoldFormProfile extends Data
{
    /**
     * @var list<string>
     */
    public const array FIELDS = ['version', 'code', 'estimates'];

    public const int VERSION = 2;

    /**
     * @param  list<GoldFormProfileEstimate>  $estimates
     */
    public function __construct(
        public int $version,
        public string $code,
        public array $estimates,
    ) {}
}
