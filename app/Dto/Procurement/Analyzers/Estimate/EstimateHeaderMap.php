<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Attributes\Ai\Alias;
use App\Attributes\Ai\Description;
use Spatie\LaravelData\Data;

/**
 * Словарь синонимов заголовков таблицы позиций (перенос v1 EstimateHeader, docs/specs/v2/
 * 08-estimate-extraction.md §2.1). НЕ часть JSON-ответа LLM ни одного шага — v2 не хранит
 * динамическую карту колонок (§1.3, карта была нужна только legacy Excel-парсеру). Класс — чистый
 * носитель #[Alias]-метаданных: исходник подмешивается в системную инструкцию (приём v1, §6),
 * чтобы модель сама сопоставляла русские заголовки таблицы с каноническими полями независимо от
 * форматирования конкретной сметной программы (Гранд-Смета, 1С:Смета, ручной Excel).
 * Подмешивает его объявление словарей шага `EstimateStepDictionaries` (P6.1): обход графа типов
 * сюда не доезжает ни от одной обёртки ответа — именно поэтому словарь объявлен явно.
 * Имя свойства — целевое поле v2 (см. EstimateRow), значение — найденная колонка исходной таблицы.
 */
final class EstimateHeaderMap extends Data
{
    public function __construct(
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю row_index (см. EstimateRow).')]
        #[Alias(aliases: ['№ п/п', '№', 'поз', 'Номер', 'по порядку', 'Номер п/п', '№№ п/п'])]
        public ?HeaderColumn $row_index,
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю code (см. EstimateRow).')]
        #[Alias(aliases: ['Шифр', 'Обоснование', 'Код', 'Шифр расценки и коды ресурсов', 'Шифр стоимостного норматива и коды ресурсов'])]
        public ?HeaderColumn $code,
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю name (см. EstimateRow).')]
        #[Alias(aliases: ['Наименование работ', 'Описание', 'Наименование работ и затрат', 'Наименование пусковых комплексов, этапов, объектов, видов выполненных работ, оборудования, затрат'])]
        public ?HeaderColumn $name,
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю unit (см. EstimateRow).')]
        #[Alias(aliases: ['Ед. изм.', 'Измеритель', 'Единица измерения'])]
        public ?HeaderColumn $unit,
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю quantity (см. EstimateRow).')]
        #[Alias(aliases: ['Количество', 'Объем', 'Кол-во единиц', 'всего с учетом коэффициентов'])]
        public ?HeaderColumn $quantity,
        #[Description('найденная колонка исходной таблицы, соответствующая целевому полю total_price_curr (см. EstimateRow).')]
        #[Alias(aliases: ['Стоимость всего', 'Итого руб', 'всего в текущем уровне цен'])]
        public ?HeaderColumn $total_price,
    ) {}
}
