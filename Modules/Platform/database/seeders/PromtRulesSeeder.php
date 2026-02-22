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
            // --- ГРУППА: GLOBAL SCHEMAS (Общая вводная) ---
            [
                'key' => 'global_json_schemas',
                'step' => Step::Global->value,
                'priority' => 1,
                'content' => "Ниже описаны схемы данных и перечисления. Каждое поле должно соответствовать аннотации.",
            ],

            // --- ГРУППА: GLOBAL PRIMARY (Схемы для первичного анализа) ---
            [
                'key' => 'global_primary_json_schemas',
                'step' => Step::GlobalPrimary->value,
                'priority' => 1,
                'content' => "0. DocumentType (Тип документа, поле data.document_type в PrimaryAnalysisResponse):\n" .
                    "- \"estimate\": Смета.\n" .
                    "- \"tech_spec\": Техническое задание (ТЗ).\n" .
                    "- \"unknown\": Неизвестный тип.\n\n" .
                    "1. Формат ответа PrimaryAnalysisResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": {\n" .
                    "    \"document_type\": DocumentType,\n" .
                    "    \"description\": string | null (Краткое резюме файла: что это, за какой период, какой объем),\n" .
                    "    \"tags\": string[] (Массив меток: Город, ОС, Шифр, Вид работ, ФИО)\n" .
                    "  }\n" .
                    "}",
            ],

            // --- ГРУППА: GLOBAL ESTIMATE (Схемы для анализа смет) ---
            [
                'key' => 'global_estimate_json_schemas',
                'step' => Step::GlobalEstimate->value,
                'priority' => 1,
                'content' => "0. EstimateType (Тип документа):\n" .
                    "- \"estimate\": Смета (ЛСР). Определять по заголовкам 'Локальный сметный расчет', 'Локальная смета'.\n" .
                    "- \"act\": Акт (КС-2). Определять по заголовкам 'Акт о приемке выполненных работ', 'Форма № КС-2'.\n" .
                    "- \"resource_calculation\": Ресурсная ведомость. Текст: 'Ведомость ресурсов', 'Расчет ресурсов'.\n" .
                    "- \"defect_list\": Дефектная ведомость. Текст: 'Дефектная ведомость', 'Опись работ'.\n\n" .
                    "1. EstimateTarget (Направление/категория сметы):\n" .
                    "- \"construction\": Строительство.\n" .
                    "- \"renovation\": Ремонт.\n" .
                    "- \"medical_equipment\": Медоборудование.\n" .
                    "- \"engineering\": Инженерия.\n\n" .
                    "2. EstimateResourceType (Классификация ресурса):\n" .
                    "- \"Material\": Материалы. Обоснования ФССЦ, прайс-листы, маркеры 'МАТ', 'мат.ресурс'.\n" .
                    "- \"Machine\": Машины. Обоснования ФСЭМ, маркеры 'ЭМ', 'маш.час', 'Машины и механизмы'.\n" .
                    "- \"Labor\": Труд. Маркеры 'ЗП', 'ОТ', 'ЗПМ', 'Трудозатраты', 'чел.час'.\n" .
                    "- \"Overhead\": Накладные/Прибыль. Маркеры 'НР', 'СП', 'Накладные расходы', 'Прибыль'.\n\n" .
                    "3. EstimateHeaderColumn (Описание колонки):\n" .
                    "{\n" .
                    "  \"index\": integer (Порядковый номер ячейки в строке, начиная с 0),\n" .
                    "  \"name\": string (Текст в данной ячейке),\n" .
                    "  \"full_name\": string (Текст с учетом родительской группы ячеек).\n" .
                    "}\n\n" .
                    "4. EstimateHeader (Карта колонок):\n" .
                    "{\n" .
                    "  \"pos\": EstimateHeaderColumn | null (Алиасы: № п/п, №, поз),\n" .
                    "  \"code\": EstimateHeaderColumn | null (Алиасы: Шифр, Обоснование, Код),\n" .
                    "  \"name\": EstimateHeaderColumn | null (Алиасы: Наименование работ, Описание),\n" .
                    "  \"unit\": EstimateHeaderColumn | null (Алиасы: Ед. изм., Измеритель),\n" .
                    "  \"quantity\": EstimateHeaderColumn | null (Алиасы: Количество, Объем),\n" .
                    "  \"price\": EstimateHeaderColumn | null (Алиасы: Стоимость всего, Итого руб)\n" .
                    "}\n\n" .
                    "5. EstimateItem (Позиция):\n" .
                    "{\n" .
                    "  \"name\": string (Соответствует 'name' в EstimateHeader),\n" .
                    "  \"pos\": integer | null,\n" .
                    "  \"code\": string | null,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"children\": EstimateItemResource[],\n" .
                    "  \"modifiers\": EstimateModifierItem[]\n" .
                    "}\n\n" .
                    "6. EstimateModifierItem (Коэффициент):\n" .
                    "{\n" .
                    "  \"name\": string (Код, напр. 'К=1.15'),\n" .
                    "  \"description\": string (Обоснование),\n" .
                    "  \"value\": float (Число)\n" .
                    "}\n\n" .
                    "7. EstimateSection (Раздел):\n" .
                    "{\n" .
                    "  \"type\": \"section\",\n" .
                    "  \"name\": string,\n" .
                    "  \"children\": (EstimateSubSection | EstimateItem)[]\n" .
                    "}\n\n" .
                    "8. EstimateSubSection (Подраздел):\n" .
                    "{\n" .
                    "  \"type\": \"subsection\",\n" .
                    "  \"name\": string,\n" .
                    "  \"children\": EstimateItem[]\n" .
                    "}\n\n" .
                    "9. EstimateItemResource (Ресурс):\n" .
                    "{\n" .
                    "  \"name\": string (Наименование),\n" .
                    "  \"resource_type\": EstimateResourceType,\n" .
                    "  \"code\": string | null,\n" .
                    "  \"unit\": string | null,\n" .
                    "  \"quantity\": float | null,\n" .
                    "  \"price\": float | null,\n" .
                    "  \"comment\": string | null (Текст из колонки 'pos')\n" .
                    "}\n\n" .
                    "10. EstimateFooterItem (Итог):\n" .
                    "{\n" .
                    "  \"label\": string,\n" .
                    "  \"value\": float,\n" .
                    "  \"children\": EstimateFooterItem[]\n" .
                    "}\n\n" .
                    "11. EstimatePassport (Паспорт сметы):\n" .
                    "{\n" .
                    "  \"estimate_id\": string,\n" .
                    "  \"type\": EstimateType | null,\n" .
                    "  \"reference\": string | null,\n" .
                    "  \"target\": EstimateTarget | null,\n" .
                    "  \"address\": string | null\n" .
                    "}\n\n" .
                    "12. Форматы ответов (Responses):\n" .
                    "- EstimatePreviewResponse:\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": EstimatePreview[]\n" .
                    "}\n" .
                    "EstimatePreview: { \"passport\": EstimatePassport, \"items_count\": integer, \"items_count_total\": integer, \"page_start\": integer, \"page_end\": integer }\n\n" .
                    "- EstimateFetchResponse (дерево позиций):\n" .
                    "{\n" .
                    "  \"estimate_id\": string,\n" .
                    "  \"data\": (EstimateSection | EstimateItem)[]\n" .
                    "}\n\n" .
                    "- EstimateFetchInfoResponse (шапка и итоги):\n" .
                    "{\n" .
                    "  \"success\": boolean,\n" .
                    "  \"message\": string | null,\n" .
                    "  \"data\": EstimateFetchInfo\n" .
                    "}\n" .
                    "EstimateFetchInfo: { \"estimate_id\": string, \"generator\": string | null, \"header\": EstimateHeader, \"footer\": EstimateFooterItem[] }\n",
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
                'key' => 'primary_analysis_parse_logic',
                'step' => Step::PrimaryAnalysis->value,
                'priority' => 20,
                'content' => "Твоя задача — провести экспресс-аудит документа по ПЕРВЫМ ДВУМ СТРАНИЦАМ.\n\n" .
                    "1. Ответ должен соответствовать структуре PrimaryAnalysisResponse\n" .
                    "2. ЛОКАЦИЯ: Если маркеры сметы (ЛСР, КС-2, Расчет ресурсов) не обнаружены на первых 2 страницах, считай, что документ не содержит сметных данных (document_type: 'unknown').\n" .
                    "3. КЛАССИФИКАЦИЯ: \n" .
                    "   - Если это смета -> document_type: \"estimate\".\n" .
                    "   - Если это ТЗ (описание работ, требования) -> document_type: \"tech_spec\".\n" .
                    "   - Иначе -> document_type: \"unknown\".\n" .
                    "4. ХАРАКТЕРИСТИКА: В поле `description` кратко опиши суть (напр. 'Проект договора на ПИР' или 'Смета на электромонтаж').\n" .
                    "5. ТЕГИ: Выдели ключевые сущности (Объект, Город, Сумма, Заказчик).",
            ],

            // --- ГРУППА: ESTIMATE_PREVIEW ---
            [
                'key' => 'estimate_preview_parse_logic',
                'step' => Step::EstimatePreview->value,
                'priority' => 30,
                'content' => "Для формирования EstimatePreviewResponse (массив data: EstimatePreview[]):\n" .
                    "1. Для каждой сметы в файле заполни EstimatePreview: passport (EstimatePassport), items_count, items_count_total, page_start, page_end.\n" .
                    "2. Просканируй документ на наличие заголовков разделов, определи количество независимых сметных объектов.",
            ],

            // --- ГРУППА: DETAILED_PARSING ---
            [
                'key' => 'estimate_fetch_items_tree_hierarchy_logic',
                'step' => Step::EstimateFetchItems->value,
                'priority' => 40,
                'content' => "Сформируй дерево данных для EstimateFetchResponse (поле `data`: массив EstimateSection | EstimateItem).\n" .
                    "1. ИЕРАРХИЯ: Используй EstimateSection (type: 'section', name, children) и EstimateSubSection (type: 'subsection', name, children) для группировки.\n" .
                    "2. ПРАВИЛО No-Number: Если колонка `pos` пуста, но есть `code` и `name` — это EstimateItemResource.\n" .
                    "   Вкладывай такие строки в `children` последнего найденного EstimateItem.\n" .
                    "3. СКЛЕЙКА: Если наименование или ШИФР (`code`) разбиты на строки, объединяй через пробел.\n" .
                    "   Не пытайся сокращать или переформатировать шифр (напр. ТЕР-01...).\n" .
                    "4. КОДЫ: Убедись, что извлеченные `code`, `name` и `unit` соответствуют EstimateHeader.",
            ],
            [
                'key' => 'estimate_fetch_items_resource_item_processing',
                'step' => Step::EstimateFetchItems->value,
                'priority' => 41,
                'content' => "При обработке EstimateItemResource:\n" .
                    "- ПРАВИЛО COMMENT: Если в колонке `pos` текст/буквы (напр. 'прим.'), пиши это в `comment` ресурса.\n" .
                    "- Определи тип ресурса в поле `resource_type` (Material|Machine|Labor|Overhead).\n" .
                    "- Выделяй коэффициенты в структуру EstimateModifierItem (name, description, value).",
            ],
            [
                'key' => 'estimate_fetch_items_hygiene_and_cleaning',
                'step' => Step::EstimateFetchItems->value,
                'priority' => 45,
                'content' => "В процессе парсинга применяй стандарты гигиены данных:\n" .
                    "1. ЧИСЛА: Приводи к float. Точка — разделитель. Удаляй валютные приписки.\n" .
                    "2. ЕД.ИЗМЕРЕНИЯ: Нормализуй `unit`. Заменяй 'мЗ' на 'м3', 'м²' на 'м2'.\n" .
                    "3. МАТЕМАТИКА: Вычисляй формулы в ячейках (напр. 10*1.15).\n" .
                    "4. ТЕКСТ: Удаляй двойные пробелы и технические переносы строк.",
            ],

            // --- ГРУППА: FOOTER_PARSING (Итоги в отдельный ответ) ---
            [
                'key' => 'estimate_fetch_info_logic',
                'step' => Step::EstimateFetchInfo->value,
                'priority' => 60,
                'content' => "Это правило для формирования EstimateFetchInfoResponse (data: EstimateFetchInfo).\n" .
                    "Заполни estimate_id, generator (если есть), header (EstimateHeader — карта колонок), footer (массив EstimateFooterItem).\n" .
                    "1. HEADER: Определи соответствие колонок (pos, code, name, unit, quantity, price) по алиасам в таблице.\n" .
                    "2. FOOTER: Извлеки итоговые начисления в массив EstimateFooterItem (label, value, children).\n" .
                    "   Различай локальные итоги (внутри разделов) и глобальные (в конце документа). Строки 'в том числе' — в `children` родителя.\n" .
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
