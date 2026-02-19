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
            // --- ГРУППА: SCHEMAS (Единый блок технических контрактов) ---
            [
                'key' => 'global_json_schemas',
                'group' => 'schemas',
                'priority' => 1,
                'content' => "Ниже описаны все схемы данных, которые должны использоваться в ответах:\n\n" .
                    "1. PrimaryAnalysisResponse:\n" .
                    "{\n" .
                    "  \"has_estimates\": boolean,\n" .
                    "  \"description\": string (кратко о чем документ, до 40 символов),\n" .
                    "  \"tags\": string[] (2-4 тега: смета, письмо, извещение и т.д.),\n" .
                    "  \"objects\": DocumentObject[],\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null (заполнять ошибку, если не получается проанализировать)\n" .
                    "}\n\n" .
                    "2. DocumentObject:\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"generator\": string | null (напр. 'smeta.ru'),\n" .
                    "  \"header_schema\": HeaderSchema,\n" .
                    "  \"items_count\": integer (кол-во элементов без подресурсов),\n" .
                    "  \"items_count_total\": integer (кол-во элементов с подресурсами),\n" .
                    "  \"has_footer\": boolean\n" .
                    "}\n\n" .
                    "3. ObjectPassport:\n" .
                    "{\n" .
                    "  \"object_type\": \"estimate\",\n" .
                    "  \"object_id\": string (напр. 'Смета №123-А'),\n" .
                    "  \"document_type\": \"estimate\"|\"act\"|\"resource_calculation\"|\"defect_list\"|null,\n" .
                    "  \"project_title\": string | null,\n" .
                    "  \"estimate_reference\": string | null,\n" .
                    "  \"target\": \"construction\"|\"renovation\"|\"medical_equipment\"|\"engineering\"|null,\n" .
                    "  \"object_address\": string | null\n" .
                    "}\n\n" .
                    "4. EstimateItem (Позиция сметы):\n" .
                    "{\n" .
                    "  \"pos\": integer | null,\n" .
                    "  \"code\": string | null,\n" .
                    "  \"name\": string,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null,\n" .
                    "  \"children\": ResourceItem[],\n" .
                    "  \"modifiers\": object[]\n" .
                    "}\n\n" .
                    "5. ResourceItem (Подэлемент/Ресурс):\n" .
                    "{\n" .
                    "  \"code\": string | null,\n" .
                    "  \"name\": string,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null,\n" .
                    "  \"resource_type\": \"Material\"|\"Machine\"|\"Labor\"|\"Overhead\"\n" .
                    "}\n\n" .
                    "6. FooterItem (Итоги):\n" .
                    "{\n" .
                    "  \"label\": string,\n" .
                    "  \"value\": float\n" .
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
                    "2. МНОЖЕСТВЕННОСТЬ: В одном документе может быть более одной сметы (объекта).\n" .
                    "3. ПОРЯДОК: Следи за нумерацией позиций при разрывах на листы.\n" .
                    "4. ЗАВЕРШЕНИЕ: Игнорируй всё, что ниже итогов ('Итого', 'Всего') или подписей.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS ---
            [
                'key' => 'primary_analysis_logic',
                'group' => 'primary_analysis',
                'priority' => 20,
                'content' => "Выполни предварительный анализ файла, опираясь на схему PrimaryAnalysisResponse.\n" .
                    "При заполнении ObjectPassport учитывай:\n" .
                    "- Любое поле может быть null, если данных нет.\n" .
                    "- Шапка документа может содержать объединенные ячейки.\n" .
                    "- Колонки количества могут занимать до 3 столбцов (ед. изм, коэф, всего).\n" .
                    "- Если тип документа или цель не подходят под справочник — ставь null.",
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
                'content' => "Сформируй дерево EstimateContent, используя схемы EstimateItem и ResourceItem.\n" .
                    "Логика вложенности:\n" .
                    "1. ПРАВИЛО No-Number: Если `pos` пуст, но есть `code` и `name` — это ResourceItem.\n" .
                    "2. ПРАВИЛО COMMENT: Если в колонке №1 (`pos`) находится НЕ число (текст, буквы),\n" .
                    "записывай это значение в поле `comment` текущего EstimateItem или ResourceItem.\n" .
                    "3. НАСЛЕДОВАНИЕ: При парсинге порций данных обязательно возвращай текущие\n" .
                    "section_name и sub_section_name для сохранения контекста в дереве.",
            ],
            [
                'key' => 'resource_item_processing',
                'group' => 'detailed_parsing',
                'priority' => 41,
                'content' => "Обработка ResourceItem:\n" .
                    "- Классифицируй `resource_type` (Material, Machine, Labor, Overhead).\n" .
                    "- СКЛЕЙКА: Объединяй многострочное Наименование работ в одну строку через пробел,\n" .
                    "пока не встретишь начало следующего кода или номера позиции.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS & DATA_CLEANING ---
            [
                'key' => 'footer_totals_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Извлеки итоговые начисления в массив объектов схемы FooterItem.\n" .
                    "Ищи фразы: 'Итого', 'НДС', 'Сметная прибыль', 'Накладные расходы'.",
            ],
            [
                'key' => 'data_hygiene_rules',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "Гигиена данных:\n" .
                    "1. Числа (float): Удаляй валюту, пробелы. Используй точку как разделитель.\n" .
                    "2. Математика: Вычисляй формулы внутри ячеек (напр. '10*1.05').\n" .
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
