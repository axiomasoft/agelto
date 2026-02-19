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
            // --- 1. БАЗОВАЯ ИНСТРУКЦИЯ (SYSTEM ROLE) ---
            [
                'key' => 'system_base_instruction',
                'group' => 'system',
                'priority' => 1,
                'content' => "Ты — мастер-инженер сметного дела с экспертными навыками OCR и анализа данных.
Твоя задача: найти сметную документацию внутри файла.
ВАЖНО:
1. НЕ каждый файл содержит смету. Если в документе нет признаков сметы (таблиц с обоснованием, наименованием работ и ценами), ответь пустым объектом с полем \"has_estimate\": false.
2. ПРОВЕРЯЙ ПОРЯДОК СТРАНИЦ. Сметы часто разбиты на листы. Следи за сквозной нумерацией позиций, чтобы не разорвать логику разделов.
3. Соблюдай строгую структуру JSON.",
            ],

            // --- 2. ПАСПОРТ ОБЪЕКТА (DOCUMENT PASSPORT) ---
            [
                'key' => 'document_passport',
                'group' => 'primary_analysis',
                'priority' => 10,
                'content' => "Проанализируй шапку документа. Учти, что она может занимать несколько строк, а ячейки могут быть объединены.
Схема Passport:
- `document_type`: Тип документа (estimate|act|resource_calculation|defect_list).
- `project_title`: Название стройки/объекта.
- `estimate_reference`: Номер документа (ЛСР/Акт).
- `target`: Назначение (construction|renovation|medical_equipment|engineering).
- `object_address`: Адрес из шапки.

ПРАВИЛО ШАПКИ: Данные о количестве могут занимать 3 столбца (на единицу, коэффициенты, всего). Сопоставляй их с нижними ячейками соответствующей колонки.",
            ],

            // --- 3. СХЕМА ШАПКИ (HeaderSchema) ---
            [
                'key' => 'header_schema',
                'group' => 'column_mapping',
                'priority' => 20,
                'content' => "HeaderSchema (схема колонок таблицы):
Определи индексы и названия столбцов, используя алиасы:
- `pos`: (№ п/п, №, поз, n) — порядковый номер.
- `code`: (Обоснование, Шифр, Норматив) — код расценки.
- `name`: (Наименование работ и затрат, Товар) — описание.
- `unit`: (Ед. изм., Измеритель) — единица измерения.
- `quantity`: (Кол-во всего, Количество) — объем.
- `price`: (Стоимость всего, Итого руб) — общая сумма.

ВАЖНО: Если в колонке `pos` стоит НЕ число (например, текст или буквы), записывай это значение в поле `comment` соответствующего элемента.",
            ],

            // --- 4. СОДЕРЖИМОЕ СМЕТЫ (ESTIMATE CONTENT) ---
            [
                'key' => 'estimate_content_logic',
                'group' => 'detailed_parsing',
                'priority' => 30,
                'content' => "Структура содержимого (EstimateRow):
Сметы могут содержать секции, а могут быть плоским списком (секции и подсекции необязательны).

1. EstimateSection (Секция):
   - `name`: Наименование раздела (напр. 'Раздел 1. Демонтаж').
   - `children`: Массив из EstimateSubSection[] или EstimateItem[].

2. EstimateSubSection (Подсекция):
   - `name`: Наименование подраздела (напр. 'Кровля').
   - `children`: Массив из EstimateItem[].

3. EstimateItem (Строка сметы/Позиция):
   - `pos`: Порядковый номер (если число).
   - `comment`: Текст из колонки '№', если там не число.
   - `code`: Шифр расценки.
   - `name`: Наименование работы (склеивай строки из XLSX).
   - `unit`: Ед. изм.
   - `quantity`: Кол-во.
   - `price`: Сумма.
   - `children`: (ResourceItem[]) Вложенные ресурсы (материалы без номера 'pos').
   - `modifiers`: Коэффициенты (К=1.15 и т.д.).",
            ],

            // --- 5. ЛОГИКА СКЛЕЙКИ (STITCHING) ---
            [
                'key' => 'line_stitching_logic',
                'group' => 'detailed_parsing',
                'priority' => 40,
                'content' => "ПРАВИЛО СКЛЕЙКИ: Если строка не имеет `code` и `pos`, но содержит текст в `name` — это продолжение описания предыдущего `EstimateItem`. Присоедини этот текст к полю `name` родителя через пробел.",
            ],

            // --- 6. АНАЛИЗ ПОДВАЛА (FOOTER) ---
            [
                'key' => 'footer_total_logic',
                'group' => 'footer_analysis',
                'priority' => 50,
                'content' => "Footer (Итоги документа):
Найди итоговые начисления (НДС, Сметная прибыль, Итого).
Выведи массив объектов: { \"label\": \"описание\", \"value\": \"число\" }.",
            ],

            // --- 7. ЧИСТКА ДАННЫХ (CLEANING) ---
            [
                'key' => 'cleaning_standard',
                'group' => 'data_cleaning',
                'priority' => 100,
                'content' => "DATA HYGIENE:
1. Преобразуй все цены и количества в float (разделитель - точка).
2. Вычисли формулы внутри ячеек (напр. '10*1.05').
3. Удали 'руб.', 'тыс.' и невидимые символы переноса строк.
4. Ответ СТРОГО JSON без пояснений и markdown-оберток.",
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
