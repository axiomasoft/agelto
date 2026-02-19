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
            // --- ГРУППА: SCHEMAS (Технические контракты с полными аннотациями) ---
            [
                'key' => 'global_json_schemas',
                'group' => 'schemas',
                'priority' => 1,
                'content' => "Ниже описаны схемы данных. Каждое поле должно соответствовать аннотации:\n\n" .
                    "1. DocumentType (Тип документа):\n" .
                    "- \"estimate\": Смета (ЛСР). Определять по заголовкам 'Локальный сметный расчет', 'Локальная смета'.\n" .
                    "- \"act\": Акт (КС-2). Определять по заголовкам 'Акт о приемке выполненных работ', 'Форма № КС-2'.\n" .
                    "- \"resource_calculation\": Ресурсная ведомость. Текст: 'Ведомость ресурсов', 'Расчет ресурсов'.\n" .
                    "- \"defect_list\": Дефектная ведомость. Текст: 'Дефектная ведомость', 'Опись работ'.\n" .
                    "- null: Если тип документа не удалось определить.\n\n" .
                    "2. DocumentTarget (Цель документа):\n" .
                    "- \"construction\": Строительство. Новые объекты, возведение зданий с нуля.\n" .
                    "- \"renovation\": Ремонт. Кап.ремонт, реконструкция, модернизация, демонтаж.\n" .
                    "- \"medical_equipment\": Медоборудование. Поставка, монтаж, ПНР медицинской техники.\n" .
                    "- \"engineering\": Инженерия. Вентиляция, ОВ, ВК, ЭОМ, связь, слаботочные системы.\n\n" .
                    "3. ResourceType (Классификация ресурса):\n" .
                    "- \"Material\": Материалы. Обоснования ФССЦ, прайс-листы, маркеры 'МАТ', 'мат.ресурс'.\n" .
                    "- \"Machine\": Машины. Обоснования ФСЭМ, маркеры 'ЭМ', 'маш.час', 'Машины и механизмы'.\n" .
                    "- \"Labor\": Труд. Маркеры 'ЗП', 'ОТ', 'ЗПМ', 'Трудозатраты', 'чел.час'.\n" .
                    "- \"Overhead\": Накладные/Прибыль. Маркеры 'НР', 'СП', 'Накладные расходы', 'Прибыль'.\n\n" .
                    "4. HeaderColumn (Описание колонки):\n" .
                    "{\n" .
                    "  \"index\": integer (Порядковый номер ячейки в строке, начиная с 0),\n" .
                    "  \"name\": string (Текст в данной ячейке),\n" .
                    "  \"full_name\": string (Текст с учетом родительской группы ячеек).\n" .
                    "  Пример: Ячейка 'Всего' под общей шапкой 'Количество' -> full_name: 'Количество Всего'\n" .
                    "}\n\n" .
                    "5. HeaderSchema (Карта колонок):\n" .
                    "{\n" .
                    "  \"pos\": HeaderColumn (Алиасы: № п/п, №, поз, n),\n" .
                    "  \"code\": HeaderColumn (Алиасы: Шифр, Обоснование, Код, Артикул, Норматив),\n" .
                    "  \"name\": HeaderColumn (Алиасы: Наименование работ и затрат, Описание, Товар),\n" .
                    "  \"unit\": HeaderColumn (Алиасы: Ед. изм., Измеритель, Единица, Ед.),\n" .
                    "  \"quantity\": HeaderColumn (Алиасы: Количество, Объем, Кол-во всего),\n" .
                    "  \"price\": HeaderColumn (Алиасы: Стоимость всего, Итого руб, Всего затрат)\n" .
                    "}\n\n" .
                    "6. ObjectPassport (Метаданные):\n" .
                    "{\n" .
                    "  \"object_type\": \"estimate\",\n" .
                    "  \"object_id\": string (Уникальный номер или индекс сметы в файле),\n" .
                    "  \"document_type\": DocumentType (Определенный тип документа),\n" .
                    "  \"reference\": string | null (Внутренняя ссылка, шифр ЛСР или номер договора),\n" .
                    "  \"target\": DocumentTarget (Целевое назначение работ),\n" .
                    "  \"object_address\": string | null (Адрес производства работ или объекта)\n" .
                    "}\n\n" .
                    "7. DocumentObject (Базовый объект документа):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport (Метаданные документа),\n" .
                    "  \"generator\": string | null (Программа, в которой создан файл, например 'Гранд-Смета'),\n" .
                    "  \"items_count\": integer (Количество основных позиций сметы без учета ресурсов),\n" .
                    "  \"items_count_total\": integer (Общее количество строк, включая подресурсы),\n" .
                    "  \"has_footer\": boolean (Наличие блока итогов в конце объекта)\n" .
                    "}\n\n" .
                    "8. DocumentDetailObject (Расширенный объект с деталями, extends DocumentObject):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport (Метаданные документа),\n" .
                    "  \"generator\": string | null (ПО генератора),\n" .
                    "  \"items_count\": integer (Кол-во позиций),\n" .
                    "  \"items_count_total\": integer (Всего строк),\n" .
                    "  \"has_footer\": boolean (Флаг итогов),\n" .
                    "  \"header_schema\": HeaderSchema (Распознанная структура колонок),\n" .
                    "  \"footer\": FooterItem[] (Массив итоговых начислений)\n" .
                    "}\n\n" .
                    "9. EstimateItem (Позиция сметы):\n" .
                    "{\n" .
                    "  \"pos\": integer | null (Номер позиции. Соответствует колонке 'pos' в HeaderSchema),\n" .
                    "  \"code\": string | null (Шифр расценки. Соответствует колонке 'code' в HeaderSchema),\n" .
                    "  \"name\": string (Наименование работ. Соответствует колонке 'name' в HeaderSchema),\n" .
                    "  \"unit\": string | null (Ед. изм. Соответствует колонке 'unit' в HeaderSchema),\n" .
                    "  \"quantity\": float | null (Объем. Соответствует колонке 'quantity' в HeaderSchema),\n" .
                    "  \"price\": float | null (Сумма. Соответствует колонке 'price' в HeaderSchema),\n" .
                    "  \"comment\": string | null (Текст из колонки 'pos', если там не числовое значение),\n" .
                    "  \"children\": EstimateItemResource[] (Вложенные ресурсы и материалы),\n" .
                    "  \"modifiers\": object[] (Коэффициенты и поправки, примененные к позиции)\n" .
                    "}\n\n" .
                    "10. EstimateItemResource (Ресурс/Материал позиции):\n" .
                    "{\n" .
                    "  \"code\": string | null (Шифр ресурса. Соответствует колонке 'code' в HeaderSchema),\n" .
                    "  \"name\": string (Наименование ресурса. Соответствует колонке 'name' в HeaderSchema),\n" .
                    "  \"unit\": string | null (Ед. изм. Соответствует колонке 'unit' в HeaderSchema),\n" .
                    "  \"quantity\": float | null (Количество ресурса),\n" .
                    "  \"price\": float | null (Стоимость ресурса),\n" .
                    "  \"comment\": string | null (Дополнительная информация по ресурсу),\n" .
                    "  \"resource_type\": ResourceType (Тип: Материал, Машина, Труд или Накладные)\n" .
                    "}\n\n" .
                    "11. FooterItem (Строка итогов):\n" .
                    "{\n" .
                    "  \"label\": string (Название итога, например 'Итого прямые затраты'),\n" .
                    "  \"value\": float (Числовое значение суммы),\n" .
                    "  \"children\": FooterItem[] (Вложенные уточняющие итоги, например 'в т.ч. материалы')\n" .
                    "}\n\n" .
                    "12. Response Schemas (Структуры ответов API):\n\n" .
                    "- PrimaryAnalysisResponse (Результат первичного сканирования):\n" .
                    "  {\n" .
                    "    \"has_estimates\": boolean (Найдены ли сметные данные в файле),\n" .
                    "    \"description\": string (Краткое описание содержимого, до 40 символов),\n" .
                    "    \"tags\": string[] (Теги для классификации: 'смета', 'ремонт' и т.д.),\n" .
                    "    \"objects\": DocumentObject[] (Список найденных документов с их паспортами),\n" .
                    "    \"success\": boolean (Флаг успешности обработки),\n" .
                    "    \"message\": string | null (Сообщение об ошибке или статусе)\n" .
                    "  }\n\n" .
                    "- EstimateFetchResponse (Результат извлечения позиций):\n" .
                    "  {\n" .
                    "    \"success\": boolean (Флаг успеха),\n" .
                    "    \"message\": string | null (Описание ошибки),\n" .
                    "    \"data\": EstimateItem[] (Массив извлеченных позиций с их ресурсами)\n" .
                    "  }\n\n" .
                    "- EstimateDocumentResponse (Полные данные документа):\n" .
                    "  {\n" .
                    "    \"success\": boolean (Флаг успеха),\n" .
                    "    \"message\": string | null (Описание ошибки),\n" .
                    "    \"data\": DocumentDetailObject (Полный объект документа с шапкой и подвалом)\n" .
                    "  }",
            ],

            // --- ГРУППА: SYSTEM (Базовые инструкции) ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 10,
                'content' => "Ты — ведущий инженер-сметчик с экспертными навыками OCR.\n" .
                    "Твоя задача: анализировать документы и извлекать структуру данных.\n" .
                    "ОСНОВНЫЕ ТРЕБОВАНИЯ:\n" .
                    "1. ОТВЕТ СТРОГО В JSON. Никакого текста или markdown-оберток.\n" .
                    "2. МНОЖЕСТВЕННОСТЬ: В одном файле может быть более одной сметы (объекта).\n" .
                    "3. ПОРЯДОК: Сметы часто разбиты на страницы. Сохраняй контекст и нумерацию.\n" .
                    "4. ТОЧКА ОСТАНОВА: Завершай парсинг при встрече итогов или блока подписей.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS (Логика распознавания) ---
            [
                'key' => 'primary_analysis_logic',
                'group' => 'primary_analysis',
                'priority' => 20,
                'content' => "Выполни первичный анализ документа по схеме PrimaryAnalysisResponse.\n" .
                    "Для каждого объекта заполни DocumentObject.\n" .
                    "Определяй DocumentType и DocumentTarget строго по маркерам из схем.\n" .
                    "Если поле в ObjectPassport отсутствует в тексте — обязательно ставь null.\n" .
                    "При определении HeaderSchema:\n" .
                    "- Тщательно определи индексы колонок (от 0).\n" .
                    "- Учитывай объединенные ячейки (colspan) в шапке документа.\n" .
                    "- Заполняй full_name, склеивая заголовок группы и текущей ячейки.",
            ],

            // --- ГРУППА: DETAILED_PARSING (Логика извлечения строк) ---
            [
                'key' => 'estimate_tree_hierarchy_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "Сформируй дерево EstimateContent на базе EstimateItem и EstimateItemResource.\n" .
                    "1. ПРАВИЛО No-Number: Если колонка `pos` пуста, но есть `code` и `name` — это EstimateItemResource.\n" .
                    "   Вкладывай такие строки в children последнего найденного EstimateItem.\n" .
                    "2. ПРАВИЛО COMMENT: Если в первой колонке (`pos`) находится текст или буквы (напр. 'прим.'),\n" .
                    "   записывай это значение в поле `comment` текущего EstimateItem или EstimateItemResource.\n" .
                    "3. СКЛЕЙКА: Если наименование работы разбито на несколько строк, объединяй их через пробел.\n" .
                    "4. КОДЫ: Убедись, что извлеченные `code`, `name` и `unit` соответствуют колонкам из HeaderSchema.",
            ],
            [
                'key' => 'resource_item_processing',
                'group' => 'detailed_parsing',
                'priority' => 41,
                'content' => "При обработке EstimateItemResource:\n" .
                    "- Определи тип ресурса в поле `resource_type` (Material|Machine|Labor|Overhead).\n" .
                    "- Используй логику анализа шифров (ФССЦ, ФСЭМ) и ключевых слов.\n" .
                    "- Очищай данные от технических символов переноса строк внутри ячеек.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS (Итоги) ---
            [
                'key' => 'footer_totals_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Извлеки итоговые начисления документа в массив объектов FooterItem.\n" .
                    "СОБЛЮДАЙ ИЕРАРХИЮ: Если строка является уточняющей ('в том числе материалы'),\n" .
                    "она должна быть вложена в children родительского итога.\n" .
                    "Ключевые слова для поиска: 'Итого', 'НДС', 'Сметная прибыль', 'Накладные расходы'.",
            ],

            // --- ГРУППА: DATA_CLEANING (Очистка) ---
            [
                'key' => 'data_hygiene_rules',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "Применяй стандарты гигиены данных:\n" .
                    "1. ЧИСЛА: Преобразуй все суммы и количества в float. Точка — разделитель.\n" .
                    "2. МАТЕМАТИКА: Если в ячейке находится формула (напр. 10*1.15), вычисли результат.\n" .
                    "3. ТЕКСТ: Очищай строки от двойных пробелов, удаляй валютные приписки.",
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