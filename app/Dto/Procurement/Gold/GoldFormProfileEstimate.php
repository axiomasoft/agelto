<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Профиль ОДНОЙ сметы книги (P5.2, D117) — `estimates/gold/<CODE>.profile.json` §`estimates[]`.
 * Многосметная книга даёт несколько элементов в порядке `ordinal`
 * (`research/07-multi-estimate-anchoring.md` §4).
 * - ordinal: номер сметы в книге, 1-based, без дыр в пределах файла.
 * - sheet: имя листа книги, которому принадлежит смета.
 * - header_rows: пара `[первая, последняя]` строк шапки — блок, а не одна строка (D117 п.1).
 * - data_row_start/data_row_end: границы диапазона строк данных листа; `data_row_end=null` —
 *   до конца листа.
 * - columns: соответствие полей §8.2 буквам колонок.
 * - header_cells: доказательство привязки КАЖДОЙ непустой колонки — поле → список частей цитаты.
 * - row_rules: замкнутый набор правил роли строки.
 * - note: что именно не описывается правилами и почему (человеко-читаемая заметка разметчика).
 */
final class GoldFormProfileEstimate extends Data
{
    /**
     * @var list<string>
     */
    public const array FIELDS = [
        'ordinal',
        'sheet',
        'header_rows',
        'data_row_start',
        'data_row_end',
        'columns',
        'header_cells',
        'row_rules',
        'note',
    ];

    /**
     * @param  array{0: int, 1: int}  $header_rows
     * @param  array<string, list<GoldHeaderQuote>>  $header_cells
     */
    public function __construct(
        public int $ordinal,
        public string $sheet,
        public array $header_rows,
        public int $data_row_start,
        public ?int $data_row_end,
        public GoldFormProfileColumns $columns,
        public array $header_cells,
        public GoldRowRules $row_rules,
        public ?string $note = null,
    ) {}
}
