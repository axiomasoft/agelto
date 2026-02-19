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
            // --- 1. ПЕРВИЧНЫЙ АНАЛИЗ (PASSPORT) ---
            [
                'key' => 'document_passport',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй шапку документа и заполни паспорт объекта.\n" .
                    "Расшифровка полей:\n" .
                    "- `document_type`: Тип документа. Варианты: 'estimate' (смета/ЛСР), 'act' (акт КС-2/приемка), 'resource_calculation' (ведомость потребности в материалах), 'defect_list' (дефектная ведомость).\n" .
                    "- `project_title`: Полное название стройки или объекта (напр. 'МКОУ ЗАТО Знаменск СОШ № 236').\n" .
                    "- `estimate_reference`: Шифр или номер сметы/акта (напр. 'ЛСР-02-01-03').\n" .
                    "- `target`: Целевое назначение. Варианты: 'construction' (новое строительство), 'renovation' (ремонт/демонтаж), 'medical_equipment' (мед. оборудование/поставка), 'engineering' (инженерные сети/электрика).\n" .
                    "- `object_address`: Юридический или строительный адрес объекта из шапки.\n\n" .
                    "Ожидаемый JSON:\n" .
                    "{\n" .
                    "  \"document_type\": \"estimate\",\n" .
                    "  \"project_title\": \"МКОУ ЗАТО Знаменск СОШ № 236\",\n" .
                    "  \"estimate_reference\": \"ЛСР-02-01-03\",\n" .
                    "  \"target\": \"renovation\",\n" .
                    "  \"object_address\": \"г. Знаменск, ул. Ленина, 10\"\n" .
                    "}",
            ],

            // --- 2. УНИВЕРСАЛЬНЫЙ МАППИНГ КОЛОНОК (АЛИАСЫ) ---
            [
                'key' => 'column_mapping_master',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "Используй расширенные алиасы для сопоставления колонок:\n" .
                    "- pos: [№, № п/п, поз, Пункт, Номер, n]\n" .
                    "- code: [Шифр, Обоснование, Код, Артикул, Норматив]\n" .
                    "- name: [Наименование работ и затрат, Наименование, Описание, Товар]\n" .
                    "- unit: [Ед. изм., Измеритель, Единица, Ед.]\n" .
                    "- quantity: [Количество всего, Кол-во, Объем, Всего]\n" .
                    "- price: [Сметная стоимость руб. всего, Стоимость всего, Итого руб, Цена за ед.]",
            ],

            // --- 3. ЛОГИКА СКЛЕЙКИ (Критично для XLSX) ---
            [
                'key' => 'line_stitching_logic',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "ПРАВИЛО СКЛЕЙКИ: В XLSX/CSV выгрузках текст одной позиции часто разбит на несколько строк.\n" .
                    "Если текущая строка НЕ имеет значения в 'pos' и 'code', но имеет текст в поле наименования — добавь этот текст к полю 'name' предыдущей валидной позиции через пробел.",
            ],

            // --- 4. ИЕРАРХИЯ: СЕКЦИИ И ПОДРЕСУРСЫ ---
            [
                'key' => 'tree_structure_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "Соблюдай вложенность (Nested JSON):\n" .
                    "1. Раздел (type: section) — заголовок верхнего уровня.\n" .
                    "2. Подраздел (type: subsection) — вложен в раздел.\n" .
                    "3. Позиция (type: item) — основная работа с 'pos' и 'code'.\n" .
                    "4. Ресурс (type: resource) — идет сразу за item, имеет code (напр. ФССЦ, 21.1), но НЕ имеет своего 'pos'. Вкладывай в массив 'children' родительского item.",
            ],

            // --- 5. КОЭФФИЦИЕНТЫ И НАЧИСЛЕНИЯ ---
            [
                'key' => 'coefficients_logic',
                'group' => 'detailed_parsing',
                'priority' => 50,
                'content' => "Ищи коэффициенты и поправки (напр. 'К=1.15', 'НР от ФОТ').\n" .
                    "Записывай их в массив `modifiers` внутри соответствующего элемента 'item'. Структура: { \"name\": \"название\", \"value\": \"значение\" }.",
            ],

            // --- 6. АНАЛИЗ ПОДВАЛА (FOOTER) ---
            [
                'key' => 'footer_total_logic',
                'group' => 'footer_analysis',
                'priority' => 60,
                'content' => "Найди итоговые начисления в конце документа (ИТОГО, НДС, Сметная прибыль).\n" .
                    "Выведи массив объектов:\n" .
                    "{\n" .
                    "  \"footer\": [\n" .
                    "    { \"label\": \"Итого прямые затраты\", \"value\": \"988110.00\" },\n" .
                    "    { \"label\": \"Всего по смете с НДС\", \"value\": \"1250000.50\" }\n" .
                    "  ]\n" .
                    "}",
            ],

            // --- 7. ПРАВИЛА ЧИСТКИ (DATA HYGIENE) ---
            [
                'key' => 'cleaning_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "ФИНАЛЬНАЯ ОБРАБОТКА:\n" .
                    "1. Числа: Только float (через точку), удаляй 'руб.', 'тыс.', пробелы.\n" .
                    "2. Формулы: Если в ячейке формула (напр. '0.15*1.2'), вычисли итог или извлеки число.\n" .
                    "3. Ответ: СТРОГО JSON без markdown-оберток (без ```json). Текст должен начинаться с { и заканчиваться }.",
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
