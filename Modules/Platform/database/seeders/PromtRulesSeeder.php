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
                    "  \"generator\": string | null (Программа генерации, например 'Гранд-Смета'),\n" .
                    "  \"items_count\": integer (Количество основных позиций сметы),\n" .
                    "  \"items_count_total\": integer (Общее количество строк, включая подресурсы),\n" .
                    "  \"has_footer\": boolean (Наличие блока итогов)\n" .
                    "}\n\n" .
                    "8. DocumentDetailObject (Расширенный объект, extends DocumentObject):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"generator\": string | null,\n" .
                    "  \"items_count\": integer,\n" .
                    "  \"items_count_total\": integer,\n" .
                    "  \"has_footer\": boolean,\n" .
                    "  \"header_schema\": HeaderSchema (Распознанная структура колонок),\n" .
                    "  \"footer\": FooterItem[] (Массив итоговых начислений)\n" .
                    "}\n\n" .
                    "9. EstimateItem (Позиция сметы):\n" .
                    "{\n" .
                    "  \"pos\": integer | null (Номер позиции. Соответствует 'pos' в HeaderSchema),\n" .
                    "  \"code\": string | null (Шифр. Соответствует 'code' в HeaderSchema),\n" .
                    "  \"name\": string (Наименование работ. Соответствует 'name' в HeaderSchema),\n" .
                    "  \"unit\": string | null (Ед. изм. Соответствует 'unit' в HeaderSchema),\n" .
                    "  \"quantity\": float | null (Объем. Соответствует 'quantity' в HeaderSchema),\n" .
                    "  \"price\": float | null (Сумма. Соответствует 'price' в HeaderSchema),\n" .
                    "  \"children\": EstimateItemResource[] (Вложенные материалы/механизмы),\n" .
                    "  \"modifiers\": object[] (Коэффициенты к позиции)\n" .
                    "}\n\n" .
                    "10. EstimateSubSection (Подраздел сметы):\n" .
                    "{\n" .
                    "  \"name\": string (Название подраздела),\n" .
                    "  \"children\": EstimateItem[] (Позиции внутри подраздела)\n" .
                    "}\n\n" .
                    "11. EstimateSection (Раздел сметы):\n" .
                    "{\n" .
                    "  \"name\": string (Название раздела),\n" .
                    "  \"children\": (EstimateSubSection | EstimateItem)[] (Подразделы или прямые позиции)\n" .
                    "}\n\n" .
                    "12. EstimateItemResource (Ресурс позиции):\n" .
                    "{\n" .
                    "  \"code\": string | null (Шифр ресурса),\n" .
                    "  \"name\": string (Наименование ресурса),\n" .
                    "  \"unit\": string | null (Ед. изм.),\n" .
                    "  \"quantity\": float | null (Количество),\n" .
                    "  \"price\": float | null (Стоимость),\n" .
                    "  \"comment\": string | null (Текст из колонки 'pos', если там не число),\n" .
                    "  \"resource_type\": ResourceType (Тип ресурса)\n" .
                    "}\n\n" .
                    "13. FooterItem (Строка итогов):\n" .
                    "{\n" .
                    "  \"label\": string (Название итога),\n" .
                    "  \"value\": float (Сумма),\n" .
                    "  \"children\": FooterItem[] (Вложенные уточняющие итоги)\n" .
                    "}\n\n" .
                    "14. Response Schemas (Структуры ответов API):\n\n" .
                    "- PrimaryAnalysisResponse (Первичное сканирование):\n" .
                    "  {\n" .
                    "    \"has_estimates\": boolean (Найдены ли сметы),\n" .
                    "    \"description\": string (Описание контента, до 40 симв),\n" .
                    "    \"tags\": string[] (Теги: 'ремонт', 'лср'),\n" .
                    "    \"objects\": DocumentObject[] (Найденные паспорта документов),\n" .
                    "    \"success\": boolean,\n" .
                    "    \"message\": string | null\n" .
                    "  }\n\n" .
                    "- EstimateFetchResponse (Извлечение позиций):\n" .
                    "  {\n" .
                    "    \"success\": boolean,\n" .
                    "    \"message\": string | null,\n" .
                    "    \"data\": (EstimateItem | EstimateSection)[] (Дерево позиций и разделов)\n" .
                    "  }\n\n" .
                    "- EstimateDocumentResponse (Полные данные):\n" .
                    "  {\n" .
                    "    \"success\": boolean,\n" .
                    "    \"message\": string | null,\n" .
                    "    \"data\": DocumentDetailObject (Объект с шапкой и подвалом)\n" .
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
                    "2. МНОЖЕСТВЕННОСТЬ: В файле может быть более одной сметы. Определяй границы каждой.\n" .
                    "3. ПОРЯДОК: Сметы часто разбиты на страницы. Сохраняй контекст и нумерацию.\n" .
                    "4. ТОЧКА ОСТАНОВА: Завершай парсинг ТЕКУЩЕГО объекта при встрече блока подписей,\n" .
                    "но продолжай поиск в файле, так как за ними может следовать новая смета.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS ---
            [
                'key' => 'primary_analysis_logic',
                'group' => 'primary_analysis',
                'priority' => 20,
                'content' => "Выполни первичный анализ по схеме PrimaryAnalysisResponse.\n" .
                    "Для каждого объекта заполни DocumentObject.\n" .
                    "Определяй DocumentType и DocumentTarget строго по маркерам.\n" .
                    "При определении HeaderSchema:\n" .
                    "- Тщательно определи индексы колонок (от 0).\n" .
                    "- Учитывай colspan в шапке.\n" .
                    "- Заполняй full_name, склеивая заголовок группы и ячейки.",
            ],

            // --- ГРУППА: DETAILED_PARSING ---
            [
                'key' => 'estimate_tree_hierarchy_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "Сформируй дерево данных для EstimateFetchResponse.\n" .
                    "1. ИЕРАРХИЯ: Используй EstimateSection и EstimateSubSection для группировки.\n" .
                    "2. ПРАВИЛО No-Number: Если колонка `pos` пуста, но есть `code` и `name` — это EstimateItemResource.\n" .
                    "   Вкладывай такие строки в `children` последнего найденного EstimateItem.\n" .
                    "3. СКЛЕЙКА: Если наименование работы разбито на строки, объединяй через пробел.\n" .
                    "4. КОДЫ: Убедись, что извлеченные `code`, `name` и `unit` соответствуют HeaderSchema.",
            ],
            [
                'key' => 'resource_item_processing',
                'group' => 'detailed_parsing',
                'priority' => 41,
                'content' => "При обработке EstimateItemResource:\n" .
                    "- ПРАВИЛО COMMENT: Если в колонке `pos` текст/буквы (напр. 'прим.'), пиши это в `comment` ресурса.\n" .
                    "- Определи тип ресурса в поле `resource_type` (Material|Machine|Labor|Overhead).\n" .
                    "- Используй анализ шифров и ключевых слов.\n" .
                    "- Очищай данные от символов переноса строк внутри ячеек.",
            ],

            // --- ГРУППА: FOOTER_ANALYSIS ---
            [
                'key' => 'footer_totals_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Извлеки итоги документа в массив FooterItem.\n" .
                    "СОБЛЮДАЙ ИЕРАРХИЮ: Строки 'в том числе' должны быть вложены в `children` родителя.\n" .
                    "Ключевые слова: 'Итого', 'НДС', 'Сметная прибыль', 'Накладные расходы'.",
            ],

            // --- ГРУППА: DATA_CLEANING ---
            [
                'key' => 'data_hygiene_rules',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "Стандарты очистки:\n" .
                    "1. ЧИСЛА: Приводи к float. Точка — разделитель. Удаляй валюту.\n" .
                    "2. МАТЕМАТИКА: Вычисляй формулы в ячейках (напр. 10*1.15).\n" .
                    "3. ТЕКСТ: Удаляй двойные пробелы и невидимые символы.",
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
