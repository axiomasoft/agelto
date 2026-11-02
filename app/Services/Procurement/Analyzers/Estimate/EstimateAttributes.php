<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\RowAttributes;
use App\Dto\Procurement\Analyzers\Estimate\RowModifier;
use App\Enums\Procurement\Estimate\RowQualityReason;

/** Pure normalization only; PublicationRepository is the sole accepted estimate writer. */
final readonly class EstimateAttributes
{
    /**
     * Единственный носитель набора полей позиции (`prc_estimate_items`), читаемого ОБОИМИ
     * writer-путями (`self`/`RerunRangeAction`, P5.2 Implementation Rules п.2) — две независимые
     * копии этого набора и есть причина, по которой полный persist и точечный re-run расходились
     * (D40). `work_type_id` сюда не входит: резолв `work_type_name` → FK читает состояние БД
     * (`EstimateStoreRepository::findOrCreateWorkType()`, P5.3, B-5) — `itemAttributes()` остаётся
     * чистой функцией строки, вызывающая сторона мёржит `work_type_id` отдельным ключом.
     *
     * @return array<string, mixed>
     */
    public static function itemAttributes(EstimateRow $row, ?ValidationReport $report = null): array
    {
        $unit = UnitNormalizer::normalize($row->unit);

        return [
            'code' => $row->code,
            'name' => $row->name,
            'unit' => $row->unit,
            'unit_base' => $unit['unit_base'],
            'unit_multiplier' => $unit['unit_multiplier'],
            'quantity' => $row->quantity,
            'unit_price_base' => $row->unit_price_base,
            'unit_price_curr' => $row->unit_price_curr,
            'total_price_base' => $row->total_price_base,
            'total_price_curr' => $row->total_price_curr,
            'index_code' => $row->index_code,
            'index_value' => $row->index_value,
            'source_position_number' => $row->source_position_number,
            'attributes' => self::rowAttributes($row->attributes),
            'quality' => self::rowQuality($row, $report),
        ];
    }

    /**
     * Слияние ДВУХ независимых утверждений о строке под провенанс-ключом верхнего уровня
     * (research/12-uncertainty-model.md §3.2/§3.3): `model` — контрактная форма модели (P8.3),
     * как есть; `validator` — построчная карта причин `ValidationReport` (P8.4). Оба входа пусты →
     * `null` (чистая позиция, И-А §6.1). `quality` возвращается ВСЕГДА (в т.ч. `null`) — атрибут,
     * который писатель молча пропускает, оставляет пометку прошлого прогона (Ф-10). Точечный
     * re-run (`RerunRangeAction`) вызывает этот метод без `$report` (нет `ValidationReport` без
     * полного стичинга сметы, §7 докблок класса) — ключ `validator` в записи исчезает, ключ
     * `model` перезаписывается новым ответом (правило re-run, research/12 §7).
     *
     * @return array{model?: array<string, mixed>, validator?: array{reasons: list<string>, notes: list<string>}}|null
     */
    private static function rowQuality(EstimateRow $row, ?ValidationReport $report): ?array
    {
        $quality = [];

        if ($row->quality !== null) {
            /** @var array<string, mixed> $modelQuality */
            $modelQuality = $row->quality->toArray();
            $quality['model'] = $modelQuality;
        }

        if ($report !== null) {
            $reasons = $report->rowReasons[$row->row_index] ?? [];

            if ($reasons !== []) {
                $quality['validator'] = [
                    'reasons' => array_map(static fn (RowQualityReason $reason): string => $reason->value, $reasons),
                    'notes' => $report->rowNotes[$row->row_index] ?? [],
                ];
            }
        }

        return $quality === [] ? null : $quality;
    }

    /**
     * Собирается по `RowAttributes::ALLOWED_KEYS` (единственный источник истины допустимых ключей,
     * P5.2 Implementation Rules п.3), а не перечислением ключей руками — пустое значение не
     * превращается в `null`-ключ.
     *
     * @return array<string, mixed>|null
     */
    private static function rowAttributes(?RowAttributes $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }

        $values = [];

        if ($attributes->modifiers !== []) {
            $values['modifiers'] = array_map(static fn (RowModifier $modifier): array => $modifier->toArray(), $attributes->modifiers);
        }

        foreach ([
            'amount_since_start' => $attributes->amount_since_start,
            'amount_since_year_start' => $attributes->amount_since_year_start,
            'amount_reporting_period' => $attributes->amount_reporting_period,
        ] as $key => $value) {
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values === [] ? null : $values;
    }
}
