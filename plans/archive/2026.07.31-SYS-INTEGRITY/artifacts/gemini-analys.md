Дима, я провёл полное, постраничное и построчное слияние всей нашей кодовой базы, архитектуры таблиц и алгоритмов, которые мы фиксировали в ходе проектирования системы **AgElTo / Slovit**.

Этот документ спроектирован как **ультимативное монолитное техническое задание для LLM-агента**. В нём объединены:

1. Полные, детально прописанные миграции для **PostgreSQL** (со всеми индексами, `jsonb`, `pgvector`, внешними ключами и каскадным удалением).
2. Расширенные сущности 223-ФЗ и 44-ФЗ (переторжки, нацрежим, обеспечение, привязка к `GeoSeeder`).
3. Продвинутый алгоритм склейки разорванных/перемешанных страниц смет (скользящее окно, буфер сиротских строк).
4. Механизм динамической трансляции PHP DTO-классов (`spatie/laravel-data`) в системный контекст модели.

Файл сформирован в виде сплошного блока Markdown — ты можешь скопировать его целиком (одним кликом) и передать другой нейросети без риска потери внутренней структуры.

---

```markdown
# АРХИТЕКТУРНО-ТЕХНИЧЕСКОЕ ЗАДАНИЕ ДЛЯ РАЗРАБОТКИ AI-ЯДРА И БАЗЫ ДАННЫХ СИСТЕМЫ «AGELTO / SLOVIT»
## Полная спецификация PostgreSQL-миграций, Laravel DTO и алгоритмов LLM-парсинга извещений и смет (44-ФЗ / 223-ФЗ)

Настоящий документ является неделимым и исчерпывающим руководством для генерации программного кода и настройки AI-агентов. Он описывает полную реляционную структуру таблиц с префиксом `ord_`, сквозной пайплайн семантического разбора HTML-извещений ЕИС и гибридный алгоритм склейки разорванных или перемешанных страниц сметной документации.

---

## ЧАСТЬ 1. Спецификация и миграции базы данных (PostgreSQL)

СУБД по умолчанию: **PostgreSQL**. Выбор обусловлен необходимостью быстрой работы с бинарным JSON (`jsonb`), индексацией глубоко вложенных характеристик товаров, работ и услуг (ТРУ), полнотекстовым поиском и возможностью хранения эмбеддингов через расширение `pgvector` для семантического матчинга.

Финансовые типы данных строго типизированы как `decimal(25, 2)`, а количества — как `decimal(22, 6)` для обеспечения точности в медицинских, химических и строительных спецификациях.

### 1.1 Системные и географические справочники

Данные организации и локации нормализованы. Для связи с географией используется системная таблица `locations` (из существующего `GeoSeeder`), что исключает дублирование адресов в текстовом виде.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Электронные торговые площадки (ЭТП)
        Schema::create('ord_platforms', function (Blueprint $table) {
            $table->id();$table->string('name');
            $table->string('url')->nullable();$table->string('code')->nullable()->unique(); // Код из ЕИС НСИ
            $table->timestamps();
        });

        // 2. Справочник способов закупок (НСИ ЕИС)
        Schema::create('ord_purchase_methods', function (Blueprint $table) {
            $table->id();$table->string('code')->unique()->index(); // Код из ЕИС (например, "EA44", "ZK223")
            $table->string('name'); // Наименование (например, "Электронный аукцион")
            $table->enum('fz_type', ['44', '223'])->index();$table->timestamps();
        });

        // 3. Организации (Заказчики, Организаторы, Уполномоченные органы, Поставщики)
        Schema::create('ord_organizations', function (Blueprint $table) {$table->id();
            $table->string('inn', 12)->unique()->index();$table->string('kpp', 9)->nullable();
            $table->string('ogrn', 15)->nullable();$table->text('full_name');
            $table->text('short_name')->nullable();$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_organizations');
        Schema::dropIfExists('ord_purchase_methods');
        Schema::dropIfExists('ord_platforms');
    }
};

```

### 1.2 Ядро закупки и Процедурный блок (включая Переторжки)

Таблица `ord_order_procedures` учитывает специфику многоэтапных процедур по 223-ФЗ, включая этапы **переторжки (редукциона)** — повторной подачи ценовых предложений с жестко фиксированными дедлайнами.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Шапка закупки
        Schema::create('ord_orders', function (Blueprint $table) {
            $table->id();$table->string('reg_number', 30)->unique()->index(); // Номер извещения в ЕИС
            $table->string('internal_id')->nullable()->index();  // Внутренний ID в ERP заказчика
            $table->enum('fz', ['44', '223'])->index();
            $table->string('version', 10)->nullable();$table->text('title'); // Предмет/Наименование закупки
            $table->string('status_code')->nullable();$table->string('status_name')->nullable(); // Статус ("Подача заявок", "Работа комиссии")
            
            // Внешние связи
            $table->foreignId('method_id')->constrained('ord_purchase_methods');$table->foreignId('customer_id')->constrained('ord_organizations');
            $table->foreignId('organizer_id')->nullable()->constrained('ord_organizations');$table->foreignId('platform_id')->nullable()->constrained('ord_platforms');
            
            // Агрегированная стоимость
            $table->decimal('total_price', 25, 2)->nullable();$table->string('currency', 3)->default('RUB');
            
            $table->string('eis_url')->nullable();$table->jsonb('extra_data')->nullable(); // Сборщик нетипичных полей
            $table->timestamps();
        });

        // 2. Хронология, порядок проведения, этапы и переторжки
        Schema::create('ord_order_procedures', function (Blueprint $table) {
            $table->id();$table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');

            // Таймлайн проведения
            $table->dateTime('published_at')->nullable();$table->dateTime('submission_start')->nullable();
            $table->dateTime('submission_end')->nullable();$table->dateTime('opening_date')->nullable();  
            $table->dateTime('review_date')->nullable();$table->dateTime('scoring_date')->nullable();  

            // Специфика 223-ФЗ: Переторжка (Редукцион)
            $table->boolean('has_reduction')->default(false); 
            $table->dateTime('reduction_start_date')->nullable();$table->text('reduction_procedure')->nullable(); 
            
            // Инструкции и регламенты
            $table->text('submission_procedure')->nullable();$table->dateTime('clarification_deadline')->nullable(); 
            $table->text('clarification_procedure')->nullable();$table->string('time_zone')->nullable(); // Часовой пояс (MSK+0, MSK+2 и т.д.)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_order_procedures');
        Schema::dropIfExists('ord_orders');
    }
};

```

### 1.3 Контактные лица закупки

Таблица позволяет гибко разграничивать контакты представителей Заказчика, Организатора или службы технической поддержки, указанных на соответствующей вкладке ЕИС.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ord_contacts', function (Blueprint $table) {$table->id();
            $table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');$table->string('type', 20)->default('customer'); // customer, organizer, support

            $table->string('full_name')->nullable(); // ФИО контактного лица
            $table->string('email')->nullable();$table->string('phone')->nullable(); // Хранится строкой для поддержки добавочных номеров
            $table->string('fax')->nullable();$table->text('postal_address')->nullable(); 
            $table->text('location')->nullable();$table->text('additional_info')->nullable(); // Время для звонков, регламент связи
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_contacts');
    }
};

```

### 1.4 Лоты и Требования (Национальный режим и Обеспечения)

Требования к участникам строго разделены по категориям. Особое внимание уделяется **Национальному режиму** (Ограничения допуска импортных товаров: ПП РФ №925 для 223-ФЗ и ст. 14 для 44-ФЗ). Параметры обеспечения заявки и исполнения контракта вынесены в отдельные типизированные поля.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Лоты закупки
        Schema::create('ord_lots', function (Blueprint $table) {
            $table->id();$table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');
            $table->integer('lot_number')->default(1);$table->text('subject'); // Предмет договора по конкретному лоту
            
            $table->decimal('initial_price', 25, 2)->nullable(); // НМЦК лота
            $table->string('currency', 3)->default('RUB');
            
            // Финансовое обеспечение заявки
            $table->decimal('bid_security_amount', 20, 2)->nullable();$table->boolean('bid_security_guarantee_allowed')->default(true); // Разрешена ли независимая гарантия
            $table->text('bid_security_terms')->nullable();
            
            // Финансовое обеспечение исполнения контракта
            $table->decimal('contract_security_amount', 20, 2)->nullable();$table->text('contract_security_terms')->nullable();

            // Преференции и Гео-привязка
            $table->boolean('for_msp')->default(false); // Ограничение только для СМП/МСП
            
            // Внешний ключ на справочник локаций системы (GeoSeeder)
            $table->foreignId('location_id')->nullable()->constrained('locations');$table->text('delivery_place')->nullable(); // Текстовый сырой адрес поставки
            $table->text('delivery_schedule')->nullable(); // Сроки и календарный план поставки ТРУ
            $table->timestamps();
        });

        // 2. Требования, ограничения и преференции лота
        Schema::create('ord_lot_requirements', function (Blueprint $table) {$table->id();
            $table->foreignId('lot_id')->constrained('ord_lots')->onDelete('cascade');$table->string('type_code')->nullable(); // Код требования из ЕИС
            $table->string('category', 30)->default('general'); // general, national_mode, preference$table->text('name'); // Наименование ограничения (например, "Запрет по ПП №925")
            $table->text('content')->nullable();$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_lot_requirements');
        Schema::dropIfExists('ord_lots');
    }
};

```

### 1.5 Позиции ТРУ (Спецификация и Страна происхождения)

Для анализа импортозамещения и ограничений нацрежима на уровне каждой строки спецификации выделен альфа-3 код **Страны происхождения**. Характеристики ТРУ записываются в `jsonb` с построением `GIN`-индекса для мгновенного поиска по сложным параметрам смет и спецификаций.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ord_purchase_objects', function (Blueprint $table) {$table->id();
            $table->foreignId('lot_id')->constrained('ord_lots')->onDelete('cascade');$table->string('okpd2_code', 25)->index(); 
            $table->text('okpd2_name')->nullable();$table->text('name');                      // Текстовое наименование позиции ТРУ
            
            $table->decimal('quantity', 22, 6)->nullable();$table->string('unit_name')->nullable();        // шт, кг, условная единица
            $table->string('unit_code')->nullable();        // Код ОКЕИ
            
            $table->decimal('price_per_unit', 25, 2)->nullable();$table->decimal('total_price', 25, 2)->nullable();
            
            // Код страны происхождения (Матчится со справочником стран GeoSeeder)
            $table->string('country_of_origin_code', 3)->nullable();$table->jsonb('characteristics')->nullable(); // Произвольные физико-технические свойства
            $table->timestamps();
        });

        // Построение GIN индекса PostgreSQL для ускорения фильтрации внутри JSONB
        DB::statement('CREATE INDEX ord_purchase_objects_characteristics_gin ON ord_purchase_objects USING gin (characteristics);');
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_purchase_objects');
    }
};

```

### 1.6 Результаты процедур: Протоколы, Договоры и Файлы

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Протоколы комиссий (Вкладка "Протоколы")
        Schema::create('ord_order_protocols', function (Blueprint $table) {
            $table->id();$table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');
            
            $table->string('type_code')->nullable();$table->string('type_name')->nullable(); // "Протокол подведения итогов"
            $table->string('number')->nullable();    // Номер протокола
            $table->dateTime('published_at')->nullable();$table->dateTime('signed_at')->nullable();
            $table->string('url')->nullable();$table->timestamps();
        });

        // 2. Сведения о заключенных договорах (Связанный Реестр договоров ЕИС)
        Schema::create('ord_order_contracts', function (Blueprint $table) {
            $table->id();$table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');
            $table->foreignId('lot_id')->nullable()->constrained('ord_lots');$table->string('reg_number', 50)->unique()->nullable(); // Номер из реестра договоров
            $table->string('status')->nullable(); // Исполнение, Исполнено, Прекращено
            
            // Данные победителя-поставщика
            $table->string('supplier_inn', 12)->index();
            $table->text('supplier_name')->nullable();$table->decimal('price', 25, 2)->nullable(); // Финальная цена заключения
            $table->string('currency', 3)->default('RUB');$table->date('signed_at')->nullable();
            $table->date('execution_start')->nullable();$table->date('execution_end')->nullable();
            $table->string('eis_url')->nullable();$table->timestamps();
        });

        // 3. Документы закупки (Файлы извещения)
        Schema::create('ord_documents', function (Blueprint $table) {
            $table->id();$table->foreignId('order_id')->constrained('ord_orders')->onDelete('cascade');
            $table->string('file_name');$table->string('url');
            $table->dateTime('published_at')->nullable();$table->string('md5', 32)->nullable(); // Для отслеживания изменений версий файла
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ord_documents');
        Schema::dropIfExists('ord_order_contracts');
        Schema::dropIfExists('ord_order_protocols');
    }
};

```

---

## ЧАСТЬ 2. Пайплайн семантического парсинга HTML-страниц извещений ЕИС

Пайплайн избавляет систему от необходимости поддержки хрупких CSS/XPath-селекторов.

1. **Экспорт контента:** Headless-браузер (Playwright) инициализирует сессию, переходит на целевую закупку ЕИС, последовательно эмулирует клики по всем ключевым вкладкам извещения («Общая информация», «Порядок размещения», «Лоты», «Контакты») и считывает сплошной текстовый слой через `document.body.innerText`.
2. **Очистка и нормализация:** Текст на бэкенде очищается от дублирующихся пробелов, элементов интерфейса навигации и скриптов. Он форматируется в компактный Markdown-текст с сохранением табличной структуры.
3. **Семантический маппинг:** Нормализованный текст отправляется в Gemini API совместно с системной инструкцией, содержащей строгую DTO-схему (см. Часть 4). Модель сопоставляет текстовые сущности по их языковому смыслу, гарантируя устойчивость к любым редизайнам интерфейса ЕИС.

---

## ЧАСТЬ 3. Гибридный алгоритм устойчивого парсинга многостраничных смет

Главная аномалия при обработке смет (PDF, сканы, изображения) — **разрывы табличных строк на стыках страниц**, а также риск **нарушения порядка (перемешивания) страниц** при некачественном сканировании. Позиция (код расценки, наименование работы) может остаться внизу страницы N, а её объемы, цены и коэффициенты переехать на страницу N+1.

Для полной компенсации этих аномалий разработан и внедрен алгоритм **"Context Window Stitching with Orphan Lines Buffer"**.

### 3.1 Пошаговый алгоритм конвейера смет

1. **Внедрение маркеров границ (Page Labeling):** Перед извлечением текста или передачей сметы на OCR, бэкенд оборачивает контент каждой физической страницы в однозначные маркерные теги: `[PAGE_START: X]` и `[PAGE_END: X]`. Это даёт модели пространственное понимание документа.
2. **Метод скользящего окна (Sliding Window):** Документ нарезается на текстовые чанки по **3 страницы** с перекрытием (сдвигом) в **1 страницу**.
* Чанк 1: Страницы `1, 2, 3`.
* Чанк 2: Страницы `3, 4, 5` (Страница 3 дублируется в контексте).
* За счет дублирования пограничной страницы в следующем запросе, ИИ видит полную картину разрыва строки в едином контекстном окне.


3. **Буферизация неполных строк (Orphan & Incomplete Lines):** Модель инструктируется жестким правилом:
* **Incomplete Item (Незавершенная строка):** Строка внизу страницы, имеющая код расценки (например, *ФЕР09-03-002-01*), но прерванная концом страницы до указания объемов или финальных цен. Модель обязана поместить её в буфер `incomplete_items` с флагом `"reason": "split_by_page_end"`.
* **Orphan Line (Сиротская строка):** Изолированные числовые показатели вверху страницы без кода расценки (продолжение разорванной строки). Помещаются в буфер с флагом `"reason": "orphan_numbers_at_page_start"`.


4. **Склейка разорванных и перемешанных страниц на бэкенде (`EstimateStitcherService`):**
* Laravel-сервис принимает массив JSON от всех чанков. Он находит незавершенную строку с кодом из Чанка N и матчит её со следующей по порядку сиротской строкой из Чанка N+1, объединяя текстовые свойства и числовые массивы в один DTO-объект (выставляется флаг `is_merged_from_split = true`).
* Если страницы сметы были физически перемешаны внутри PDF, сервис выполняет группировку позиций по иерархии кодов расценок и названиям разделов, пересобирая исходный хронологический порядок сметы перед отправкой в `ord_purchase_objects`.


5. **Изолированный парсинг концевиков:** Итоговые начисления (Накладные расходы, Сметная прибыль, Коэффициенты Минстроя, НДС) парсятся обособленным запросом при фиксации маркеров завершения документа (*«Всего по смете»*, *«В том числе НДС»*), после чего обновляют общие финансовые данные лота.

---

## ЧАСТЬ 4. Динамическая интеграция PHP DTO-классов в системный промпт ИИ

Для полного исключения человеческого фактора и рассинхронизации полей базы данных, миграций и ответов ИИ, структура целевого JSON генерируется динамически на основе PHP-классов DTO (`spatie/laravel-data`) через Reflection API и передается в `systemInstruction` Gemini API.

### 4.1 Пример целевого класса DTO закупки в Laravel (`OrderImportData`)

```php
namespace App\Data\Procurement;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class OrderImportData extends Data
{
    public function __construct(
        public string $reg_number,
        public string $fz,
        public string $title,
        public ?float $total_price,
        public string $currency,
        public string $status_name,
        public OrderProcedureData $procedure,
        /** @var array<int, ContactData> */
        public array $contacts,
        /** @var array<int, LotData> */
        public array $lots,
    ) {}
}

```

### 4.2 Генератор контекста структуры в Prompt Factory (PHP)

Перед каждым обращением к LLM, бэкенд считывает исходный код DTO-классов, превращая его в жесткое руководство для ИИ.

```php
namespace App\Services\AI;

use App\Data\Procurement\OrderImportData;
use ReflectionClass;

class PromptContextGenerator
{
    /**
     * Формирует строгий контекст структуры данных для системного промпта ИИ
     */
    public function generateStructureContext(): string
    {
        $dtoReflection = new ReflectionClass(OrderImportData::class);
        
        // Считываем исходный код DTO-класса. ИИ безошибочно парсит 
        // синтаксис PHP 8+ и считывает типы данных (float, string, array, nullable)
        $dtoCode = file_get_contents($dtoReflection->getFileName());

        return <<<TEXT
=== СТРОГИЙ ТЕХНИЧЕСКИЙ КОНТРАКТ ОТВЕТА (PHP DTO SCHEMA) ===
Вы обязаны сформировать результирующий JSON строго в соответствии со свойствами, именами и типами данных из этого PHP-класса. 
Любое несовпадение ключей или неверный тип данных приведет к сбою валидации на бэкенде.

{$dtoCode}
============================================================
TEXT;
    }
}

```

### 4.3 Сборка и отправка Payload в Gemini API

```php
$structureContext = (new PromptContextGenerator())->generateStructureContext();

$systemInstruction = <<<TEXT
Ты — специализированное ИИ-ядро парсинга ЕИС Госзакупок. Твоя задача — трансформировать неструктурированный текст извещения в валидный JSON.
Ответ должен представлять собой чистую JSON-строку, без использования markdown-тегов (ЗАПРЕЩЕНО писать ```json ... ```).

{$structureContext}

ПРАВИЛА ОЧИСТКИ ДАННЫХ ДЛЯ ИИ:
1. Финансовые поля и количества строго приводи к типу float/int (удаляй пробелы, разделители, текстовые символы валют "руб.", "usd").
2. Если поле в PHP-классе имеет знак '?' (nullable) и данные отсутствуют в источнике — возвращай null.
3. Лоты внутри массива 'lots' должны строго агрегировать свои позиции 'purchase_objects'.
TEXT;

// Инициализация запроса к LLM
$aiResponse = $geminiClient->withSystemInstruction($systemInstruction)
                            ->generateContent($cleanedMarkdownText);

```

### 4.4 Жесткая JSON-схема детального анализа сметы (`EstimateFetchResponse`)

Контракт ответа ИИ для сметной документации, обязывающий модель заполнять буфер разорванных страниц:

```json
{
  "$schema": "[http://json-schema.org/draft-07/schema#](http://json-schema.org/draft-07/schema#)",
  "type": "object",
  "properties": {
    "estimate_metadata": {
      "type": "object",
      "properties": {
        "number": { "type": ["string", "null"] },
        "object_name": { "type": "string" },
        "total_base_price": { "type": ["number", "null"] },
        "total_current_price": { "type": ["number", "null"] },
        "pages_processed": {
          "type": "array",
          "items": { "type": "integer" },
          "description": "Список страниц, вошедших в данный чанк. Пример: [3, 4, 5]"
        }
      },
      "required": ["object_name", "total_base_price", "total_current_price", "pages_processed"]
    },
    "sections": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "name": { "type": "string", "description": "Наименование раздела сметы" },
          "ordinal_number": { "type": "integer" },
          "items": {
            "type": "array",
            "items": {
              "type": "object",
              "properties": {
                "type": { "type": "string", "enum": ["work", "material", "equipment"] },
                "code": { "type": "string", "description": "Обоснование расценки по ФЕР/ТЕР" },
                "name": { "type": "string", "description": "Наименование ТРУ" },
                "unit": { "type": "string", "description": "Единица измерения по ОКЕИ" },
                "quantity": { "type": ["number", "null"] },
                "price_per_unit_current": { "type": ["number", "null"] },
                "total_price_current": { "type": ["number", "null"] },
                "country_of_origin": { "type": ["string", "null"], "description": "Страна происхождения, если указана" },
                "start_page": { "type": "integer" },
                "end_page": { "type": "integer" },
                "coefficients": {
                  "type": "array",
                  "items": {
                    "type": "object",
                    "properties": {
                      "code": { "type": "string", "example": "К=1.15" },
                      "value": { "type": "number" },
                      "description": { "type": "string" }
                    },
                    "required": ["code", "value"]
                  }
                }
              },
              "required": ["type", "code", "name", "unit", "quantity", "total_price_current", "start_page", "end_page"]
            }
          }
        },
        "required": ["name", "ordinal_number", "items"]
      }
    },
    "incomplete_items": {
      "type": "array",
      "description": "Буфер для аккумуляции строк, разорванных границами страниц",
      "items": {
        "type": "object",
        "properties": {
          "reason": { "type": "string", "enum": ["split_by_page_end", "orphan_numbers_at_page_start"] },
          "raw_text_buffer": { "type": "string", "description": "Сырой текст поврежденной строки" },
          "last_known_code": { "type": ["string", "null"], "description": "Код родительской расценки" },
          "page_number": { "type": "integer" }
        },
        "required": ["reason", "raw_text_buffer", "page_number"]
      }
    },
    "summary_totals": {
      "type": "object",
      "properties": {
        "overhead_costs": { "type": ["number", "null"] },
        "estimated_profit": { "type": ["number", "null"] },
        "vat": { "type": ["number", "null"] },
        "final_sum": { "type": "number", "description": "Итоговая сумма концевика сметы" }
      },
      "required": ["final_sum"]
    }
  },
  "required": ["estimate_metadata", "sections", "incomplete_items", "summary_totals"]
}

```

============================================================

```
***

Документ полностью готов к отправке твоим AI-агентам для генерации кода и парсеров. Напиши, если потребуется расширить логику валидации ответов на стороне Laravel!

```