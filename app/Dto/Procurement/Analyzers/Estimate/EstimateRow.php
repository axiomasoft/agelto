<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Alias;
use App\Attributes\Ai\Description;
use App\Enums\Procurement\Estimate\RowQualityReason;
use App\Enums\Procurement\Estimate\RowType;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * Плоская строка построчного извлечения (docs/specs/v2/08-estimate-extraction.md §3.2, D10).
 * Вложенное дерево section→subsection→item модель НЕ формирует — иерархия prc_estimate_sections
 * собирается на бэкенде из row_type + section_title. row_type=section_header/subtotal/summary_row
 * — тоже строки, участвуют в сквозной нумерации row_index (§4а, счёт ведётся ПО СМЕТЕ — P4.3).
 */
final class EstimateRow extends Data
{
    /** Единственный носитель русских подписей полей позиции для витрины и validator-пометок. */
    private const array FIELD_LABELS = [
        'name' => 'Наименование',
        'section_title' => 'Раздел',
        'code' => 'Шифр расценки',
        'unit' => 'Ед. изм.',
        'quantity' => 'Количество',
        'unit_price_base' => 'Цена (базисный уровень)',
        'unit_price_curr' => 'Цена (текущий уровень)',
        'total_price_base' => 'Стоимость (базисный уровень)',
        'total_price_curr' => 'Стоимость (текущий уровень)',
        'index_code' => 'Индекс (код)',
        'index_value' => 'Индекс (значение)',
        'source_position_number' => '№ позиции в документе',
        'work_type_name' => 'Вид работ',
    ];

    /**
     * @param  RowResource[]  $resources
     */
    public function __construct(
        #[Description('сквозной номер строки В ПРЕДЕЛАХ СМЕТЫ (не «№ п/п» исходника) — присваивается ПОСЛЕДОВАТЕЛЬНО в порядке появления, включая заголовки разделов и промежуточные итоги.')]
        public int $row_index,
        #[Description('section_header/subsection_header/item/subtotal/summary_row.')]
        public RowType $row_type,
        #[Description('наименование работы/раздела (обязательно).')]
        public string $name,
        #[Description('название раздела/подраздела, к которому относится строка (для сборки иерархии на бэкенде); для строк с row_type=section_header/subsection_header — название самого раздела.')]
        public ?string $section_title = null,
        #[Description('шифр расценки (ФЕР/ТЕР), если есть.')]
        public ?string $code = null,
        #[Description('единица измерения — ЦИТАТА документа; разбор на базу и множитель делает БЭКЕНД детерминированной нормализацией (не входит в контракт).')]
        public ?string $unit = null,
        #[Description('количество в единицах документа (decimal-строка — конверсия во float запрещена).')]
        public ?string $quantity = null,
        #[Description('цена за единицу на базисном уровне (decimal-строка) — пуста у ресурсной сметы (calc_method=resource), это законное состояние, а не потеря.')]
        #[Alias(aliases: ['Цена в базисном уровне', 'Сметная стоимость в базисных ценах'])]
        public ?string $unit_price_base = null,
        #[Description('цена за единицу на текущем уровне (decimal-строка).')]
        public ?string $unit_price_curr = null,
        #[Description('стоимость всего по строке на базисном уровне (decimal-строка).')]
        public ?string $total_price_base = null,
        #[Description('стоимость всего по строке на текущем уровне (decimal-строка).')]
        #[Alias(aliases: ['Стоимость всего', 'Итого руб'])]
        public ?string $total_price_curr = null,
        #[Description('код индекса пересчёта позиции — второй множитель арифметики «базис × индекс ≈ текущая»; подпись индекса не хранится.')]
        #[Alias(aliases: ['Индекс', 'Ин'])]
        public ?string $index_code = null,
        #[Description('значение индекса пересчёта позиции — второй множитель арифметики «базис × индекс ≈ текущая»; подпись индекса не хранится.')]
        #[Alias(aliases: ['Индекс', 'Ин'])]
        public ?string $index_value = null,
        #[Description('собственный номер позиции, напечатанный в документе, если он есть (может быть дробным, напр. «9.1»); область — ЛЮБАЯ смета, не только акт. Отличается от row_index — синтетического монотонного якоря, который существует и для строк без напечатанного номера.')]
        public ?string $source_position_number = null,
        #[Description('подпись вида работ, к которому относится позиция — заполняет контракт LLM; бэкенд резолвит по имени ссылку prc_estimate_items.work_type_id, нормативы НР/СП и иерархия — только маппер .gsfx (см. EstimateWorkType, PassportResponse::$work_types).')]
        #[Alias(aliases: ['НР', 'СП'])]
        public ?string $work_type_name = null,
        #[Description('ресурсы позиции (материалы/машины/труд/накладные).')]
        public array $resources = [],
        #[Description('фиксированная схема attributes (коэффициенты + КС-3, см. RowAttributes) — null, если строка не несёт ни коэффициентов, ни КС-3-разрезов.')]
        public ?RowAttributes $attributes = null,
        #[Description('носитель неопределённости строки — причина, по которой перечисленные в нём поля этой строки нельзя считать надёжными; отсутствует/null на строке, прочитанной полностью.')]
        public ?RowQuality $quality = null,
    ) {}

    /**
     * Гейт фиксированной схемы `attributes` (Implementation Rules P4.5): неизвестный ключ внутри
     * `attributes` обязан приводить к отказу валидации — иначе «фиксированная схема» (`RowAttributes`)
     * существовала бы только на бумаге, а D32 не был бы выполнен. Спека `spatie/laravel-data` сама
     * не отклоняет незнакомые ключи (лишние ключи `from()`/`validateAndCreate()` тихо игнорирует) —
     * проверка добавлена явно ДО делегирования остальной валидации родителю.
     */
    /**
     * @param  Arrayable<array-key, mixed>|array<string, mixed>  $payload
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

        if (isset($raw['quality']) && is_array($raw['quality'])) {
            $unknown = array_diff(array_keys($raw['quality']), ['reason', 'fields']);

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'quality' => sprintf('Неизвестный ключ quality: %s', implode(', ', $unknown)),
                ]);
            }

            if (array_key_exists('reason', $raw['quality'])) {
                $reason = is_string($raw['quality']['reason']) ? RowQualityReason::tryFrom($raw['quality']['reason']) : null;

                if ($reason === null) {
                    throw ValidationException::withMessages([
                        'quality.reason' => 'Неизвестная причина quality.',
                    ]);
                }

                if (! $reason->isModelOrigin()) {
                    throw ValidationException::withMessages([
                        'quality.reason' => sprintf('Причина "%s" недоступна модели (не model-origin).', $reason->value),
                    ]);
                }
            }

            if (($raw['quality']['fields'] ?? []) === []) {
                throw ValidationException::withMessages([
                    'quality.fields' => 'quality.fields не может быть пустым.',
                ]);
            }
        }

        return parent::validateAndCreate($payload);
    }

    /** Подпись контрактного поля для интерфейса и детерминированных validator-пометок. */
    public static function fieldLabel(string $field): string
    {
        return self::FIELD_LABELS[$field] ?? $field;
    }
}
