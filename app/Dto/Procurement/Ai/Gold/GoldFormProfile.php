<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Gold;

use Spatie\LaravelData\Data;

/**
 * Профиль формы печати табличной книги (P5.2, D117) — верхний уровень контракта
 * `estimates/gold/<CODE>.profile.json` (version 2). Заменяет `headermap.json` первой редакции
 * (D42, снято D117): описывает не шесть колонок, а форму — роли строк, ресурсы, пары
 * базис/текущий — из которой `SheetGoldMapper` строит полный gold §8.2 дословно.
 * `FIELDS` — единственный источник истины для допустимых ключей верхнего уровня, используется
 * `FormProfileReader` для отказа на неизвестном ключе.
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
