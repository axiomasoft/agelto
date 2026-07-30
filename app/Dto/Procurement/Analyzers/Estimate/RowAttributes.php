<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use Spatie\LaravelData\Data;

/**
 * Фиксированная схема `prc_estimate_items.attributes` (jsonb, P4.5, `plans/2026.07.26-EST-TRAIN/
 * research/06-schema-decision.md` §4.1). Ключ, не перечисленный ниже, в `attributes` не пишется
 * вовсе — `EstimateRow::validateAndCreate()` отклоняет неизвестный ключ явно (Implementation Rules
 * P4.5: «фиксированная схема выражается ОТДЕЛЬНЫМ Data-классом, а не свободным array»).
 * - modifiers: коэффициенты позиции (SR-04/SR-05/SR-06, элемент — `RowModifier`, §4.2).
 * - amount_since_start / amount_since_year_start / amount_reporting_period: КС-3 — стоимость с
 *   начала проведения работ / с начала года / за отчётный период (SR-14) — decimal-строки,
 *   заполняются только строкой `row_type=summary_row`.
 */
final class RowAttributes extends Data
{
    /**
     * Единственный источник истины для допустимых ключей `attributes` — используется
     * `EstimateRow::validateAndCreate()` для отказа на неизвестном ключе (иначе фиксированная
     * схема существовала бы только на бумаге, D32).
     *
     * @var list<string>
     */
    public const array ALLOWED_KEYS = [
        'modifiers',
        'amount_since_start',
        'amount_since_year_start',
        'amount_reporting_period',
    ];

    /**
     * @param  RowModifier[]  $modifiers
     */
    public function __construct(
        public array $modifiers = [],
        public ?string $amount_since_start = null,
        public ?string $amount_since_year_start = null,
        public ?string $amount_reporting_period = null,
    ) {}
}
