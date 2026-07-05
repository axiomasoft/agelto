# Архитектура надёжной системы парсинга ЕИС (zakupki.gov.ru)
## Версия 2.0 — Результирующий план с анализом проекта agelto

> **Статус документа:** Результирующий технический план. Основан на анализе реального кода `agelto/agelto` (модуль `Eis/Crawling`) и исследовании best-practices построения отказоустойчивых краулеров государственных закупок.

---

## 1. Анализ текущего состояния проекта

### 1.1 Архитектурные достоинства (сохранить)

Код `EisCrawlingService.php` демонстрирует несколько по-настоящему зрелых решений, которые отсутствуют у большинства open-source парсеров ЕИС:

- **Template Method pattern** — абстрактный `AbstractCrawlingService` с контрактом `getNodes()` / `crawlingItem()` / `getPageMeta()` / `parseAttachments()`. Добавление нового источника = новый класс, без изменения ядра
- **Двухрежимный обход** — оперативный (свежие данные, полный проход с нуля) и архивный (по дням с checkpoint-возобновлением). Логика `rememberDay()` / `resumeDay()` корректна
- **Рекурсивное ценовое дробление** `priceSplitHook()` — нетривиальное решение проблемы лимита 1000 записей на поиск ЕИС; бинарный поиск по диапазону цен с защитой от бесконечной рекурсии (`$diff < 10_000`)
- **Rotation proxy** встроена в `BrowserOptions` с `rotate_proxy_on_fail: true` и `retries: 3`
- **`CrawlerRunManager` с heartbeat** — серьёзная реализация; большинство открытых парсеров ЕИС не имеют ничего подобного

### 1.2 Критические проблемы (устранить в первую очередь)

#### Проблема A — синхронные вложения (КРИТИЧНО, приоритет #1)

```php
// EisCrawlingService.php → parseAttachments()
usleep(3_000_000); // 3 секунды на КАЖДУЮ закупку, синхронно
```

50 записей на странице × 3 сек = **минимум 150 секунд только на паузы** без учёта HTTP-запросов. Вложения скачиваются синхронно внутри основного цикла страницы. Это главная причина нестабильности — длинное синхронное выполнение истощает воркеры и убивает соединение.

**Решение:** вынести `parseAttachments()` в отдельный `ParseProcurementAttachmentsJob`, диспатчить через очередь.

#### Проблема B — chrome-php без fingerprint-спуфинга

`chrome-php/chrome` — это DevTools Protocol (CDP) клиент. Он запускает headless Chromium, но не подменяет отпечатки браузера. Современные антибот-системы (Cloudflare, PerimeterX) детектируют `HeadlessChrome` по нескольким векторам:
- `navigator.webdriver = true`
- пустой `navigator.plugins`
- нестандартные размеры `window.screen`
- аномальное поведение мыши

Playwright решает эти проблемы нативно через `browserContext` с реалистичными параметрами.

#### Проблема C — избыточное использование headless браузера

Страницы листинга ЕИС (`/epz/order/extendedsearch/results.html`) **рендерятся на сервере (SSR)**. Данные в HTML уже присутствуют без выполнения JS. Запуск полного браузера для них — излишество. Проверено: кнопка "Выгрузить результаты поиска" и CSV-экспорт работают через прямой HTTP-запрос.

---

## 2. Три метода краулинга — принципиальная схема

Система должна быть спроектирована так, чтобы **метод краулинга был переключаемым параметром конфигурации** на уровне модели `Crawler`, не требующим изменения кода.

```
┌─────────────────────────────────────────────────────────────────┐
│                     CrawlerOrchestrator                         │
│   (Laravel + Horizon, PHP — остаётся основным языком/хостом)   │
└──────────────────────┬──────────────────────────────────────────┘
                       │ выбирает метод по Crawler::source_method
          ┌────────────┼────────────────┐
          ▼            ▼                ▼
   [Метод 1]      [Метод 2]        [Метод 3]
   HTML-парсинг   CSV-экспорт       SOAP/API
   (текущий)      (новый)           (будущий)
```

### Метод 1 — HTML парсинг через Playwright PHP (обновлённый текущий)

**Использовать когда:** листинг с JS-зависимыми элементами, детальные карточки закупок, документы.

**Стек:**
- `playwright-php/playwright` (Packagist, PHP 8.2+, Node 20+) — заменяет `chrome-php/chrome`
- Playwright запускает Chromium/Firefox/WebKit, NodeJS-сервер стартует автоматически, никакого отдельного процесса
- Работает в той же Laravel-экосистеме, никакого Python

```php
// composer require playwright-php/playwright
use Playwright\Playwright;

$playwright = Playwright::create();
$browser = $playwright->chromium()->launch([
    'headless' => true,
    'args' => ['--no-sandbox', '--disable-setuid-sandbox'],
]);

$context = $browser->newContext([
    'userAgent' => '...реалистичный UA...',
    'viewport' => ['width' => 1920, 'height' => 1080],
    'locale' => 'ru-RU',
    'extraHTTPHeaders' => ['Accept-Language' => 'ru-RU,ru;q=0.9'],
]);

$page = $context->newPage();
// Маскировка webdriver-флага
$page->addInitScript("Object.defineProperty(navigator,'webdriver',{get:()=>undefined})");
$page->goto($url);
$html = $page->content();
$browser->close();
```

**Преимущества Playwright перед chrome-php:**
- Автоматическое ожидание (`waitForSelector`, `waitForLoadState`) — не нужен `sleep()`
- Встроенный `page.route()` для перехвата запросов и блокировки ненужных ресурсов (картинки, шрифты)
- `storageState` — сохранение сессии между запросами
- Cross-browser: Chromium, Firefox, WebKit
- Активно поддерживается (последний релиз Dec 2025)

**Репозиторий:** https://github.com/playwright-php/playwright

### Метод 2 — CSV-экспорт (новый, рекомендуемый для листинга) ⭐

**Использовать когда:** первичный сбор реестра закупок, построение карты/индекса.

**Принцип:** ЕИС предоставляет кнопку "Выгрузить результаты поиска" на странице поиска. Это POST-запрос, возвращающий CSV-файл. Лимит — до 500 записей на файл. При необходимости файлы объединяются.

**Ключевые преимущества:**
- Нет риска изменения CSS-селекторов — CSV со стабильными заголовками колонок
- Скорость: один CSV-запрос ≈ N HTTP-запросов к HTML-страницам
- Меньше нагрузки на IP — меньше риск бана
- Данные структурированы "из коробки"

**Эндпоинт (из анализа ЕИС):**

```
POST https://zakupki.gov.ru/epz/order/extendedsearch/download.html
```

Параметры формы идентичны поисковому запросу + `downloadFile=csv` (или `xls`). Формат файла: `ContractSearch(1-N)_дата.csv`, кодировка Windows-1251.

**Архитектурная реализация:**

```php
// app/Process/Crawling/Methods/CsvExportMethod.php

class CsvExportMethod implements CrawlingMethodInterface
{
    public function collect(CrawlerModel $crawler): Collection
    {
        $response = Http::withHeaders([
            'User-Agent' => $this->randomUserAgent(),
            'Referer' => 'https://zakupki.gov.ru/epz/order/extendedsearch/results.html',
        ])->post('https://zakupki.gov.ru/epz/order/extendedsearch/download.html', [
            ...$this->buildSearchParams($crawler),
            'downloadFile' => 'csv',
        ]);

        $csv = mb_convert_encoding($response->body(), 'UTF-8', 'Windows-1251');
        
        return $this->parseCsvToCollection($csv);
    }
}
```

**Важная оговорка:** CSV содержит поверхностные данные листинга. Детальные карточки (лоты, сметы, технические задания) всё равно требуют Метода 1 или 3. CSV — это инструмент для быстрого первичного индексирования, а не для глубокого извлечения.

### Метод 3 — SOAP API (резервный, будущее)

**Статус:** временно недоступен. FTP-интерфейс ЕИС закрыт с 1 января 2025. SOAP API (`int44.zakupki.gov.ru`) требует токен, получение которого для физлица нестабильно, для юрлица — требует ЭЦП.

**Эндпоинты для будущей интеграции:**
```
https://int44.zakupki.gov.ru/eis-integration/services/integration1
https://int223.zakupki.gov.ru/eis-integration/services/integration1
```

**Метод получения XML-документа закупки (когда API заработает):**

```xml
<soapenv:Body>
  <ns:getPurchaseNoticeInfo>
    <ns:regNumber>0111100023725000001</ns:regNumber>
  </ns:getPurchaseNoticeInfo>
</soapenv:Body>
```

**Архитектура под будущее переключение:** метод должен реализовывать тот же `CrawlingMethodInterface`, что и CSV и HTML. Переключение = изменение одного поля в БД.

---

## 3. Детальные страницы — JS или SSR?

**Вопрос правомерен.** Страницы листинга ЕИС — SSR. Но детальные карточки закупок (например, `common-info.html`, `documents.html`) содержат динамически подгружаемые вкладки. Тест:

1. Откройте DevTools → Network → отключите JS → обновите страницу детальной карточки
2. Если данные отсутствуют — нужен браузер. Если есть — достаточно HTTP-клиента.

**Практическая рекомендация:** использовать **Guzzle + Symfony DomCrawler** для `documents.html` как первую попытку. Если получено меньше N вложений — переключиться на Playwright. Это снизит использование браузера на ~60-70%.

```php
protected function parseAttachments(string $commonInfoUrl): array
{
    $docsUrl = str_replace('common-info.html', 'documents.html', $commonInfoUrl);
    
    // Попытка 1: чистый HTTP (быстро, без браузера)
    try {
        $html = Http::timeout(15)
            ->withHeaders(['User-Agent' => $this->randomUserAgent()])
            ->get($docsUrl)
            ->throw()
            ->body();
        
        $attachments = $this->extractAttachmentsFromHtml($html);
        if (count($attachments) > 0) {
            return $attachments;
        }
    } catch (\Exception $e) {
        // fallthrough
    }
    
    // Попытка 2: Playwright (медленно, но надёжно)
    return $this->parseAttachmentsWithBrowser($docsUrl);
}
```

---

## 4. Оптимизированная архитектура очередей

Главная проблема производительности — синхронное выполнение цепочки. Правильная модель:

```
┌──────────────────────────────────────────────────────────────┐
│  Queue: crawling-listing (высокий приоритет)                │
│  Job: CrawlListingPageJob                                    │
│  ├─ скачивает страницу/CSV (Метод 1 или 2)                  │
│  ├─ парсит N записей                                        │
│  ├─ сохраняет в БД (bulk upsert)                            │
│  └─ диспатчит N × ParseProcurementDetailsJob                │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼ (асинхронно, другая очередь)
┌──────────────────────────────────────────────────────────────┐
│  Queue: crawling-details (средний приоритет)                │
│  Job: ParseProcurementDetailsJob                            │
│  ├─ скачивает детальную страницу                            │
│  ├─ диспатчит ParseProcurementAttachmentsJob                │
│  └─ сохраняет лоты, НМЦК, заказчика                        │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼ (асинхронно, отдельная очередь)
┌──────────────────────────────────────────────────────────────┐
│  Queue: crawling-attachments (низкий приоритет)             │
│  Job: ParseProcurementAttachmentsJob                        │
│  ├─ случайная задержка 2-8 сек (вместо фиксированных 3 сек) │
│  ├─ скачивает documents.html                                │
│  └─ сохраняет вложения                                      │
└──────────────────────────────────────────────────────────────┘
```

**Конфигурация Horizon:**
```php
// config/horizon.php
'environments' => [
    'production' => [
        'crawling-listing' => [
            'connection' => 'redis',
            'queue' => ['crawling-listing'],
            'balance' => 'auto',
            'maxProcesses' => 3,   // параллельно скачиваем листинг
            'tries' => 3,
            'timeout' => 120,
        ],
        'crawling-details' => [
            'connection' => 'redis',
            'queue' => ['crawling-details'],
            'balance' => 'auto',
            'maxProcesses' => 10,  // детали параллельно
            'tries' => 3,
            'timeout' => 60,
        ],
        'crawling-attachments' => [
            'connection' => 'redis',
            'queue' => ['crawling-attachments'],
            'balance' => 'auto',
            'maxProcesses' => 5,   // меньше, чтобы не банили
            'tries' => 5,
            'timeout' => 90,
        ],
    ],
],
```

---

## 5. Rate Limiting и Anti-Ban стратегия

### 5.1 Throttling через Redis

```php
// app/Services/Crawling/RateLimiterService.php

class RateLimiterService
{
    // Максимум N запросов к ЕИС в скользящем окне
    public function throttle(string $domain, int $maxRpm = 20): void
    {
        $key = "crawler:ratelimit:{$domain}";
        $count = Redis::incr($key);
        
        if ($count === 1) {
            Redis::expire($key, 60); // 1 минута
        }
        
        if ($count > $maxRpm) {
            $ttl = Redis::ttl($key);
            throw new RateLimitExceededException("Rate limit exceeded. Retry in {$ttl}s");
        }
        
        // Случайная задержка внутри окна
        usleep(random_int(500_000, 2_000_000)); // 0.5 - 2 сек
    }
}
```

### 5.2 Ротация прокси через пул

```php
// Конфигурация источников прокси
// config/crawler.php

'proxy_pool' => [
    'type' => 'residential',         // residential > datacenter для ЕИС
    'providers' => [
        'brightdata' => env('BRIGHTDATA_DSN'),
        'oxylabs'   => env('OXYLABS_DSN'),
    ],
    'rotation_strategy' => 'random', // или 'round-robin'
    'blacklist_on_ban' => true,
    'blacklist_ttl' => 3600,         // 1 час
],
```

### 5.3 User-Agent ротация

```php
private array $userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_4) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64; rv:125.0) Gecko/20100101 Firefox/125.0',
    // ... актуальные UA из top-100 браузеров
];
```

### 5.4 Детектирование бана

```php
protected function isBlocked(string $html): bool
{
    return str_contains($html, 'Доступ ограничен')
        || str_contains($html, 'Access Denied')
        || str_contains($html, 'captcha')
        || mb_strlen($html) < 1000; // страница слишком короткая
}
```

---

## 6. Переключение методов — интерфейс и конфигурация

### 6.1 Интерфейс

```php
// app/Process/Crawling/Methods/CrawlingMethodInterface.php

interface CrawlingMethodInterface
{
    /**
     * Собирает первичные данные из источника.
     * Возвращает коллекцию CrawlingItemDto.
     */
    public function collectListings(CrawlerModel $crawler, int $page): Collection;

    /**
     * Собирает детальные данные по конкретной закупке.
     */
    public function collectDetails(string $procurementUrl): ProcurementDetailDto;

    /**
     * Проверяет доступность метода.
     */
    public function isAvailable(): bool;
}
```

### 6.2 Модель Crawler — новые поля

```php
// Новая миграция
Schema::table('plt_crawlers', function (Blueprint $table) {
    // Метод краулинга: 'html', 'csv', 'api'
    $table->enum('source_method', ['html', 'csv', 'api'])->default('html');
    
    // Fallback: если primary недоступен
    $table->enum('fallback_method', ['html', 'csv', 'api'])->nullable();
    
    // Метод для деталей может отличаться от листинга
    $table->enum('details_method', ['html', 'csv', 'api'])->default('html');
});
```

### 6.3 Фабрика методов

```php
// app/Process/Crawling/Methods/CrawlingMethodFactory.php

class CrawlingMethodFactory
{
    public function make(string $method): CrawlingMethodInterface
    {
        return match ($method) {
            'csv'  => app(CsvExportMethod::class),
            'api'  => app(SoapApiMethod::class),
            'html' => app(PlaywrightHtmlMethod::class),  // обновлённый метод
            default => throw new InvalidArgumentException("Unknown method: {$method}"),
        };
    }
    
    public function makeWithFallback(CrawlerModel $crawler): CrawlingMethodInterface
    {
        $primary = $this->make($crawler->source_method);
        
        if ($primary->isAvailable()) {
            return $primary;
        }
        
        if ($crawler->fallback_method) {
            Log::warning("Primary method {$crawler->source_method} unavailable, falling back to {$crawler->fallback_method}");
            return $this->make($crawler->fallback_method);
        }
        
        throw new CrawlingMethodUnavailableException($crawler->source_method);
    }
}
```

---

## 7. Переход с chrome-php на Playwright PHP

### 7.1 Установка

```bash
composer require playwright-php/playwright

# Установка браузеров (Chromium ~170MB)
vendor/bin/playwright-install --browsers

# На CI/production с зависимостями ОС
vendor/bin/playwright-install --with-deps
```

### 7.2 Адаптер-обёртка (совместимость с текущим кодом)

Чтобы не переписывать всю кодовую базу сразу, создаётся адаптер с тем же интерфейсом, что у текущего `BrowserService`:

```php
// app/Services/Browser/PlaywrightBrowserAdapter.php

class PlaywrightBrowserAdapter implements BrowserServiceInterface
{
    private ?Browser $browser = null;
    
    public function openPage(string $url, BrowserOptions $options): PageResult
    {
        $page = $this->getContext()->newPage();
        
        // Блокируем ненужные ресурсы (ускорение 30-50%)
        $page->route('**/*.{png,jpg,gif,svg,woff,woff2,ttf}', fn($route) => $route->abort());
        
        // Маскировка webdriver
        $page->addInitScript(
            "Object.defineProperty(navigator,'webdriver',{get:()=>undefined})"
        );
        
        $response = $page->goto($url, ['waitUntil' => 'domcontentloaded']);
        
        if ($response->status() === 429 || $response->status() === 403) {
            if ($options->rotate_proxy_on_fail) {
                $this->rotateProxy();
                return $this->openPage($url, $options);
            }
        }
        
        // Ждём конкретный элемент вместо sleep()
        if ($options->wait_for_selector) {
            $page->waitForSelector($options->wait_for_selector, ['timeout' => 10_000]);
        }
        
        $html = $page->content();
        $page->close();
        
        return new PageResult(html: $html, status: $response->status());
    }
}
```

### 7.3 Что меняется в EisCrawlingService

После замены адаптера убирается `usleep(3_000_000)` из `parseAttachments()`:

```diff
- // Ожидание для предотвращения блокировки (3 секунды)
- usleep(3_000_000);
+ // Playwright сам ждёт загрузку элементов через waitForSelector
```

И добавляется умное ожидание вместо сна:

```php
$page = $this->browser->openPage($docsUrl, new BrowserOptions(
    retries: 3,
    rotate_proxy_on_fail: true,
    wait_for_selector: '.attachment a[href*="download"]', // ждём конкретно вложения
));
```

---

## 8. Мониторинг и observability

### 8.1 Метрики для отслеживания

```php
// В каждом Job — после обработки
Metrics::increment('crawler.listings.processed', 1, [
    'source' => $crawler->source_method,
    'status' => 'success',
]);

Metrics::gauge('crawler.queue.depth', Queue::size('crawling-details'));

Metrics::timing('crawler.page.duration', $durationMs, [
    'method' => $crawler->source_method,
]);
```

### 8.2 Алерты

- Очередь `crawling-details` > 10,000 задач — замедлить скорость диспатча
- `crawler.page.duration` > 30 сек — детектор зависшего браузера
- Подряд 5 пустых страниц — вероятный бан IP; переключить прокси

---

## 9. Итоговый приоритет задач

| Приоритет | Задача | Метод | Эффект |
|-----------|--------|-------|--------|
| 🔴 **P0** | Вынести `parseAttachments()` в отдельный Job | PHP рефакторинг | Ускорение в 30-50x |
| 🔴 **P0** | Заменить `chrome-php` на `playwright-php/playwright` | PHP, замена адаптера | Надёжность +++ |
| 🟡 **P1** | Реализовать CSV-метод как альтернативу HTML для листинга | PHP новый класс | Снижение нагрузки на IP |
| 🟡 **P1** | Добавить `source_method` / `fallback_method` в `plt_crawlers` | Миграция + рефакторинг | Переключаемость |
| 🟢 **P2** | Добавить HTTP-fallback в `parseAttachments()` перед браузером | PHP | Скорость деталей |
| 🟢 **P2** | Redis rate-limiter на домен zakupki.gov.ru | PHP | Защита от IP-бана |
| ⚪ **P3** | SOAP API интеграция | PHP / будущее | Максимальная надёжность |

---

## 10. Полезные репозитории и ресурсы

### Playwright PHP
- **Основной пакет:** https://github.com/playwright-php/playwright (packagist: `playwright-php/playwright`)
- **Документация Playwright:** https://playwright.dev/docs/browsers
- **Device descriptors для PHP:** https://packagist.org/packages/playwright-php/devices

### Парсинг ЕИС — реальный опыт
- **VadimSpb/goszakupki** — подтверждение CSV-экспорта с ЕИС через кнопку "выгрузить результаты": https://github.com/VadimSpb/goszakupki
- **excelvba.ru — парсер zakupki.gov.ru:** https://excelvba.ru/programmes/Parser/samples/zakupki
- **Habr: ЕИС как найти и выгрузить данные (CSV):** https://habr.com/ru/articles/806877/ — подтверждение: CSV-выгрузка до 500 строк, файл `ContractSearch(1-N)_дата.csv`

### CSV-экспорт ЕИС
- Эндпоинт поиска: https://zakupki.gov.ru/epz/order/extendedsearch/results.html
- Расширенный поиск: https://zakupki.gov.ru/epz/order/extendedsearch/search.html
- Кнопка "Выгрузить результаты поиска" — POST-запрос с параметром `downloadFile=csv`
- Лимит: 500 записей на файл; кодировка Windows-1251

### Архитектурные паттерны
- **Crawlee for Python** (аналог идей): https://github.com/apify/crawlee-python
- **Laravel Horizon** (очереди): https://laravel.com/docs/horizon
- **Symfony DomCrawler** (SSR-страницы): https://symfony.com/doc/current/components/dom_crawler.html
- **Playwright headless vs headed guide (2026):** https://testdino.com/blog/headless-vs-headed

### Прокси
- **BrightData Residential Proxies** — лучший вариант для ЕИС (российские IP)
- **Oxylabs** — альтернатива
- Для ЕИС residential proxy предпочтительнее datacenter — российские IP меньше блокируются

---

## 11. Авторская оценка плана (что нравится / что не нравится)

### Сильные стороны архитектуры

✅ **Трёхметодная схема реально нужна.** CSV-метод — это не "красивая идея", а прагматичное решение: один HTTP-запрос вместо 50 страниц по 50 записей. Для первичного индексирования это в разы эффективнее.

✅ **Замена chrome-php на Playwright PHP — правильное решение.** Playwright нативно решает проблему fingerprinting, имеет умное ожидание элементов (без `sleep`), активно поддерживается и работает в тех же PHP-процессах.

✅ **Сохранение PHP как хоста-оркестратора** — верное решение. Python не нужен. Playwright-PHP запускает NodeJS-сервер прозрачно под капотом.

### Риски и ограничения

⚠️ **CSV-метод нестабилен по API.** ЕИС нередко меняет форму параметров. Структуру POST-запроса на CSV-скачивание нужно будет восстанавливать через DevTools при каждом редизайне сайта (хотя реже, чем CSS-селекторы).

⚠️ **CSV содержит только листинг, не детали.** Лоты, ТЗ, сметы и вложения — только через Метод 1 (HTML/браузер) или Метод 3 (SOAP). Это не недостаток плана, а архитектурная реальность ЕИС.

⚠️ **SOAP API нестабилен для физлиц** — это подтверждается и внешними источниками. Метод 3 следует проектировать сейчас, но не блокировать на нём текущую разработку.

⚠️ **playwright-php/playwright** — относительно новый пакет (релиз Dec 2025). Перед production использованием рекомендуется тесты на всех основных сценариях ЕИС. Альтернатива: `victor-teles/playwright-php` (более старый bridge).

