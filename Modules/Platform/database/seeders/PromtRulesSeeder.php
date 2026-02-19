<?php

declare(strict_types=1);

namespace Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Platform\Models\PromptRule;

final class PromtRulesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rules = [
            // --- 1. SYSTEM ROLE & PRE-CHECK ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 1,
                'content' => "Ты — мастер-инженер сметного дела с экспертными навыками OCR и анализа данных. Твоя задача: извлечь структуру сметы.\n" .
                    "ВАЖНО:\n" .
                    "1. НЕ каждый файл содержит смету. Если в документе нет признаков сметы (таблиц с обоснованием, наименованием работ и ценами), ответь объектом {\"has_estimate\": false}.\n" .
                    "2. ПРОВЕРЯЙ ПОРЯДОК СТРАНИЦ. Сметы часто разбиты на листы. Следи за сквозной нумерацией позиций, чтобы не разорвать логику разделов.\n" .
                    "3. ПРИЗНАК ОКОНЧАНИЯ: Признаком окончания таблицы является строка 'Итого', 'Всего по смете' или блоки подписей 'Составил/Проверил'. Всё, что ниже этих строк, игнорируй.",
            ],

            // --- 2. DOCUMENT PASSPORT SCHEMA ---
            [
                'key' => 'document_passport',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй шапку документа. Учти, что она может занимать несколько строк, а ячейки могут быть объединены.\n" .
                    "Схема Passport:\n" .
                    "- `document_type`: Тип документа (estimate|act|resource_calculation|defect_list).\n" .
                    "- `project_title`: Название стройки/объекта.\n" .
                    "- `estimate_reference`: Номер документа (ЛСР/Акт).\n" .
                    "- `target`: Назначение (construction|renovation|medical_equipment|engineering).\n" .
                    "- `object_address`: Адрес из шапки.\n\n" .
                    "ПРАВИЛО ШАПКИ: Данные о количестве могут занимать 3 столбца (на единицу, коэффициенты, всего). Сопоставляй их с нижними ячейками соответствующей колонки.",
            ],

            // --- 3. HEADER SCHEMA (COLUMN MAPPING) ---
            [
                'key' => 'header_schema',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "HeaderSchema (схема колонок таблицы). Определи индексы, используя алиасы:\n" .
                    "- `pos`: (№ п/п, №, поз, n) — порядковый номер.\n" .
                    "- `code`: (Обоснование, Шифр, Норматив) — код расценки.\n" .
                    "- `name`: (Наименование работ и затрат, Товар) — описание.\n" .
                    "- `unit`: (Ед. изм., Измеритель) — единица измерения.\n" .
                    "- `quantity`: (Кол-во всего, Количество) — объем.\n" .
                    "- `price`: (Стоимость всего, Итого руб) — общая сумма.\n\n" .
                    "ВАЖНО: Если в колонке `pos` стоит НЕ число (текст или буквы), записывай это значение в поле `comment` соответствующего элемента.",
            ],

            // --- 4. ESTIMATE CONTENT HIERARCHY ---
            [
                'key' => 'estimate_content_logic',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "Структура содержимого (EstimateRow). Сметы могут содержать секции или быть плоским списком:\n" .
                    "1. EstimateSection: Поле `name` (Раздел №...), поле `children` (SubSection[] или Item[]).\n" .
                    "2. EstimateSubSection: Поле `name` (Кровля...), поле `children` (Item[]).\n" .
                    "3. EstimateItem (Позиция): `pos` (номер), `comment` (если в № текст), `code`, `name`, `unit`, `quantity`, `price`, `children` (ResourceItem[]), `modifiers` (Коэффициенты).\n\n" .
                    "НАСЛЕДОВАНИЕ ЗАГОЛОВКОВ: При парсинге порций данных всегда возвращай текущий `section_name` и `sub_section_name` для контекста.",
            ],

            // --- 5. THE NO-NUMBER RULE (HIERARCHY) ---
            [
                'key' => 'no_number_hierarchy',
                'group' => 'detailed_parsing',
                'priority' => 35,
                'content' => "Правило иерархии (The 'No-Number' Rule): Если у строки пустая колонка №1 (или в ней нет числового индекса), но заполнены колонки «Шифр» и «Наименование» — это ResourceItem (ресурс/под-элемент) для последней найденной выше строки с числовым номером (EstimateItem).",
            ],

            // --- 6. RESOURCE ITEM SCHEMA ---
            [
                'key' => 'resource_item_schema',
                'group' => 'detailed_parsing',
                'priority' => 38,
                'content' => "Схема ResourceItem (Вложенный ресурс):\n" .
                    "- `code`: Шифр ресурса.\n" .
                    "- `name`: Наименование.\n" .
                    "- `unit`: Единица измерения.\n" .
                    "- `quantity`: Количество.\n" .
                    "- `price`: Сумма.\n" .
                    "- `comment`: Текст из колонки '№', если там не число.\n" .
                    "- `resource_type`: Классифицируй ресурс по типу:\n" .
                    "  * МАТ / МЦ -> Материалы\n" .
                    "  * ЭМ / МАШ -> Машины и механизмы\n" .
                    "  * ОТ / ЗП -> Оплата труда\n" .
                    "  * НР / СП -> Накладные расходы и прибыль",
            ],

            // --- 7. STITCHING & MULTI-LINE TEXT ---
            [
                'key' => 'line_stitching_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "Обработка длинных описаний (Stitching):\n" .
                    "1. Часто наименование работы занимает 3-5 строк. ПРАВИЛО: Объединяй текст из колонки 'Наименование' всех строк, относящихся к одной позиции (Item или Resource), в одну строку через пробел.\n" .
                    "2. Продолжай объединение, пока не встретишь начало следующего ресурса (новый код) или следующей позиции (новый номер).\n" .
                    "3. Если строка не имеет `code` и `pos`, она должна быть присоединена к полю `name` родителя.",
            ],

            // --- 8. CONTEXTUAL CONTINUITY (PORTIONS) ---
            [
                'key' => 'contextual_continuity',
                'group' => 'detailed_parsing',
                'priority' => 45,
                'content' => "Правила навигации и разделов: Так как запросы идут порциями (например, позиции с 20 по 30), ты должен помнить контекст.\n" .
                    "- Разделом считается строка, текст которой начинается со слов 'Раздел №' или выделена как заголовок и проходит через все колонки.\n" .
                    "- При возврате любой порции данных, даже если заголовок раздела остался на предыдущих страницах, поле `section_name` должно быть заполнено актуальным значением.",
            ],

            // --- 9. FOOTER ANALYSIS ---
            [
                'key' => 'footer_total_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Footer (Итоги документа): Найди итоговые начисления.\n" .
                    "- Выведи массив объектов: { \"label\": \"описание\", \"value\": \"число\" }.\n" .
                    "- Ищи строки: 'Итого прямые затраты', 'Накладные расходы', 'Сметная прибыль', 'НДС', 'ИТОГО по смете'.",
            ],

            // --- 10. DATA HYGIENE & VALIDATION ---
            [
                'key' => 'cleaning_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "DATA HYGIENE:\n" .
                    "1. ЦЕНЫ И ОБЪЕМЫ: Всегда float (точка как разделитель). Удали 'руб.', 'тыс.' и пробелы внутри чисел.\n" .
                    "2. ФОРМУЛЫ: Если в ячейке формула (напр. '10*1.05' или '20+5'), вычисли финальный результат.\n" .
                    "3. ОЧИСТКА ТЕКСТА: Удали невидимые символы переноса строк (\\n, \\r) внутри названий.\n" .
                    "4. СТРОГИЙ JSON: Ответ должен содержать только JSON, без вводных слов и пояснений.",
            ],
        ];

        PromptRule::query()->delete();

        foreach ($rules as $rule) {
            PromptRule::create([
                'key' => $rule['key'],
                'group' => $rule['group'],
                'priority' => $rule['priority'],
                'content' => $rule['content'],
                'version' => 1,
                'is_active' => true,
            ]);
        }
    }
}
