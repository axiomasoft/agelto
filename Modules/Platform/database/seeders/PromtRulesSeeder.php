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
            // --- ГРУППА: SYSTEM (Базовые инструкции) ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 1,
                'content' => "Ты — мастер-инженер сметного дела с экспертными навыками OCR. Твоя задача: анализировать документы и извлекать данные.\n" .
                    "ОСНОВНЫЕ ТРЕБОВАНИЯ:\n" .
                    "1. ОТВЕТ СТРОГО В JSON. Никакого вводного текста, пояснений или markdown-оберток (```json).\n" .
                    "2. ПОРЯДОК СТРАНИЦ: Сметы часто разбиты на листы. Следи за сквозной нумерацией позиций.\n" .
                    "3. ПРИЗНАК ОКОНЧАНИЯ: Таблица завершается строками 'Итого', 'Всего по смете' или блоком подписей. Всё, что ниже — игнорируй.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS (Предварительный анализ) ---
            [
                'key' => 'primary_analysis_schema',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй файл и определи наличие сметной документации. Учти, что смет в одном файле может быть несколько.\n" .
                    "Ожидаемая схема ответа:\n" .
                    "{\n" .
                    "  \"has_estimates\": boolean,\n" .
                    "  \"description\": \"Краткое описание (до 40 символов)\",\n" .
                    "  \"tags\": [\"смета\", \"письмо\"], (2-4 тега)\n" .
                    "  \"objects\": DocumentObject[],\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": \"заполнять ошибку, если не получается проанализировать файл\"\n" .
                    "}",
            ],
            [
                'key' => 'document_object_schema',
                'group' => 'primary_analysis',
                'priority' => 11,
                'content' => "Схема DocumentObject:\n" .
                    "- `passport`: схема ObjectPassport.\n" .
                    "- `generator`: (например, smeta.ru) — через какое ПО сгенерировано. Может быть пустым.\n" .
                    "- `header_schema`: HeaderSchema (маппинг колонок).\n" .
                    "- `items_count`: кол-во элементов в смете (без подэлементов).\n" .
                    "- `items_count_total`: кол-во элементов с подэлементами.\n" .
                    "- `has_footer`: boolean (наличие итогов).",
            ],
            [
                'key' => 'object_passport_schema',
                'group' => 'primary_analysis',
                'priority' => 12,
                'content' => "Схема ObjectPassport:\n" .
                    "- `object_type`: всегда 'estimate'.\n" .
                    "- `object_id`: \"Смета №123-А\".\n" .
                    "- `document_type`: Справочник: estimate (смета), act (акт), resource_calculation (ведомость), defect_list (дефектная ведомость). Если нет совпадений — null.\n" .
                    "- `project_title`: Наименование стройки.\n" .
                    "- `estimate_reference`: Шифр (ЛСР).\n" .
                    "- `target`: Справочник: construction (стройка), renovation (ремонт), medical_equipment (мед. оборудование), engineering (инженерия). Если нет совпадений — null.\n" .
                    "- `object_address`: Адрес объекта.\n\n" .
                    "ВАЖНО: Поля могут быть пустыми.",
            ],

            // --- ГРУППА: COLUMN_MAPPING ---
            [
                'key' => 'header_schema_definition',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "HeaderSchema (Схема шапки):\n" .
                    "Определи индексы колонок:\n" .
                    "- `pos`: (№ п/п, поз).\n" .
                    "- `code`: (Шифр, Обоснование).\n" .
                    "- `name`: (Наименование).\n" .
                    "- `unit`: (Ед. изм.).\n" .
                    "- `quantity`: (Кол-во).\n" .
                    "- `price`: (Стоимость всего, Итого руб, всего затрат).",
            ],

            // --- ГРУППА: DETAILED_PARSING ---
            [
                'key' => 'estimate_content_hierarchy',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "Иерархия (Древовидная структура):\n" .
                    "1. EstimateSection: `name` (Раздел №), `children`.\n" .
                    "2. EstimateSubSection: `name`, `children`.\n" .
                    "3. EstimateItem: `pos`, `code`, `name`, `unit`, `quantity`, `price`, `children` (ResourceItem[]), `modifiers`.\n" .
                    "4. ResourceItem: Подэлемент.",
            ],
            [
                'key' => 'no_number_and_comments',
                'group' => 'detailed_parsing',
                'priority' => 35,
                'content' => "Обработка элементов:\n" .
                    "1. Правило No-Number: Если №1 (pos) пуст, но есть Шифр и Наименование — это ResourceItem для последней строки с номером.\n" .
                    "2. COMMENT RULE: Если в `pos` НЕ число (текст/буквы) — записывай это в поле `comment` текущего EstimateItem или ResourceItem.",
            ],
            [
                'key' => 'resource_classification_logic',
                'group' => 'detailed_parsing',
                'priority' => 38,
                'content' => "ResourceItem Классификация:\n" .
                    "- МАТ/МЦ -> 'Material'\n" .
                    "- ЭМ/МАШ -> 'Machine'\n" .
                    "- ОТ/ЗП -> 'Labor'\n" .
                    "- НР/СП -> 'Overhead'\n" .
                    "СКЛЕЙКА: Объединяй текст 'Наименования' из нескольких строк (3-5 строк) в одну, пока не встретишь новый код или номер.",
            ],
            [
                'key' => 'navigation_rules',
                'group' => 'detailed_parsing',
                'priority' => 45,
                'content' => "Контекст (Наследование заголовков):\n" .
                    "При парсинге порции (например, только позиции 20-30), всегда возвращай в JSON текущие `section_name` и `sub_section_name` родительского раздела, даже если заголовок был на прошлых страницах.",
            ],

            // --- ГРУППА: FOOTER & CLEANING ---
            [
                'key' => 'footer_total_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Итоги: Найди строки 'Итого', 'НДС', 'Всего по смете'. Выведи массив {label, value}.",
            ],
            [
                'key' => 'data_hygiene_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "Чистка: float для чисел, вычисляй формулы (10*1.15), удаляй переносы строк внутри ячеек.",
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
