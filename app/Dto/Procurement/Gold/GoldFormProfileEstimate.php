<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Профиль одной сметы книги в `estimates/gold/<CODE>.profile.json`, объект `estimates[]`.
 * ordinal: номер сметы в книге, 1-based без пропусков.
 * sheet: имя листа сметы.
 * header_rows: включительные границы блока шапки `[первая, последняя]`.
 * data_row_start/data_row_end: границы данных; `data_row_end=null` означает до конца листа.
 * columns: соответствие полей gold буквам колонок.
 * header_cells: части дословных цитат, доказывающие привязку каждой объявленной колонки.
 * row_rules: замкнутые правила определения роли строки.
 * note: пояснение разметчика о том, чего правила не описывают.
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
