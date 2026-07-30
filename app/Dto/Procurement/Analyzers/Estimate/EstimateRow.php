<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\RowType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * Плоская строка построчного извлечения (docs/specs/v2/08-estimate-extraction.md §3.2, D10).
 * Вложенное дерево section→subsection→item модель НЕ формирует — иерархия prc_estimate_sections
 * собирается на бэкенде из row_type + section_title. row_type=section_header/subtotal/summary_row
 * — тоже строки, участвуют в сквозной нумерации row_index (§4а, счёт ведётся ПО СМЕТЕ — P4.3).
 * - row_index: сквозной номер строки В ПРЕДЕЛАХ СМЕТЫ (не «№ п/п» исходника) — присваивается
 *   ПОСЛЕДОВАТЕЛЬНО в порядке появления, включая заголовки разделов и промежуточные итоги (§4а).
 * - row_type: section_header/subsection_header/item/subtotal/summary_row.
 * - section_title: название раздела/подраздела, к которому относится строка (для сборки иерархии
 *   на бэкенде); для строк с row_type=section_header/subsection_header — название самого раздела.
 * - code: шифр расценки (ФЕР/ТЕР), если есть.
 * - name: наименование работы/раздела (обязательно).
 * - unit: единица измерения — ЦИТАТА документа; разбор на базу и множитель делает БЭКЕНД
 *   детерминированной нормализацией (не входит в контракт, исключение из D33).
 * - quantity: количество в единицах документа (decimal-строка — конверсия во float запрещена).
 * - unit_price_base: цена за единицу на базисном уровне (decimal-строка, SR-01, P4.5) — пуста у
 *   ресурсной сметы (`calc_method=resource`), это законное состояние, а не потеря.
 * - unit_price_curr: цена за единицу на текущем уровне (decimal-строка) — переименовано из
 *   `unit_price` (D93).
 * - total_price_base: стоимость всего по строке на базисном уровне (decimal-строка, SR-01, P4.5).
 * - total_price_curr: стоимость всего по строке на текущем уровне (decimal-строка) — переименовано
 *   из `total_price` (D93, v1 Alias «Стоимость всего»/«Итого руб»).
 * - index_code / index_value: код и значение индекса пересчёта позиции (SR-03, P4.5) — второй
 *   множитель арифметики «базис × индекс ≈ текущая»; подпись индекса не хранится.
 * - source_position_number: собственный номер позиции, напечатанный в документе, если он есть
 *   (может быть дробным, напр. «9.1») — SR-13, P4.5; область — ЛЮБАЯ смета, не только акт
 *   (расширено P4.3 §5.1). Отличается от `row_index` — синтетического монотонного якоря, который
 *   существует и для строк без напечатанного номера.
 * - work_type_name: подпись вида работ, к которому относится позиция (SR-08, P4.5) — заполняет
 *   контракт LLM; бэкенд резолвит по имени ссылку `prc_estimate_items.work_type_id`, нормативы
 *   НР/СП и иерархия — только маппер `.gsfx` (см. `EstimateWorkType`, `PassportResponse::$work_types`).
 * - resources: ресурсы позиции (материалы/машины/труд/накладные).
 * - attributes: фиксированная схема `prc_estimate_items.attributes` (коэффициенты + КС-3, P4.5,
 *   см. `RowAttributes`) — null, если строка не несёт ни коэффициентов, ни КС-3-разрезов.
 */
final class EstimateRow extends Data
{
    /**
     * @param  RowResource[]  $resources
     */
    public function __construct(
        public int $row_index,
        public RowType $row_type,
        public string $name,
        public ?string $section_title = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $unit_price_base = null,
        public ?string $unit_price_curr = null,
        public ?string $total_price_base = null,
        public ?string $total_price_curr = null,
        public ?string $index_code = null,
        public ?string $index_value = null,
        public ?string $source_position_number = null,
        public ?string $work_type_name = null,
        public array $resources = [],
        public ?RowAttributes $attributes = null,
    ) {}

    /**
     * Гейт фиксированной схемы `attributes` (Implementation Rules P4.5): неизвестный ключ внутри
     * `attributes` обязан приводить к отказу валидации — иначе «фиксированная схема» (`RowAttributes`)
     * существовала бы только на бумаге, а D32 не был бы выполнен. Спека `spatie/laravel-data` сама
     * не отклоняет незнакомые ключи (лишние ключи `from()`/`validateAndCreate()` тихо игнорирует) —
     * проверка добавлена явно ДО делегирования остальной валидации родителю.
     */
    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $raw = $payload instanceof Arrayable ? $payload->toArray() : $payload;

        if (isset($raw['attributes']) && is_array($raw['attributes'])) {
            $unknown = array_diff(array_keys($raw['attributes']), RowAttributes::ALLOWED_KEYS);

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'attributes' => sprintf('Неизвестный ключ attributes: %s', implode(', ', $unknown)),
                ]);
            }
        }

        return parent::validateAndCreate($payload);
    }
}
