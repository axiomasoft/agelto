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
            // --- ГРУППА: SCHEMAS (Технические контракты) ---
            [
                'key' => 'global_json_schemas',
                'group' => 'schemas',
                'priority' => 1,
                'content' => "Ниже описаны все схемы данных для использования в ответах:\n\n" .
                    "1. DocumentType (Тип документа):\n" .
                    "- \"estimate\": Смета (ЛСР). Определять по заголовкам 'Локальный сметный расчет', 'Смета'.\n" .
                    "- \"act\": Акт (КС-2). Определять по заголовкам 'Акт о приемке выполненных работ', 'Форма КС-2'.\n" .
                    "- \"resource_calculation\": Ресурсная ведомость. Поиск ключевых слов 'Ведомость ресурсов', 'Расчет ресурсов'.\n" .
                    "- \"defect_list\": Дефектная ведомость. Поиск слов 'Дефектная ведомость', 'Опись работ'.\n" .
                    "- null: Если тип документа не удалось определить однозначно.\n\n" .
                    "2. DocumentTarget (Цель документа):\n" .
                    "- \"construction\": Строительство. Новые объекты, капитальное возведение с нуля.\n" .
                    "- \"renovation\": Ремонт. Капитальный ремонт, реконструкция, модернизация, демонтаж.\n" .
                    "- \"medical_equipment\": Медоборудование. Поставка, монтаж или пусконаладка медтехники.\n" .
                    "- \"engineering\": Инженерия. Вентиляция, электроснабжение, связь, слаботочные системы.\n" .
                    "- null: Если цель не ясна.\n\n" .
                    "3. ResourceType (Тип ресурса в строке):\n" .
                    "- \"Material\": Материалы. Обоснования типа ФССЦ, прайс-листы, ключевые слова 'МАТ', 'Материал'.\n" .
                    "- \"Machine\": Машины и механизмы. Обоснования типа ФСЭМ, ключевые слова 'ЭМ', 'Маш.'.\n" .
                    "- \"Labor\": Оплата труда. Ключевые слова 'ЗП', 'ОТ', 'Трудозатраты', 'ТЗ'.\n" .
                    "- \"Overhead\": Накладные расходы и прибыль. Ключевые слова 'НР', 'СП', 'Накладные', 'Прибыль'.\n\n" .
                    "4. HeaderSchema:\n" .
                    "{\n" .
                    "  \"pos\": integer (индекс колонки №),\n" .
                    "  \"code\": integer (индекс колонки Шифр),\n" .
                    "  \"name\": integer (индекс колонки Наименование),\n" .
                    "  \"unit\": integer (индекс колонки Ед.изм.),\n" .
                    "  \"quantity\": integer (индекс колонки Кол-во),\n" .
                    "  \"price\": integer (индекс колонки Цена/Сумма)\n" .
                    "}\n\n" .
                    "5. ObjectPassport:\n" .
                    "{\n" .
                    "  \"object_type\": \"estimate\",\n" .
                    "  \"object_id\": string,\n" .
                    "  \"document_type\": DocumentType,\n" .
                    "  \"project_title\": string | null,\n" .
                    "  \"estimate_reference\": string | null,\n" .
                    "  \"target\": DocumentTarget,\n" .
                    "  \"object_address\": string | null\n" .
                    "}\n\n" .
                    "6. DocumentObject:\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"generator\": string | null,\n" .
                    "  \"items_count\": integer,\n" .
                    "  \"items_count_total\": integer,\n" .
                    "  \"has_footer\": boolean\n" .
                    "}\n\n" .
                    "7. DocumentDetailObject (extends DocumentObject):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"generator\": string | null,\n" .
                    "  \"items_count\": integer,\n" .
                    "  \"items_count_total\": integer,\n" .
                    "  \"has_footer\": boolean,\n" .
                    "  \"header_schema\": HeaderSchema,\n" .
                    "  \"footer\": FooterItem[]\n" .
                    "}\n\n" .
                    "8. EstimateItemResource:\n" .
                    "{\n" .
                    "  \"code\": string | null,\n" .
                    "  \"name\": string,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null,\n" .
                    "  \"resource_type\": ResourceType\n" .
                    "}\n\n" .
                    "9. EstimateItem:\n" .
                    "{\n" .
                    "  \"pos\": integer | null,\n" .
                    "  \"code\": string | null,\n" .
                    "  \"name\": string,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null,\n" .
                    "  \"children\": EstimateItemResource[],\n" .
                    "  \"modifiers\": object[]\n" .
                    "}\n\n" .
                    "10. FooterItem:\n" .
                    "{\n" .
                    "  \"label\": string,\n" .
                    "  \"value\": float,\n" .
                    "  \"children\": FooterItem[]\n" .
                    "}\n\n" .
                    "11. PrimaryAnalysisResponse:\n" .
                    "{\n" .
                    "  \"has_estimates\": boolean,\n" .
                    "  \"description\": string (max 40 chars),\n" .
                    "  \"tags\": string[],\n" .
                    "  \"objects\": DocumentObject[],\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null\n" .
                    "}\n\n" .
                    "12. EstimateFetchResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": EstimateItem[]\n" .
                    "}\n\n" .
                    "13. EstimateDocumentResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": DocumentDetailObject\n" .
                    "}",
            ],

            // --- ГРУППА: SYSTEM (Базовые настройки) ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 10,
                'content' => "Ты — мастер-инженер сметного дела с экспертными навыками OCR.\n" .
                    "Твоя задача: анализировать документы и извлекать структуру данных.\n" .
                    "ОСНОВНЫЕ ТРЕБОВАНИЯ:\n" .
                    "1. ОТВЕТ СТРОГО В JSON. Никакого текста или markdown-оберток.\n" .
                    "2. МНОЖЕСТВЕННОСТЬ: В документе может быть более одной сметы (объекта).\n" .
                    "3. ПОРЯДОК: Следи за нумерацией позиций при разрывах на листы.\n" .
                    "4. ЗАВЕРШЕНИЕ: Игнорируй всё, что ниже итогов или подписей.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS ---
            [
                'key' => 'primary_analysis_logic',
                'group' => 'primary_analysis',
                'priority' => 20,
                'content' => "Выполни анализ, используя схему PrimaryAnalysisResponse.\n" .
                    "Для каждого объекта заполни DocumentObject.\n" .
                    "Используй подробную логику определения DocumentType и DocumentTarget из схем.\n" .
                    "Поля могут быть null, если данных нет.\n" .
                    "Учитывай объединенные ячейки в шапке документа.",
            ],

            // --- ГРУППА: COLUMN_MAPPING ---
            [
                'key' => 'header_schema_mapping_rules',
                'group' => 'column_mapping',
                'priority' => 30,
                'content' => "Определи индексы столбцов (HeaderSchema) по алиасам:\n" .
                    "- `pos`: № п/п, №, поз, n.\n" .
                    "- `code`: Шифр, Обоснование, Код, Норматив.\n" .
                    "- `name`: Наименование работ и затрат, Товар.\n" .
                    "- `unit`: Ед. изм., Измеритель.\n" .
                    "- `quantity`: Количество, Объем, Всего.\n" .
                    "- `price`: Стоимость всего, Итого руб, всего затрат.",
            ],

            // --- ГРУППА: DETAILED_PARSING ---
            [
                'key' => 'estimate_tree_hierarchy_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "Сформируй дерево EstimateContent (схемы EstimateItem и EstimateItemResource).\n" .
                    "Логика вложенности:\n" .
                    "1. ПРАВИЛО No-Number: Если `pos` пуст, но есть `code` и `name` — это EstimateItemResource.\n" .
                    "2. ПРАВИЛО COMMENT: Если в колонке №1 (`pos`) находится НЕ число,\n" .
                    "записывай это в поле `comment` текущего EstimateItem или EstimateItemResource.\n" .
                    "3. НАСЛЕДОВАНИЕ: Всегда возвращай section_name и sub_section_name родителя.",
            ],
            [
                'key' => 'resource_item_processing',
                'group' => 'detailed_parsing',
                'priority' => 41,
                'content' => "Обработка EstimateItemResource:\n" .
                    "- Классифицируй ResourceType, опираясь на логику определения из схемы.\n" .
                    "- СКЛЕЙКА: Объединяй многострочное Наименование через пробел.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS & DATA_CLEANING ---
            [
                'key' => 'footer_totals_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Извлеки итоговые начисления в массив FooterItem.\n" .
                    "Поддерживай иерархию (children), если итоги сгруппированы.\n" .
                    "Ищи: 'Итого', 'НДС', 'Сметная прибыль', 'Накладные расходы'.",
            ],
            [
                'key' => 'data_hygiene_rules',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "Гигиена данных:\n" .
                    "1. Числа (float): Удаляй валюту, пробелы. Точка — разделитель.\n" .
                    "2. Математика: Вычисляй формулы внутри ячеек.\n" .
                    "3. Текст: Удаляй \\n и \\r, заменяя их на пробелы.",
            ],
        ];

        PromptRule::query()->delete();

        foreach ($rules as $rule) {
            PromptRule::updateOrCreate(
                ['key' => $rule['key']],
                [
                    'group' => $rule['group'],
                    'priority' => $rule['priority'],
                    'content' => $rule['content'],
                    'version' => 1,
                    'is_active' => true,
                ]
            );
        }
    }
}
