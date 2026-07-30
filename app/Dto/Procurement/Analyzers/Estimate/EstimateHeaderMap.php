<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

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
        #[Alias(aliases: ['№ п/п', '№', 'поз', 'Номер', 'по порядку', 'Номер п/п', '№№ п/п'])]
        public ?HeaderColumn $row_index,
        #[Alias(aliases: ['Шифр', 'Обоснование', 'Код', 'Шифр расценки и коды ресурсов', 'Шифр стоимостного норматива и коды ресурсов'])]
        public ?HeaderColumn $code,
        #[Alias(aliases: ['Наименование работ', 'Описание', 'Наименование работ и затрат', 'Наименование пусковых комплексов, этапов, объектов, видов выполненных работ, оборудования, затрат'])]
        public ?HeaderColumn $name,
        #[Alias(aliases: ['Ед. изм.', 'Измеритель', 'Единица измерения'])]
        public ?HeaderColumn $unit,
        #[Alias(aliases: ['Количество', 'Объем', 'Кол-во единиц', 'всего с учетом коэффициентов'])]
        public ?HeaderColumn $quantity,
        #[Alias(aliases: ['Стоимость всего', 'Итого руб', 'всего в текущем уровне цен'])]
        public ?HeaderColumn $total_price,
    ) {}
}
