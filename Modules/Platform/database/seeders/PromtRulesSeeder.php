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
            // --- ГРУППА: SYSTEM (Базовые настройки) ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 1,
                'content' => "Ты — мастер-инженер сметного дела с экспертными навыками OCR.\n" .
                    "Твоя задача: анализировать документы и извлекать структуру данных.\n" .
                    "ОСНОВНЫЕ ТРЕБОВАНИЯ:\n" .
                    "1. ОТВЕТ СТРОГО В JSON. Никакого текста, пояснений или markdown-оберток.\n" .
                    "2. ПРОВЕРКА ПОРЯДКА: Сметы часто разбиты на листы. Следи за нумерацией позиций.\n" .
                    "3. ПРИЗНАК ОКОНЧАНИЯ: Таблица завершается строками 'Итого', 'Всего по смете'\n" .
                    "или блоком подписей. Всё, что ниже этих строк — игнорируй.\n" .
                    "4. ВАЛИДАЦИЯ: Если признаков сметы нет, верни {\"has_estimates\": false}.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS (Предварительный анализ) ---
            [
                'key' => 'primary_analysis_root_schema',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй файл и определи наличие сметной документации.\n" .
                    "Учти, что смет (объектов) в одном файле может быть несколько.\n" .
                    "Ожидаемая схема ответа:\n" .
                    "{\n" .
                    "  \"has_estimates\": boolean,\n" .
                    "  \"description\": \"Краткое описание сути (до 40 символов)\",\n" .
                    "  \"tags\": [\"тэг1\", \"тэг2\"], (2-4 тега: смета, акт, письмо, извещение),\n" .
                    "  \"objects\": DocumentObject[], (массив найденных смет),\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": \"текст ошибки, если анализ невозможен\"\n" .
                    "}",
            ],
            [
                'key' => 'document_object_schema_details',
                'group' => 'primary_analysis',
                'priority' => 11,
                'content' => "Схема DocumentObject:\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport (метаданные),\n" .
                    "  \"generator\": \"smeta.ru | гранд-смета | иное | null\",\n" .
                    "  \"header_schema\": HeaderSchema (маппинг колонок),\n" .
                    "  \"items_count\": integer (кол-во EstimateItem без подресурсов),\n" .
                    "  \"items_count_total\": integer (кол-во всех строк с ресурсами),\n" .
                    "  \"has_footer\": boolean (наличие итогов)\n" .
                    "}",
            ],
            [
                'key' => 'object_passport_schema_details',
                'group' => 'primary_analysis',
                'priority' => 12,
                'content' => "Схема ObjectPassport:\n" .
                    "- `object_type`: всегда 'estimate'.\n" .
                    "- `object_id`: Номер сметы (например, \"Смета №123-А\").\n" .
                    "- `document_type`: estimate|act|resource_calculation|defect_list|null.\n" .
                    "- `project_title`: Название стройки.\n" .
                    "- `estimate_reference`: Шифр (ЛСР).\n" .
                    "- `target`: Схема DocumentPassportTarget.\n" .
                    "- `object_address`: Адрес из шапки.\n" .
                    "ВАЖНО: Поля могут быть пустыми. Учитывай объединение ячеек в шапке.\n" .
                    "Данные о количестве могут занимать 3 столбца (ед. изм, коэф, всего).",
            ],
            [
                'key' => 'document_passport_target_dictionary',
                'group' => 'primary_analysis',
                'priority' => 13,
                'content' => "Справочник DocumentPassportTarget (цель документа):\n" .
                    "- `construction`: новое строительство.\n" .
                    "- `renovation`: ремонт, реконструкция, демонтаж.\n" .
                    "- `medical_equipment`: мед. оборудование.\n" .
                    "- `engineering`: инженерные системы, электрика.\n" .
                    "Если цель не определена — null.",
            ],

            // --- ГРУППА: COLUMN_MAPPING (Шапка таблицы) ---
            [
                'key' => 'header_schema_mapping_rules',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "HeaderSchema: Определи индексы столбцов по алиасам.\n" .
                    "- `pos`: № п/п, №, поз, n.\n" .
                    "- `code`: Шифр, Обоснование, Код, Норматив.\n" .
                    "- `name`: Наименование работ и затрат, Товар.\n" .
                    "- `unit`: Ед. изм., Измеритель.\n" .
                    "- `quantity`: Количество, Объем, Всего.\n" .
                    "- `price`: Стоимость всего, Итого руб, всего затрат.",
            ],

            // --- ГРУППА: DETAILED_PARSING (Разбор позиций) ---
            [
                'key' => 'estimate_tree_hierarchy',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "Иерархия EstimateContent (Дерево):\n" .
                    "1. EstimateSection: `name` (Раздел №), `children`.\n" .
                    "2. EstimateSubSection: `name`, `children`.\n" .
                    "3. EstimateItem: `pos`, `code`, `name`, `unit`, `quantity`, `price`,\n" .
                    "   `children` (ResourceItem[]), `modifiers`.\n" .
                    "4. ResourceItem: Подэлемент (ресурс).",
            ],
            [
                'key' => 'no_number_logic_and_comments_extended',
                'group' => 'detailed_parsing',
                'priority' => 35,
                'content' => "Логика обработки строк:\n" .
                    "1. ПРАВИЛО No-Number: Если `pos` пуст, но есть `code` и `name` — это ResourceItem.\n" .
                    "   Вкладывай его в `children` последнего EstimateItem.\n" .
                    "2. ПРАВИЛО COMMENT: Если в `pos` (№1) НЕ число (текст/буквы) — записывай\n" .
                    "   это значение в поле `comment` элемента (EstimateItem или ResourceItem).",
            ],
            [
                'key' => 'resource_item_schema_and_stitching',
                'group' => 'detailed_parsing',
                'priority' => 38,
                'content' => "Схема ResourceItem и классификация:\n" .
                    "Определи `resource_type`:\n" .
                    "- 'Material': МАТ, МЦ, Материалы.\n" .
                    "- 'Machine': ЭМ, МАШ, Техника.\n" .
                    "- 'Labor': ОТ, ЗП, Трудозатраты.\n" .
                    "- 'Overhead': НР, СП, Накладные расходы.\n" .
                    "СКЛЕЙКА: Если наименование разбито на несколько строк, объединяй их\n" .
                    "через пробел, пока не встретишь новый код или номер позиции.",
            ],
            [
                'key' => 'contextual_navigation_logic',
                'group' => 'detailed_parsing',
                'priority' => 45,
                'content' => "Контекст (Наследование заголовков):\n" .
                    "При парсинге порций данных всегда возвращай текущий путь в дереве.\n" .
                    "Поля `section_name` и `sub_section_name` должны быть заполнены\n" .
                    "актуальными значениями родителя, даже если заголовка нет в текущей порции.\n" .
                    "Разделом считается строка 'Раздел №' или заголовок на всю ширину таблицы.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS & DATA_CLEANING ---
            [
                'key' => 'footer_totals_extraction',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Итоги (Footer): Найди 'Итого', 'НДС', 'Всего по смете'.\n" .
                    "Выведи массив объектов: { \"label\": string, \"value\": float }.",
            ],
            [
                'key' => 'data_hygiene_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "DATA HYGIENE:\n" .
                    "1. ЧИСЛА: Только float. Удаляй 'руб.', пробелы. Точка — разделитель.\n" .
                    "2. МАТЕМАТИКА: Вычисляй формулы в ячейках (напр. 10*1.15).\n" .
                    "3. ТЕКСТ: Удаляй \\n и \\r внутри ячеек, заменяя на пробелы.",
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
