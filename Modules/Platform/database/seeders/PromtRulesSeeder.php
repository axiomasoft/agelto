<?php

declare(strict_types=1);

namespace Platform\Database\Seeders;

use Illuminate\Database\Seeder;
use Platform\Dto\PromtRule\Step;
use Platform\Models\Promt\PromptRule;

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
                'step' => Step::Global->value,
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
                    "3. EstimateResourceType (Классификация ресурса):\n" .
                    "- \"Material\": Материалы. Обоснования ФССЦ, прайс-листы, маркеры 'МАТ', 'мат.ресурс'.\n" .
                    "- \"Machine\": Машины. Обоснования ФСЭМ, маркеры 'ЭМ', 'маш.час', 'Машины и механизмы'.\n" .
                    "- \"Labor\": Труд. Маркеры 'ЗП', 'ОТ', 'ЗПМ', 'Трудозатраты', 'чел.час'.\n" .
                    "- \"Overhead\": Накладные/Прибыль. Маркеры 'НР', 'СП', 'Накладные расходы', 'Прибыль'.\n\n" .
                    "4. EstimateHeaderColumn (Описание колонки):\n" .
                    "{\n" .
                    "  \"index\": integer (Порядковый номер ячейки в строке, начиная с 0),\n" .
                    "  \"name\": string (Текст в данной ячейке),\n" .
                    "  \"full_name\": string (Текст с учетом родительской группы ячеек).\n" .
                    "}\n\n" .
                    "5. EstimateHeaderSchema (Карта колонок):\n" .
                    "{\n" .
                    "  \"pos\": EstimateHeaderColumn (Алиасы: № п/п, №, поз),\n" .
                    "  \"code\": EstimateHeaderColumn (Алиасы: Шифр, Обоснование, Код),\n" .
                    "  \"name\": EstimateHeaderColumn (Алиасы: Наименование работ, Описание),\n" .
                    "  \"unit\": EstimateHeaderColumn (Алиасы: Ед. изм., Измеритель),\n" .
                    "  \"quantity\": EstimateHeaderColumn (Алиасы: Количество, Объем),\n" .
                    "  \"price\": EstimateHeaderColumn (Алиасы: Стоимость всего, Итого руб)\n" .
                    "}\n\n" .
                    "6. ObjectPassport (Метаданные):\n" .
                    "{\n" .
                    "  \"object_id\": string (Уникальный индекс объекта в файле),\n" .
                    "  \"document_type\": DocumentType (Тип),\n" .
                    "  \"reference\": string | null (Шифр ЛСР или номер договора),\n" .
                    "  \"target\": DocumentTarget (Цель),\n" .
                    "  \"object_address\": string | null (Адрес)\n" .
                    "}\n\n" .
                    "7. DocumentObject (Базовый объект):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"generator\": string | null (ПО, напр. 'Гранд-Смета'),\n" .
                    "  \"items_count\": integer (Кол-во позиций),\n" .
                    "  \"items_count_total\": integer (Всего строк),\n" .
                    "  \"has_footer\": boolean\n" .
                    "}\n\n" .
                    "8. DocumentDetailObject (Расширенный объект):\n" .
                    "{\n" .
                    "  \"passport\": ObjectPassport,\n" .
                    "  \"header_schema\": EstimateHeaderSchema,\n" .
                    "  \"footer\": EstimateFooterItem[]\n" .
                    "}\n\n" .
                    "9. EstimateItem (Позиция):\n" .
                    "{\n" .
                    "  \"pos\": integer | null (Соответствует 'pos' в EstimateHeaderSchema),\n" .
                    "  \"code\": string | null (Соответствует 'code' в EstimateHeaderSchema),\n" .
                    "  \"name\": string (Соответствует 'name' в EstimateHeaderSchema),\n" .
                    "  \"unit\": string | null (Соответствует 'unit' в EstimateHeaderSchema),\n" .
                    "  \"quantity\": float | null (Соответствует 'quantity' в EstimateHeaderSchema),\n" .
                    "  \"price\": float | null (Соответствует 'price' в EstimateHeaderSchema),\n" .
                    "  \"children\": EstimateItemResource[],\n" .
                    "  \"modifiers\": EstimateModifierItem[]\n" .
                    "}\n\n" .
                    "10. EstimateModifierItem (Коэффициент):\n" .
                    "{\n" .
                    "  \"name\": string (Код, напр. 'К=1.15'),\n" .
                    "  \"description\": string (Обоснование),\n" .
                    "  \"value\": float (Число)\n" .
                    "}\n\n" .
                    "11. EstimateSection (Раздел):\n" .
                    "{\n" .
                    "  \"name\": string,\n" .
                    "  \"children\": (EstimateSubSection | EstimateItem)[]\n" .
                    "}\n\n" .
                    "12. EstimateSubSection (Подраздел):\n" .
                    "{\n" .
                    "  \"name\": string,\n" .
                    "  \"children\": EstimateItem[]\n" .
                    "}\n\n" .
                    "13. EstimateItemResource (Ресурс):\n" .
                    "{\n" .
                    "  \"code\": string | null (Шифр ресурса),\n" .
                    "  \"name\": string (Наименование),\n" .
                    "  \"unit\": string | null (Ед. изм.),\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null (Текст из колонки 'pos'),\n" .
                    "  \"resource_type\": EstimateResourceType\n" .
                    "}\n\n" .
                    "14. EstimateFooterItem (Итог):\n" .
                    "{\n" .
                    "  \"label\": string,\n" .
                    "  \"value\": float,\n" .
                    "  \"children\": EstimateFooterItem[]\n" .
                    "}\n\n" .
                    "15. Форматы ответов (Response Schemas):\n" .
                    "- PrimaryAnalysisResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": {\n" .
                    "    \"has_estimates\": boolean (Наличие хотя бы одного сметного объекта),\n" .
                    "    \"description\": string (Краткое резюме файла: что это, за какой период, какой объем),\n" .
                    "    \"tags\": string[] (Массив меток: Город, ОС, Шифр, Вид работ, ФИО),\n" .
                    "    \"objects\": DocumentObject[] (Список найденных документов)\n" .
                    "  }\n" .
                    "}\n\n" .
                    "- EstimateFetchResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": (EstimateItem | EstimateSection)[] (Массив строк сметы)\n" .
                    "}\n\n" .
                    "- EstimateDocumentResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": DocumentDetailObject (Полный объект с метаданными, шапкой и итогами)\n" .
                    "}"
            ],

            // --- ГРУППА: SYSTEM (Базовые инструкции) ---
            [
                'key' => 'system_base_instruction',
                'step' => Step::Global->value,
                'priority' => 10,
                'content' => "Ты — ведущий инженер-сметчик с экспертными навыками OCR.\n" .
                    "Твоя задача: анализировать документы и извлекать структуру данных.\n" .
                    "ОСНОВНЫЕ ТРЕБОВАНИЯ:\n" .
                    "1. ОТВЕТ СТРОГО В JSON. Никакого текста или markdown.\n" .
                    "2. МНОЖЕСТВЕННОСТЬ: В файле может быть более одной сметы.\n" .
                    "3. ПОРЯДОК: При переходе на новую страницу игнорируй повторение заголовков таблицы\n" .
                    "и продолжай сборку текущего EstimateItem, если он был разорван.\n" .
                    "4. ТОЧКА ОСТАНОВА: Завершай парсинг ТЕКУЩЕГО объекта при встрече блока подписей,\n" .
                    "но продолжай поиск в файле, так как за ними может следовать новая смета.",
            ],

            // --- ГРУППА: PRIMARY_ANALYSIS ---
            [
                'key' => 'primary_analysis_logic',
                'step' => Step::PrimaryAnalysis->value,
                'priority' => 20,
                'content' => "Выполни первичный анализ по схеме PrimaryAnalysisResponse.\n" .
                    "Для каждого объекта заполни DocumentObject.\n" .
                    "Определяй DocumentType и DocumentTarget строго по маркерам.\n" .
                    "При определении EstimateHeaderSchema:\n" .
                    "- Тщательно определи индексы колонок (от 0).\n" .
                    "- Учитывай colspan в шапке.\n" .
                    "- Заполняй full_name, склеивая заголовок группы и ячейки.",
            ],

            // --- ГРУППА: DETAILED_PARSING ---
            [
                'key' => 'estimate_tree_hierarchy_logic',
                'step' => Step::DetailedParsing->value,
                'priority' => 40,
                'content' => "Сформируй дерево данных для EstimateFetchResponse.\n" .
                    "1. ИЕРАРХИЯ: Используй EstimateSection и EstimateSubSection для группировки.\n" .
                    "2. ПРАВИЛО No-Number: Если колонка `pos` пуста, но есть `code` и `name` — это EstimateItemResource.\n" .
                    "   Вкладывай такие строки в `children` последнего найденного EstimateItem.\n" .
                    "3. СКЛЕЙКА: Если наименование или ШИФР (`code`) разбиты на строки, объединяй через пробел.\n" .
                    "   Не пытайся сокращать или переформатировать шифр (напр. ТЕР-01...).\n" .
                    "4. КОДЫ: Убедись, что извлеченные `code`, `name` и `unit` соответствуют EstimateHeaderSchema.",
            ],
            [
                'key' => 'resource_item_processing',
                'step' => Step::DetailedParsing->value,
                'priority' => 41,
                'content' => "При обработке EstimateItemResource:\n" .
                    "- ПРАВИЛО COMMENT: Если в колонке `pos` текст/буквы (напр. 'прим.'), пиши это в `comment` ресурса.\n" .
                    "- Определи тип ресурса в поле `resource_type` (Material|Machine|Labor|Overhead).\n" .
                    "- Выделяй коэффициенты в структуру EstimateModifierItem (name, description, value).",
            ],
            [
                'key' => 'data_hygiene_and_cleaning',
                'step' => Step::DetailedParsing->value,
                'priority' => 45,
                'content' => "В процессе парсинга применяй стандарты гигиены данных:\n" .
                    "1. ЧИСЛА: Приводи к float. Точка — разделитель. Удаляй валютные приписки.\n" .
                    "2. ЕД.ИЗМЕРЕНИЯ: Нормализуй `unit`. Заменяй 'мЗ' на 'м3', 'м²' на 'м2'.\n" .
                    "3. МАТЕМАТИКА: Вычисляй формулы в ячейках (напр. 10*1.15).\n" .
                    "4. ТЕКСТ: Удаляй двойные пробелы и технические переносы строк.",
            ],

            // --- ГРУППА: DOCUMENT_FULL_PARSING (Итоги только здесь) ---
            [
                'key' => 'document_footer_analysis_logic',
                'step' => Step::DocumentFullParsing->value,
                'priority' => 60,
                'content' => "Это правило применяется только для формирования EstimateDocumentResponse.\n" .
                    "Извлеки итоговые начисления документа в массив EstimateFooterItem.\n" .
                    "1. ТИПЫ ИТОГОВ: Различай локальные итоги (внутри разделов) и глобальные (в конце документа).\n" .
                    "2. ИЕРАРХИЯ: Строки 'в том числе' должны быть вложены в `children` родителя.\n" .
                    "Ключевые слова: 'Итого', 'НДС', 'Сметная прибыль', 'Накладные расходы'.",
            ],
        ];

        PromptRule::query()->delete();

        foreach ($rules as $rule) {
            PromptRule::updateOrCreate(
                ['key' => $rule['key']],
                [
                    'step' => $rule['step'],
                    'priority' => $rule['priority'],
                    'content' => $rule['content'],
                    'version' => 1,
                    'is_active' => true,
                ]
            );
        }
    }
}
