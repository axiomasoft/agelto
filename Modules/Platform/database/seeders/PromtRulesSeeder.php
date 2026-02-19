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
            // --- ГРУППА: SYSTEM (Базовые инструкции, всегда в начале) ---
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

            // --- ГРУППА: PRIMARY_ANALYSIS (Предварительный анализ файла) ---
            [
                'key' => 'primary_analysis_schema',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй файл и определи наличие сметной документации. Учти, что смет в одном файле может быть несколько.\n" .
                    "Ожидаемая схема ответа:\n" .
                    "{\n" .
                    "  \"has_estimates\": boolean,\n" .
                    "  \"description\": \"Краткое описание (до 40 символов, например: Ремонт кровли СОШ №236)\",\n" .
                    "  \"tags\": [\"смета\", \"письмо\", \"извещение\"], (от 2 до 4 тегов)\n" .
                    "  \"objects\": DocumentObject[], (массив найденных смет)\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": \"Текст ошибки, если анализ невозможен\"\n" .
                    "}",
            ],
            [
                'key' => 'document_object_schema',
                'group' => 'primary_analysis',
                'priority' => 11,
                'content' => "Схема DocumentObject:\n" .
                    "- `passport`: схема ObjectPassport.\n" .
                    "- `generator`: Программа, в которой создана смета (например: 'smeta.ru', 'Гранд-Смета'). Если не определено — null.\n" .
                    "- `header_schema`: Определенная HeaderSchema для этой сметы.\n" .
                    "- `items_count`: Количество основных позиций (EstimateItem).\n" .
                    "- `items_count_total`: Общее количество элементов, включая подресурсы (ResourceItem).\n" .
                    "- `has_footer`: boolean (наличие блока итогов).",
            ],
            [
                'key' => 'object_passport_schema',
                'group' => 'primary_analysis',
                'priority' => 12,
                'content' => "Схема ObjectPassport (метаданные конкретной сметы):\n" .
                    "- `object_type`: всегда 'estimate'.\n" .
                    "- `object_id`: Номер или ID сметы (например: 'Смета №123-А').\n" .
                    "- `document_type`: Схема DocumentType (выбери подходящее или оставь пустым).\n" .
                    "- `project_title`: Название стройки/объекта.\n" .
                    "- `estimate_reference`: Шифр/номер (ЛСР/Акт).\n" .
                    "- `target`: Схема DocumentPassportTarget.\n" .
                    "- `object_address`: Адрес из шапки.\n\n" .
                    "ВАЖНО: Поля могут быть пустыми, если данных нет в тексте.",
            ],
            [
                'key' => 'passport_dictionaries',
                'group' => 'primary_analysis',
                'priority' => 13,
                'content' => "Справочники для паспорта:\n" .
                    "1. DocumentType (Типы): 'estimate' (смета), 'act' (акт КС-2), 'resource_calculation' (ведомость), 'defect_list' (дефектная ведомость).\n" .
                    "2. DocumentPassportTarget (Цели): 'construction' (стройка), 'renovation' (ремонт), 'medical_equipment' (мед. оборудование), 'engineering' (инженерия).\n" .
                    "3. ПРАВИЛО ШАПКИ: Данные о количестве могут занимать 3 столбца (на единицу, коэффициенты, всего). Сопоставляй их с нижними ячейками.",
            ],

            // --- ГРУППА: COLUMN_MAPPING (Схема колонок) ---
            [
                'key' => 'header_schema_definition',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "HeaderSchema (Маппинг колонок таблицы):\n" .
                    "Определи индексы столбцов по алиасам:\n" .
                    "- `pos`: (№ п/п, №, поз, n) — порядковый номер.\n" .
                    "- `code`: (Обоснование, Шифр, Норматив) — код расценки.\n" .
                    "- `name`: (Наименование работ и затрат, Товар) — описание.\n" .
                    "- `unit`: (Ед. изм., Измеритель) — единица измерения.\n" .
                    "- `quantity`: (Кол-во всего, Количество) — объем.\n" .
                    "- `price`: (Стоимость всего, Итого руб, Всего затрат) — общая сумма.",
            ],

            // --- ГРУППА: DETAILED_PARSING (Ядро обработки данных) ---
            [
                'key' => 'estimate_content_hierarchy',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "Иерархия EstimateContent:\n" .
                    "1. EstimateSection: `name` (Заголовок раздела), `children` (массив подразделов/позиций).\n" .
                    "2. EstimateSubSection: `name` (Подзаголовок), `children` (позиции).\n" .
                    "3. EstimateItem: Основная работа. Поля: `pos`, `code`, `name`, `unit`, `quantity`, `price`, `children` (ResourceItem[]), `modifiers` (Коэффициенты).\n" .
                    "4. ResourceItem: Ресурс/под-элемент. Вкладывается в `children` родительского EstimateItem.",
            ],
            [
                'key' => 'no_number_and_comments',
                'group' => 'detailed_parsing',
                'priority' => 35,
                'content' => "Логика обработки подэлементов:\n" .
                    "1. ПРАВИЛО No-Number: Если у строки пустая колонка `pos` (№1), но заполнены 'Шифр' и 'Наименование' — это ResourceItem для последней позиции с номером.\n" .
                    "2. COMMENT RULE: Если в колонке `pos` (№1) стоит НЕ число (текст, буквы, примечания), записывай это значение в поле `comment` текущего элемента (EstimateItem или ResourceItem).",
            ],
            [
                'key' => 'resource_classification_logic',
                'group' => 'detailed_parsing',
                'priority' => 38,
                'content' => "Схема ResourceItem и классификация:\n" .
                    "Внутри `resource_type` укажи тип:\n" .
                    "- МАТ / МЦ -> 'Material'\n" .
                    "- ЭМ / МАШ -> 'Machine'\n" .
                    "- ОТ / ЗП -> 'Labor'\n" .
                    "- НР / СП -> 'Overhead'\n" .
                    "ОБРАБОТКА ИМЕН: Объединяй текст из колонки 'Наименование' всех строк, относящихся к одной позиции, пока не встретишь начало следующего кода/номера.",
            ],
            [
                'key' => 'navigation_rules',
                'group' => 'detailed_parsing',
                'priority' => 45,
                'content' => "Правила навигации (Contextual Continuity):\n" .
                    "При парсинге порций данных (например, только середины сметы) ИИ должен сохранять путь в иерархии.\n" .
                    "- Если текущая порция данных находится внутри раздела, объект ответа должен содержать актуальные `section_name` и `sub_section_name` родителя.\n" .
                    "- Разделом считается строка, текст которой начинается со слов 'Раздел №' или проходит через все колонки таблицы.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS & DATA_CLEANING ---
            [
                'key' => 'footer_total_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Footer (Итоги): Найди итоговые начисления (НДС, Сметная прибыль, Итого).\n" .
                    "- Выведи массив объектов: { \"label\": string, \"value\": float }.",
            ],
            [
                'key' => 'data_hygiene_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "DATA HYGIENE:\n" .
                    "1. ЧИСЛА: Только float (точка как разделитель). Удали 'руб.', 'тыс.', пробелы.\n" .
                    "2. ФОРМУЛЫ: Если в ячейке формула (напр. '10*1.05'), вычисли результат.\n" .
                    "3. ТЕКСТ: Удали невидимые символы переноса (\\n, \\r) внутри ячеек.",
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
