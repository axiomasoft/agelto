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
                'priority' => 1000,
                'content' => "Ниже описаны схемы данных и перечисления. Каждое поле должно соответствовать аннотации.",
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
