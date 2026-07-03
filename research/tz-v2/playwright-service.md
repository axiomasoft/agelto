# ТЕМА 4: Node.js-сервис на Playwright как browser-backend для PHP-оркестратора

Дата исследования: 2026-07-03. Все факты — из веб-источников, ссылки указаны. Не подтверждённое явно помечено.

## 1. Готовые решения vs свой сервис — сравнение

| Решение | Лицензия | Self-host | Статус в 2026 | Стоимость self-host |
|---|---|---|---|---|
| **Browserless v2** (browserless/browserless) | **SSPL-1.0 OR Browserless Commercial License** (с v2.0, конец 2023, GPLv3→SSPL) | Да, но коммерческое закрытое использование (в т.ч. внутренний CI для проприетарного продукта) по их же формулировке требует платной лицензии | Активно развивается, актуальная ветка | «Бесплатно» только для non-commercial/open-source по SSPL; для закрытого коммерческого SaaS/CI — Enterprise-лицензия по индивидуальной цене |
| **Browserless Chrome CE** (старый `browserless/chrome`, 1.x) | GPLv3, legacy | Да | Не архивирован, но в режиме поддержки (security/dependency fixes), новые фичи не идут — команда сама называет его legacy при миграции на 2.0 | Бесплатно (GPLv3), но без развития |
| **Steel.dev** (steel-browser) | **Apache-2.0** (ядро браузерного runtime — open source) | Да, Docker, без лицензионных отчислений | Активно развивается, позиционируется как «AI-first browser API for agents» | Бесплатно (только инфраструктурные расходы); облако — тарифы по browser-hours ($29–499/мес) |
| **Свой express/fastify-сервис на голом Playwright** | Playwright сам — Apache-2.0/MIT (open source, MS) | Да | — | Только время разработки + инфраструктура |

Источники: [browserless.io/pricing](https://www.browserless.io/pricing), [browserless.io/blog/browserless-2-0](https://www.browserless.io/blog/browserless-2-0), [github.com/browserless/browserless/issues/3850](https://github.com/browserless/browserless/issues/3850), [apis.io/plans/steel-dev](https://apis.io/plans/steel-dev/steel-dev-plans-pricing/).

### Лицензионный риск Browserless — детали

- v2 задекларирован как `SPDX-License-Identifier: SSPL-1.0 OR Browserless Commercial License`.
- Формально SSPL (как у MongoDB) разрешает внутреннее коммерческое использование бесплатно — платить нужно, только если вы *предоставляете саму программу как услугу третьим лицам* (SaaS-хостинг Browserless-подобного сервиса), и тогда обязаны открыть весь стек.
- НО в README/LICENSE Browserless прямо пишет: «to build commercial sites, applications, or in a continuous-integration system that's closed-source you need a commercial license» — это шире, чем требует сама SSPL, и в GitHub issue community прямо называет формулировку «muddy» и спорной.
- Практический вывод для нашего кейса (закрытый коммерческий продукт для сбора госзакупок): читать намерение проекта максимально консервативно — любое нетривиальное закрытое коммерческое использование = нужна платная лицензия. Self-host Enterprise — цена по договорённости, не публикуется.

### Steel.dev — выгоднее по лицензии

Ядро (steel-browser) — Apache-2.0, self-host бесплатен без ограничений по коммерческому использованию. Облачные тарифы метрятся в browser-hours (не по 30-секундным «unit» как у Browserless), что даёт более предсказуемый бюджет. Для закрытого коммерческого продукта это структурно безопаснее лицензионно, чем Browserless.

**Не удалось подтвердить**: точное содержание Enterprise-прайсинга Browserless (цена не публикуется, только «custom»).

## 2. Архитектура своего сервиса

### Модель пула: браузеры-процессы + context-per-request

Официальная модель Playwright: `BrowserContext` — дешёвая, быстро создаваемая изоляция (как incognito-профиль), полноценный запуск браузера — тяжёлая единица. Рекомендуемый паттерн для прод-сервиса:

- **Browser pool**: небольшое фиксированное число запущенных Chromium-процессов (стартовая точка: 2–4 браузера на средней VM, дальше — бенчмарк).
- **Context-per-request**: каждая задача получает свежий `browser.newContext()` + `page`, оба закрываются в `finally`.
- **Очередь с backpressure**: ограниченная очередь вместо неограниченного спавна — иначе HTTP-сервер сам становится нерегулируемой очередью с ростом памяти.

Ориентировочные лимиты: 4–8 активных контекстов на браузер для средних страниц (меньше для тяжёлых JS-сайтов); при задержках/крашах сначала снижать contexts-per-browser, потом увеличивать число браузеров.

Готовые библиотеки вместо самописного пула:
- **`@crawlee/browser-pool`** (Apify) — `BrowserPool` + `PlaywrightPlugin`, incognito pages, hooks, метрики — готовое решение уровня "production-ready".
- **`generic-pool`/`node-pool`** — низкоуровневый resource pool, оборачивающий фабрику create/destroy для контекста/браузера вручную.

Источники: [medium.com/@devcriston/building-a-robust-browser-pool...](https://medium.com/@devcriston/building-a-robust-browser-pool-for-web-automation-with-playwright-2c750eb0a8e7), [playwright.dev/docs/docker](https://playwright.dev/docs/docker), [github.com/apify/browser-pool](https://github.com/apify/browser-pool), [github.com/coopernurse/node-pool](https://github.com/coopernurse/node-pool).

### Crash-изоляция и авторестарт

- Каждое закрытие контекста может означать: контекст закрыт нормально, браузер закрыт, или браузер **упал**. Пул должен считать смерть браузера инвалидацией всех его контекстов и failed/retry для затронутых задач.
- Health-probe перед возвратом слота в пул (например, создать и закрыть тестовый контекст).
- **Generation ID** на слот — чтобы поздний callback от упавшего браузера не «освободил» уже перезапущенный слот.
- **Soft recycle**: принудительно перезапускать браузер после N задач или по порогу памяти — даже без видимого краша (Playwright это не предписывает, но паттерн широко применяется, т.к. процесс браузера деградирует незаметно).
- Разделять «ожидаемый отказ сайта» (429/503 target) от «отказа пула» — не должны триггерить рестарт браузера.

### Docker: официальный образ и память

- Официальный образ `mcr.microsoft.com/playwright` **не задаёт** `shm_size` или memory limits сам — это ответственность пользователя.
- Официальная рекомендация Playwright-доков: предпочитать `--ipc=host` вместо ручного `--shm-size`, когда это возможно (не подходит для мультитенантной инфраструктуры со строгой изоляцией).
- Если `--ipc=host` недоступен: `shm_size: 512m` для лёгких CI-сценариев, `1g`+ для параллельных воркеров/тяжёлых страниц.
- Дефолт Docker для `/dev/shm` — **64 MB**, этого системно не хватает Chromium → крэши/зависания.
- Exit code **137** = OOM-killer съел процесс. Типичные fix'ы: увеличить `shm_size`, поднять memory limit контейнера (ориентир ~1.5 GB на конкурентный browser-worker), флаги `--disable-dev-shm-usage --disable-gpu --disable-software-rasterizer` как fallback (но это переносит нагрузку с shm на обычную RAM, поэтому его называют fallback, а не основным решением), не наращивать `workers` бездумно — 1–2 воркера на 2 GB памяти как реалистичный ориентир.

Источники: [playwright.dev/docs/docker](https://playwright.dev/docs/docker), [deadends.dev/docker/exit-code-137](https://deadends.dev/docker/exit-code-137/).

## 3. Прокси: per-context, ротация, обработка 403/429

- Playwright поддерживает proxy **и на уровне browser launch, и индивидуально на `browser.newContext(...)`** — это официальный, first-class паттерн для ротации по задачам/тенантам без пересоздания браузера.
- Поля прокси-объекта: `server`, `username`, `password`. Это именно **proxy auth**, отдельно от `http_credentials` (тот — для сайтового HTTP-basic-auth, не для прокси!) — частая ошибка.
- Важный нюанс от вендоров прокси: SOCKS5 с username/password может не работать через браузерные движки — предпочтителен HTTP(S)-прокси с логином/паролем.
- Рекомендуемая модель ротации: **retry-unit = context, а не запрос**. Заблокированный контекст уже несёт «отравленные» cookie/fingerprint — ретраить в том же контексте почти гарантированно приводит к повторному отказу.
- Обработка кодов:
  - **403** → жёсткий блок, немедленная ротация прокси + новый контекст.
  - **429** → уважать заголовок `Retry-After`, «остудить» именно этот прокси, ретрай в новом контексте с backoff+jitter.
  - Сетевые таймауты/обрывы → это скорее нестабильность прокси, не блокировка таргета — короче cooldown.
- Практический паттерн детекции: слушать `page.on('response')`, т.к. часто HTML-shell отдаётся 200, а реально блокируется конкретный XHR/API-запрос внутри страницы.
- Разделять health прокси и health таргета — иначе один "плохой" сайт отравляет весь пул прокси.

Источники: [playwright.dev/python/docs/network](https://playwright.dev/python/docs/network), [pixeljets.com/blog/proxy-in-playwright](https://pixeljets.com/blog/proxy-in-playwright/), [coldproxy.com/docs/playwright](https://coldproxy.com/docs/playwright/).

## 4. Anti-detection в 2026

Ключевой вывод: **классический стек playwright-extra + puppeteer-extra-plugin-stealth фактически устарел** как серьёзное средство обхода детекта, хотя npm-пакеты живы и продолжают публиковаться/использоваться в примерах (например, Crawlee-документация).

| Инструмент | Статус на 2026 | Что решает | Оценка |
|---|---|---|---|
| `playwright-extra` + `puppeteer-extra-plugin-stealth` | Пакеты публикуются, но по факту «legacy» | Старые JS-фингерпринт сигналы (navigator.webdriver и т.п.) | Недостаточно против Cloudflare/DataDome/Akamai — не закрывает CDP-уровень (`Runtime.enable` leak) |
| **Patchright** (`Kaliiiiiiiiii-Vinyzu/patchright`) | Активно поддерживается, релиз v1.56.1 — Oct 2025, Apache-2.0, 40+ контрибьюторов, автосборка на новые версии Playwright | Патчит CDP-уровень: не вызывает `Runtime.enable`, использует изолированные execution contexts, убирает `--enable-automation`, отключает Console API | Одна из немногих реально рабочих опций против Cloudflare/DataDome в 2026 по независимым обзорам. Только Chromium (Firefox/WebKit не поддерживает) |
| **rebrowser-patches** (rebrowser-playwright) | Активность зафиксирована по май 2025 (последняя точная дата, которую удалось найти) | Аналогичный фикс `Runtime.enable` leak через биндинги/изолированные контексты | Считается сопоставимой по эффективности альтернативой Patchright |
| **Camoufox** | Формально жив (v146.0.1-beta, январь 2026), но сам сайт помечает свежие релизы как **"highly experimental, not for production"**; форумный пост автора (нач. 2026) говорит о периоде простоя из-за проблем со здоровьем автора и **планах деприкейта в пользу нового Chromium-based движка** | Firefox-based анти-детект (патч на уровне C++ вместо JS-оверрайдов), консистентные фингерпринты через BrowserForge | **Риск для прода высокий** — сам проект называет себя нестабильным, есть сигнал о будущей деприкации |
| **noDriver/nodriver** | Упоминается как жизнеспособная альтернатива | CDP-free автоматизация (вообще не использует Chrome DevTools Protocol) | Теряет часть Playwright API-эргономики, но по конструкции недетектируем через CDP-сигнатуры |

**Детектирующая сторона в 2026**: комбинация `Runtime.enable`-leak, `sourceURL`-leak (Puppeteer/Playwright тегируют инжектируемые скрипты), `__pwInitScripts` (специфичный Playwright-глобал), аномальный viewport (Playwright default 1280×720 статистически ненормален), `navigator.webdriver`, "Chrome for Testing" UA, TLS/JA3-JA4 фингерпринт, WebGL/SwiftShader software-рендерер в headless, тайминговая когерентность действий. Открытый референс-детектор: `rebrowser-bot-detector` на GitHub — можно использовать как self-test/CI-gate для своего стека.

**Рекомендация для ТЗ**: закладывать Patchright как основной драйвер вместо ванильного Playwright для целей обхода детекта на ЕИС/маркетплейсах, с rebrowser-patches как запасной вариант. Camoufox — не использовать в проде, только как экспериментальный fallback при явной необходимости Firefox-движка. Держать этот слой за внутренним "browser driver"-абстракцией, чтобы можно было безболезненно переключать реализацию по мере эволюции детект-стека (арка гонки вооружений идёт постоянно).

Источники: [github.com/Kaliiiiiiiiii-Vinyzu/patchright](https://github.com/Kaliiiiiiiiii-Vinyzu/patchright), [blog.crawlex.net/blog/detecting-cdp-runtime-enable](https://blog.crawlex.net/blog/detecting-cdp-runtime-enable/), [camoufox.com](https://camoufox.com/), [discuss.privacyguides.net/t/donut-browser-fingerprint-chromium/34157/8](https://discuss.privacyguides.net/t/donut-browser-fingerprint-chromium/34157/8), [github.com/rebrowser/rebrowser-bot-detector](https://github.com/rebrowser/rebrowser-bot-detector), [jibaoproxy.com/blog/headless-browser-detection-2026](https://www.jibaoproxy.com/blog/headless-browser-detection-2026.html).

## 5. Контракт API для PHP

**Единого «CDP-over-HTTP» стандарта не существует.** Browserless — не транспорт для сырых CDP-сообщений через HTTP, а набор opinionated REST-эндпоинтов, каждый из которых внутри дёргает CDP и отдаёт результат:

- `/content` — POST, `{url, gotoOptions, rejectResourceTypes, ...}` → рендеренный HTML.
- `/function` — POST, `{code: "module.exports = async ({page, context}) => {...}", context: {...}}` — произвольный Puppeteer-подобный скрипт, ответ = сериализованный return.
- `/download` — POST, тот же паттерн code+context, но сервис создаёт чистую страницу + папку загрузок, ждёт события скачивания Chrome, стримит файл в ответ.
- Все эндпоинты **stateless**: один запрос = одна сессия браузера, создаётся и уничтожается внутри запроса. Многошаговые сценарии реализуются только внутри одного `/function`-скрипта.
- Живой CDP (WebSocket) — отдельно, через `wss://...?token=`, для Playwright/Puppeteer/BrowserQL с session reuse.

**Опасный паттерн, которого стоит избегать** (явное предостережение из исследования): не давать PHP-стороне слать произвольный JS-код на исполнение в Node-сервисе, если это не полностью доверенное внутреннее окружение — фактически RCE-поверхность. Вместо этого — **типизированная командная схема**.

### Рекомендуемый контракт для своего сервиса (по паттерну "control-plane PHP + execution-plane Node")

Асинхронная job-модель вместо синхронного долгого запроса:

```
POST   /v1/jobs          — { taskType, url, options, proxyProfile, timeoutMs, callbackUrl, idempotencyKey }
GET    /v1/jobs/{id}     — { status: queued|running|succeeded|failed|canceled, ... }
POST   /v1/jobs/{id}/cancel
GET    /v1/jobs/{id}/artifacts   — метаданные html/screenshot/pdf/downloaded-file/trace
GET    /v1/health
GET    /v1/capacity      — текущая занятость пула, доступная конкурентность
```

Плюс узкие синхронные эндпоинты для простых разовых операций (`/screenshot`, `/pdf`, `/render-html`), где ожидание оправдано короткое.

`taskType` — command-profile паттерн: PHP шлёт `"download_eis_attachment"` / `"render_lot_page"`, а не сырые Playwright-шаги; Node маппит на версионированный обработчик. Это даёт версионируемость и не завязывает PHP на детали селекторов/навигации.

Операции, которые реально нужны под наш пайплайн (crawling ЕИС + document prepare):
- `renderPage` (получить HTML после JS-рендеринга/пагинации) → аналог `/content`.
- `downloadFile` (клик по ссылке скачивания вложения, дождаться download-event, вернуть файл) → аналог `/download`.
- `evaluate` / `extract` — выполнение заранее определённого (не произвольного!) набора извлекающих скриптов.
- `fillForm` + `submit` — для форм поиска/фильтров ЕИС.

Источники: [docs.browserless.io/open-api](https://docs.browserless.io/open-api), [docs.browserless.io/rest-apis/intro](https://docs.browserless.io/rest-apis/intro), [docs.browserless.io/examples/playwright-connection](https://docs.browserless.io/examples/playwright-connection).

## 6. Скачивание файлов: download events → передача в PHP

Playwright: `page.waitForEvent('download')` до триггера скачивания → `Download` объект → `download.saveAs(path)` (безопасно вызывать даже во время скачивания, дожидается завершения) или `download.createReadStream()` для потоковой обработки. Дефолтно файлы временные и живут в связке с lifecycle контекста — при закрытии контекста удаляются, если не сохранены явно.

**Решение зависит от паттерна использования:**

| Сценарий | Рекомендация |
|---|---|
| Async job (наш случай — crawling/document prepare через очередь) | **Shared volume**: Node сохраняет в смонтированную директорию через `saveAs()`, пишет метаданные о готовности в БД/очередь; PHP читает файл по стабильному пути когда получит уведомление |
| Синхронный "нажал — жду — получил файл" | Стриминг: Node дожидается скачивания, затем API-слой стримит `createReadStream()` в HTTP-ответ (Express: `res.attachment(name); stream.pipe(res)`; Fastify: `reply.header(...).send(stream)`) |

**Для нашей архитектуры (Horizon-jobs, browser automation только в worker-контейнере)** — shared volume — правильный выбор: Node browser-сервис пишет вложения на volume, доступный PHP worker-контейнеру, job в очереди подхватывает файл по пути и передаёт дальше в document-prepare пайплайн. Это развязывает таймаут HTTP-запроса от времени скачивания и переживает рестарт браузерного контекста.

Источники: [runebook.dev/.../download-save-as](https://runebook.dev/en/docs/playwright/api/class-download/download-save-as), [nodejs.org/api/stream.html](https://nodejs.org/api/stream.html).

---

## Рекомендации для ТЗ

1. **Не использовать Browserless v2 в закрытом коммерческом self-host** — их SSPL+собственная формулировка лицензии трактует практически любой закрытый коммерческий кейс (включая внутренний CI для проприетарного продукта) как требующий платной лицензии. Юридический риск неоправдан против бесплатных альтернатив.

2. **Строить свой мини-сервис на Node.js + Playwright**, а не брать готовый продукт целиком — потому что: (а) нужен полный контроль над anti-detection слоем (Patchright/rebrowser вместо стандартного Playwright), который сторонние сервисы не предоставляют «из коробки» без доп. оплаты/ограничений; (б) нужна тесная интеграция с нашей моделью прокси-ротации ЕИС и job-очередью Horizon; (в) Steel.dev рассматривать как запасной вариант (Apache-2.0, дешевле лицензионно Browserless), если ресурсов на свою разработку не хватит — но он тоже AI-agent-ориентирован, не специализирован под наш domain-flow.

3. **Архитектура своего сервиса**: control-plane (PHP/Laravel, Horizon jobs) + execution-plane (Node/Fastify, Playwright). Контракт — асинхронный job-based REST API (`POST /v1/jobs`, `GET /v1/jobs/{id}`, `GET /v1/jobs/{id}/artifacts`) с типизированным `taskType` (никакого произвольного JS от PHP — RCE-риск). Операции: `renderPage`, `downloadFile`, `fillForm+submit`, ограниченный набор `extract`-хендлеров.

4. **Browser pool**: браузеры как долгоживущие worker-слоты (2–4 на хост, бенчмарк на реальных страницах ЕИС), `newContext()` на каждую задачу, обязательный `finally`-close, health-probe перед возвратом в пул, soft-recycle браузера по счётчику задач/памяти, generation-ID против гонок при рестарте. За основу — либо `@crawlee/browser-pool`, либо тонкая обёртка над `generic-pool`.

5. **Docker**: базовый образ `mcr.microsoft.com/playwright`, запуск с `--ipc=host` если инфраструктура позволяет (иначе `shm_size: 1g`+), memory limit ~1.5–2 GB на конкурентный browser-worker, флаги `--disable-gpu --disable-software-rasterizer` как доп. защита. Мониторить exit code 137 как прямой сигнал нехватки shm/памяти.

6. **Anti-detection**: заложить Patchright как драйвер по умолчанию (Apache-2.0, активно поддерживается, закрывает `Runtime.enable` CDP-leak) с возможностью переключения на rebrowser-patches. Явно НЕ полагаться на playwright-extra+stealth и НЕ закладывать Camoufox в прод (нестабилен, риск деприкации). Держать этот выбор за внутренней abstraction-прослойкой ("browser driver"), т.к. детект-гонка непрерывна и стек придётся менять.

7. **Прокси**: proxy per-context через нативные поля Playwright (`server/username/password`), не через `http_credentials`. Retry unit = context (не запрос). Пул прокси с cooldown/backoff по 403 (жёсткий, долгий cooldown) и 429 (уважать `Retry-After`), раздельный health для прокси и для таргета.

8. **Файлы**: shared volume между Node browser-сервисом и PHP worker-контейнером (не стриминг через HTTP-ответ) — соответствует уже принятой в проекте модели «browser automation только в worker-контейнере horizon» и переживает произвольные таймауты скачивания крупных смет.

**Не удалось подтвердить проверкой**: точная цена Browserless Enterprise self-host (публично не раскрывается); точная дата последней активности rebrowser-patches (найдена активность до мая 2025, актуальность на июль 2026 не подтверждена отдельно — рекомендуется проверить репозиторий перед выбором).