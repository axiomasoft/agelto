<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Точечная роль по номеру строки — `estimates/gold/<CODE>.profile.json`
 * §`row_rules.explicit_rows`. Механизм для ОДИНОЧНЫХ конструкций формы, которые замкнутый набор
 * префиксных/маркерных правил не описывает (напр. заголовок подраздела без общего префикса
 * листа), не для массового «дотягивания» покрытия условия (б) — `FormProfileReader`/
 * `GridRecognitionGate` решений об этом не принимают, разметка обязана доказать роль цитатой.
 * row: номер строки листа.
 * role: значение `RowType` (`section_header`/`subsection_header`/`item`/`subtotal`/
 * `summary_row`) либо `ignored` (строка-сноска, в gold не попадает вовсе).
 * text: дословная цитата текста строки — доказательство роли.
 */
final class GoldExplicitRow extends Data
{
    public function __construct(
        public int $row,
        public string $role,
        public string $text,
    ) {}
}
