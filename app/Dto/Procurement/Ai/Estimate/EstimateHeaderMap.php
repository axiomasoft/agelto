<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use App\Attributes\Ai\Alias;
use Spatie\LaravelData\Data;

/**
 * Словарь синонимов заголовков таблицы позиций (перенос v1 EstimateHeader, docs/specs/v2/
 * 08-estimate-extraction.md §2.1). НЕ часть JSON-ответа LLM ни одного шага — v2 не хранит
 * динамическую карту колонок (§1.3, карта была нужна только legacy Excel-парсеру). Класс — чистый
 * носитель #[Alias]-метаданных: исходник подмешивается в системную инструкцию (приём v1, §6),
 * чтобы модель сама сопоставляла русские заголовки таблицы с каноническими полями независимо от
 * форматирования конкретной сметной программы (Гранд-Смета, 1С:Смета, ручной Excel).
 * Имя свойства — целевое поле v2 (см. EstimateRow), значение — найденная колонка исходной таблицы.
 */
final class EstimateHeaderMap extends Data
{
    public function __construct(
        #[Alias(aliases: ['№ п/п', '№', 'поз'])]
        public ?HeaderColumn $row_index,
        #[Alias(aliases: ['Шифр', 'Обоснование', 'Код'])]
        public ?HeaderColumn $code,
        #[Alias(aliases: ['Наименование работ', 'Описание'])]
        public ?HeaderColumn $name,
        #[Alias(aliases: ['Ед. изм.', 'Измеритель'])]
        public ?HeaderColumn $unit,
        #[Alias(aliases: ['Количество', 'Объем'])]
        public ?HeaderColumn $quantity,
        #[Alias(aliases: ['Стоимость всего', 'Итого руб'])]
        public ?HeaderColumn $total_price,
    ) {}
}
