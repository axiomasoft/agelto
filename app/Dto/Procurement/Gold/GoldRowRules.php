<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Gold;

use Spatie\LaravelData\Data;

/**
 * Замкнутый набор видов правил распознавания роли строки (P5.2, D117) — `estimates/gold/<CODE>.
 * profile.json` §`estimates[].row_rules`. Профиль — ДАННЫЕ: правила параметризуют дословными
 * цитатами документа единственный порядок проверки, зашитый в `GridRecognitionGate`/
 * `SheetGoldMapper` (Code Guidance P5.2) — ни одного «умного» распознавания сверх перечисленных
 * здесь видов.
 * - item_number_column: буква колонки, непустая ячейка которой маркирует строку-позицию (правило
 *   7 роли строки) — используется ТОЛЬКО для распознавания роли, значение поля
 *   `source_position_number` берёт `SheetGoldMapper` из `GoldFormProfileColumns::$source_position_number`.
 * - section_prefixes/subsection_prefixes: префиксы текста ПЕРВОЙ непустой ячейки строки в
 *   пределах объявленных колонок — заголовок раздела печатается в объединении от левого края, а
 *   не в колонке `name`.
 * - subtotal_prefixes/summary_prefixes/ignored_prefixes: префиксы текста колонки `name`.
 * - resource_groups: маркеры групп ресурсов (точное совпадение текста колонки `name`).
 * - explicit_rows: точечные роли по номеру строки.
 */
final class GoldRowRules extends Data
{
    /**
     * @var list<string>
     */
    public const array FIELDS = [
        'item_number_column',
        'section_prefixes',
        'subsection_prefixes',
        'subtotal_prefixes',
        'summary_prefixes',
        'ignored_prefixes',
        'resource_groups',
        'explicit_rows',
    ];

    /**
     * @param  list<string>  $section_prefixes
     * @param  list<string>  $subsection_prefixes
     * @param  list<string>  $subtotal_prefixes
     * @param  list<string>  $summary_prefixes
     * @param  list<string>  $ignored_prefixes
     * @param  list<GoldResourceGroup>  $resource_groups
     * @param  list<GoldExplicitRow>  $explicit_rows
     */
    public function __construct(
        public string $item_number_column,
        public array $section_prefixes = [],
        public array $subsection_prefixes = [],
        public array $subtotal_prefixes = [],
        public array $summary_prefixes = [],
        public array $ignored_prefixes = [],
        public array $resource_groups = [],
        public array $explicit_rows = [],
    ) {}
}
